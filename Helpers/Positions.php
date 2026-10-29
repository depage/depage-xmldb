<?php

/**
 * @file    Helpers/Positions.php
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
 * @internal management of the sparse sibling positions of a document;
 * not part of the public api of this library
 *
 * The children of a node are ordered by their pos value, which has gaps
 * (POS_GAP) between the siblings; inserting a node between two neighbours
 * takes the midpoint of their positions, so no other rows have to be
 * renumbered. A child list is rebalanced once the space between two
 * neighbours drops below POS_MIN_GAP.
 *
 * The methods work inside whatever transaction the caller opened.
 */
class Positions
{
    /**
     * distance between the sparse positions of sibling nodes
     */
    public const POS_GAP = 65536;

    /**
     * the child list is rebalanced once the space between two neighbours
     * drops below this gap
     */
    public const POS_MIN_GAP = 16;

    /**
     * adding this offset to all positions of a child list (in descending
     * order, before assigning the final positions) moves them out of the
     * way of the unique sibling index; it is big enough to keep the
     * scratch positions clear of the final positions that are written by
     * rebalance()
     */
    public const SCRATCH_OFFSET = 1125899906842624;

    // {{{ variables
    protected $pdo;
    // }}}

    // {{{ __construct
    /**
     * @param $pdo database connection to read and write the positions with
     * @param $table (string) name of the xmltree table
     * @param $doc_id (int) document the nodes belong to
     * @param $batch_size (int) number of rows rebalance() writes with one
     *        statement
     */
    public function __construct(
        $pdo,
        protected string $table,
        protected $doc_id,
        protected int $batch_size = CaseUpdate::DEFAULT_SIZE
    ) {
        $this->pdo = $pdo;
    }
    // }}}

    // {{{ siblings
    /**
     * gets id and pos of all children of a parent node in document order
     *
     * @param $parent_id (int) db-id of parent node
     *
     * @return (array) list of objects with id and pos
     */
    public function siblings($parent_id): array
    {
        $query = $this->pdo->prepare(
            "SELECT xml.id, xml.pos
            FROM {$this->table} AS xml
            WHERE xml.id_parent = :parent_id AND xml.id_doc = :doc_id
            ORDER BY xml.pos ASC, xml.id ASC",
        );
        $query->execute([
            'parent_id' => $parent_id,
            'doc_id' => $this->doc_id,
        ]);

        return $query->fetchAll(\PDO::FETCH_OBJ);
    }
    // }}}

    // {{{ indexById
    /**
     * gets the index of a node in the child list of its parent node
     * (number of siblings before it), the "dense" position, independent
     * of the sparse pos values
     *
     * @param $id (int) node db-id
     *
     * @return (int) index in parents childlist, false if the node doesn't
     *         exist
     */
    public function indexById($id)
    {
        $query = $this->pdo->prepare(
            "SELECT xml.id_parent, xml.pos
            FROM {$this->table} AS xml
            WHERE xml.id = :id AND xml.id_doc = :doc_id
            LIMIT 1",
        );
        $query->execute([
            'id' => $id,
            'doc_id' => $this->doc_id,
        ]);

        if (!($node = $query->fetchObject())) {
            return false;
        }

        $query = $this->pdo->prepare(
            "SELECT COUNT(*) AS sibling_index
            FROM {$this->table} AS xml
            WHERE xml.id_parent = :parent_id AND xml.id_doc = :doc_id AND xml.pos < :pos",
        );
        $query->execute([
            'parent_id' => $node->id_parent,
            'pos' => $node->pos,
            'doc_id' => $this->doc_id,
        ]);
        $result = $query->fetchObject();

        return (int) $result->sibling_index;
    }
    // }}}

    // {{{ appendIndex
    /**
     * gets the index a child would get when appended to the child list,
     * i.e. the number of current children
     *
     * @param $parent_id (int) db-id of parent node
     */
    public function appendIndex($parent_id)
    {
        $query = $this->pdo->prepare(
            "SELECT COUNT(*) AS pos
            FROM {$this->table} AS xml
            WHERE xml.id_parent = :target_id AND id_doc = :doc_id",
        );
        $query->execute([
            'target_id' => $parent_id,
            'doc_id' => $this->doc_id,
        ]);

        if ($object = $query->fetchObject()) {
            $result = $object->pos;
        } else {
            $result = null;
        }

        return $result;
    }
    // }}}

    // {{{ positionForIndex
    /**
     * calculates the sparse pos value for a node to be inserted at the
     * given index of a parent node's child list; the result lies between
     * the positions of the neighbouring siblings, so no other rows have
     * to be renumbered. if the space between the neighbours is exhausted,
     * the child list is rebalanced and the position recalculated.
     *
     * @param $parent_id (int) db-id of parent node
     * @param $index (int) insertion index in the child list
     * @param $exclude_id (int) db-id of a child that does not count into
     *        the index (a node that is moved inside its own parent)
     *
     * @return (int) pos value for the node to insert
     */
    public function positionForIndex($parent_id, $index, $exclude_id = null)
    {
        $siblings = $this->siblings($parent_id);

        if (!is_null($exclude_id)) {
            foreach ($siblings as $i => $sibling) {
                if ($sibling->id == $exclude_id) {
                    unset($siblings[$i]);
                    $siblings = array_values($siblings);
                    break;
                }
            }
        }

        $num_siblings = count($siblings);
        $index = min(max((int) $index, 0), $num_siblings);

        if ($index >= $num_siblings) {
            // appending does not need room between two neighbours
            $last_pos = $num_siblings > 0 ? (int) $siblings[$num_siblings - 1]->pos : 0;

            return $last_pos + self::POS_GAP;
        }

        $prev_pos = $index > 0 ? (int) $siblings[$index - 1]->pos : 0;
        $next_pos = (int) $siblings[$index]->pos;

        if ($next_pos - $prev_pos - 1 < self::POS_MIN_GAP) {
            $this->rebalance($parent_id);

            return $this->positionForIndex($parent_id, $index, $exclude_id);
        }

        return intdiv($prev_pos + $next_pos, 2);
    }
    // }}}

    // {{{ rebalance
    /**
     * renumbers the children of a parent node to have an even distance of
     * self::POS_GAP between their positions; called when the sparse
     * positions of a child list are exhausted
     *
     * @param $parent_id (int) db-id of parent node
     * @param $order (array) optional list of child ids giving the new
     *        order, null to keep the order stored in the database
     */
    public function rebalance($parent_id, $order = null): void
    {
        $siblings = $this->siblings($parent_id);

        if (count($siblings) == 0) {
            return;
        }

        $current = [];
        foreach ($siblings as $sibling) {
            $current[] = (int) $sibling->id;
        }

        if (is_null($order)) {
            // keep the order that is stored in the database
            $ids = $current;
        } else {
            // reorder into the given child order (used by the patch
            // applier)
            $ids = array_map('intval', $order);

            if (count($ids) != count($current) || array_diff($ids, $current) || array_diff($current, $ids)) {
                throw new XmlDbException("Can not reorder the children of node {$parent_id}, the node list does not match.");
            }
        }

        // the unique sibling index is checked row by row, so the positions
        // have to be moved out of the way first: adding a big offset in
        // descending order cannot collide with a sibling that has not
        // been updated yet, and the offset is big enough to keep the
        // scratch positions clear of the final positions that are
        // assigned in the update below
        $this->pdo->exec(
            "UPDATE {$this->table}
            SET pos = pos + " . self::SCRATCH_OFFSET . "
            WHERE id_doc = " . (int) $this->doc_id . " AND id_parent = " . (int) $parent_id . "
            ORDER BY pos DESC"
        );

        // every sibling gets a position of its own, so writing the final
        // positions in batches cannot collide with the unique sibling
        // index either
        $positions = new CaseUpdate($this->pdo, $this->table, $this->batch_size);
        foreach ($ids as $index => $id) {
            $positions->add($id, ($index + 1) * self::POS_GAP);
        }
        $positions->write('pos', ['id_doc' => $this->doc_id]);
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
