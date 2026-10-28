<?php

/**
 * @file    Helpers/NodeBatch.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2026 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb\Helpers;

use Depage\XmlDb\Exceptions\XmlDbException;

/**
 * @internal buffering the node inserts of a save; not part of the public
 * api of this library
 *
 * Collects the rows of a tree that are written together and writes them
 * with as few multi row inserts as possible instead of one insert per
 * node.
 *
 * Nodes that carry a preferred database id are inserted with that id. The
 * ids of the nodes that got one from the auto increment can not be
 * deduced from the insert (auto increment does not hand out consecutive
 * ids in a multi row insert), they are read back over the unique sibling
 * index and returned to the caller, which needs them as id_parent for the
 * next tree level and as db:id attribute for the saved dom.
 */
class NodeBatch
{
    /**
     * number of nodes written with a single insert statement
     */
    public const DEFAULT_SIZE = 200;

    // {{{ variables
    protected $pdo;

    /**
     * rows of the batch, keyed by the key the caller passed to add()
     */
    protected $entries = [];
    // }}}

    // {{{ __construct
    /**
     * @param $pdo database connection to write the batch with
     * @param $table (string) name of the xmltree table; a table name can
     *        not be bound as a parameter, so it is checked to be a plain
     *        identifier
     * @param $doc_id (int) document the nodes belong to
     * @param $size (int) number of nodes written with one statement
     */
    public function __construct(
        $pdo,
        protected string $table,
        protected int $doc_id,
        protected int $size = self::DEFAULT_SIZE
    ) {
        $this->pdo = $pdo;

        $this->checkIdentifier($table);
    }
    // }}}
    // {{{ checkIdentifier
    /**
     * the table name is the one part of the statements this batch writes
     * that can not be a bound parameter
     */
    protected function checkIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new XmlDbException("Can not use \"$name\" as a table name.");
        }

        return $name;
    }
    // }}}

    // {{{ isEmpty
    public function isEmpty(): bool
    {
        return count($this->entries) == 0;
    }
    // }}}
    // {{{ isFull
    /**
     * whether the batch holds as many nodes as a single statement writes;
     * a caller that needs the ids of a batch before it writes the next
     * ones (the levels of a tree) writes whenever this is true
     */
    public function isFull(): bool
    {
        return count($this->entries) >= $this->size;
    }
    // }}}

    // {{{ add
    /**
     * collects one node of the batch
     *
     * @param $key (int) number of the node in the list of the caller, the
     *        result of write() is keyed by it
     * @param $id (int|null) preferred database id, null to take the one
     *        the auto increment hands out
     * @param $parent_id (int) database id of the parent node
     * @param $pos (int) position in the child list of the parent node
     * @param $name (string|null) node name as it is stored
     * @param $value (string) node value as it is stored
     * @param $type (string) node type as it is stored
     */
    public function add($key, $id, $parent_id, $pos, $name, $value, $type): void
    {
        $this->entries[$key] = [
            'id' => $id,
            'parent_id' => $parent_id,
            'pos' => $pos,
            'name' => $name,
            'value' => $value,
            'type' => $type,
        ];
    }
    // }}}

    // {{{ write
    /**
     * inserts the collected nodes with one statement and empties the
     * batch
     *
     * @returns (array) database ids of the written nodes, keyed by the keys
     *          passed to add()
     */
    public function write(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $entries = $this->entries;
        $this->entries = [];

        $this->insert($entries);

        $ids = $this->readAutoIncrementIds($entries);

        foreach ($entries as $key => $entry) {
            if (!is_null($entry['id'])) {
                $ids[$key] = $entry['id'];
            }
        }
        ksort($ids);

        return $ids;
    }
    // }}}

    // {{{ insert
    /**
     * writes the rows of one batch; nodes that carry an id are inserted
     * with it, a node whose id is already in use is not overwritten but
     * resolves to the row that holds the id (LAST_INSERT_ID(id))
     */
    protected function insert(array $entries): void
    {
        $values = [];
        $params = [];

        foreach ($entries as $entry) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $params,
                $entry['id'],
                $entry['parent_id'],
                $this->doc_id,
                $entry['pos'],
                $entry['name'],
                $entry['value'],
                $entry['type']
            );
        }

        $query = $this->pdo->prepare(
            "INSERT {$this->table}
                (id, id_parent, id_doc, pos, name, value, type)
            VALUES
                " . implode(", ", $values) . "
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $query->execute($params);
    }
    // }}}
    // {{{ readAutoIncrementIds
    /**
     * finds the ids the auto increment handed out for the nodes of the
     * batch that did not carry one; the unique sibling index
     * (id_doc, id_parent, pos) identifies them again
     *
     * @attention this assumes that the parents of a batch have no children
     *          beside the ones of the batch, which holds for a save: the
     *          tree of a document is either written after it was cleared,
     *          or new subtrees are appended below their parent before the
     *          child lists are reordered
     */
    protected function readAutoIncrementIds(array $entries): array
    {
        $wanted = [];
        $parents = [];
        $positions = [];

        foreach ($entries as $key => $entry) {
            if (!is_null($entry['id'])) {
                continue;
            }

            $wanted[$entry['parent_id'] . ':' . $entry['pos']] = $key;
            $parents[] = (int) $entry['parent_id'];
            $positions[] = (int) $entry['pos'];
        }

        if (count($wanted) == 0) {
            return [];
        }

        $parents = array_values(array_unique($parents));
        $positions = array_values(array_unique($positions));

        $query = $this->pdo->prepare(
            "SELECT id, id_parent, pos
            FROM {$this->table}
            WHERE id_doc = ?
                AND id_parent IN (" . implode(", ", array_fill(0, count($parents), '?')) . ")
                AND pos IN (" . implode(", ", array_fill(0, count($positions), '?')) . ")"
        );
        $query->execute(array_merge(
            [$this->doc_id],
            $parents,
            $positions
        ));
        $rows = $query->fetchAll(\PDO::FETCH_OBJ);
        $query->closeCursor();

        $ids = [];
        foreach ($rows as $row) {
            $sibling = $row->id_parent . ':' . $row->pos;

            if (isset($wanted[$sibling])) {
                $ids[$wanted[$sibling]] = (int) $row->id;
                unset($wanted[$sibling]);
            }
        }

        if (count($wanted) > 0) {
            throw new XmlDbException("Inserted node could not be found again after batch insert.");
        }

        return $ids;
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
