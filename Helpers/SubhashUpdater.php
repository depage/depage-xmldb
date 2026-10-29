<?php

/**
 * @file    Helpers/SubhashUpdater.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2026 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb\Helpers;

/**
 * @internal recalculating the merkle style subhashes of a document; not
 * part of the public api of this library
 *
 * A subhash covers the stored type, name and value of a node plus the
 * subhashes of all its children, so comparing one hash answers whether a
 * whole subtree changed (that is what makes savePatch() cheap). Subhashes
 * do not include node ids, equal subtrees always have equal hashes.
 */
class SubhashUpdater
{
    // {{{ variables
    protected $pdo;
    // }}}

    // {{{ __construct
    /**
     * @param $pdo database connection to read and write with
     * @param $table (string) name of the xmltree table
     * @param $doc_id (int) document whose subhashes are recalculated
     * @param $pad (int) number of digits of one sortkey level
     *        (Document::SORTKEY_PAD)
     * @param $batch_size (int) number of rows written with one statement
     */
    public function __construct(
        $pdo,
        protected string $table,
        protected $doc_id,
        protected int $pad,
        protected int $batch_size = CaseUpdate::DEFAULT_SIZE
    ) {
        $this->pdo = $pdo;
    }
    // }}}

    // {{{ refresh
    /**
     * recalculates the subhash of every node of the document; nodes that
     * already carry the hash they would be recalculated to are not
     * touched; has to run inside a transaction
     */
    public function refresh(): void
    {
        $query = $this->pdo->prepare(
            TreeSql::descendants($this->table, "id_doc = :doc_id AND id_parent IS NULL", $this->pad) . "
            SELECT xml.id, xml.id_parent, xml.type, xml.name, xml.value, xml.subhash
            FROM tree JOIN {$this->table} AS xml ON tree.id = xml.id
            ORDER BY tree.lvl DESC, xml.id_parent ASC, xml.pos ASC;",
            \Depage\Db\Pdo::unbuffered(),
        );
        $query->execute([
            'doc_id' => $this->doc_id,
        ]);

        // deepest level first, so a node is only reached after all of its
        // children contributed their hash
        $child_hashes = [];

        // the recalculated hashes are only written after the cursor is
        // closed (no statement may run on the connection while the
        // unbuffered result is open), so they are collected in the update
        // buffer first
        $subhashes = new CaseUpdate($this->pdo, $this->table, $this->batch_size);

        while ($row = $query->fetchObject()) {
            $hash = NodeContent::hash($row->type, $row->name, $row->value, $child_hashes[$row->id] ?? []);

            if ($row->subhash !== $hash) {
                $subhashes->add($row->id, $hash);
            }

            if ($row->id_parent !== null) {
                $child_hashes[$row->id_parent][] = $hash;
            }
            unset($child_hashes[$row->id]);
        }
        // no statements may run while the unbuffered result is open
        $query->closeCursor();

        // subhash takes part in no unique index, so the case updates
        // cannot collide
        $subhashes->write('subhash', ['id_doc' => $this->doc_id]);
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
