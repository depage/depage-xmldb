<?php

/**
 * @file    Patch/PatchApplier.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2026 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb\Patch;

use Depage\XmlDb\Helpers\DocumentWriter;
use Depage\XmlDb\Helpers\Positions;

/**
 * @internal executing the change plans of PatchPlanner; not part of the
 * public api of this library
 *
 * All nodes that were not touched by the plan keep their database row
 * (and with it position and subhash) untouched. The applier runs inside
 * whatever transaction the caller opened.
 */
class PatchApplier
{
    // {{{ variables
    protected $pdo;
    // }}}

    // {{{ __construct
    /**
     * @param $pdo database connection to write with
     * @param $table (string) name of the xmltree table
     * @param $docId (int) document the nodes belong to
     * @param $positions (Positions) position management of the document,
     *        used to reorder the child lists the plan changes
     * @param $writer (DocumentWriter) write pipeline of the document,
     *        used to save the new subtrees of the plan
     */
    public function __construct(
        $pdo,
        protected string $table,
        protected $docId,
        protected Positions $positions,
        protected DocumentWriter $writer
    ) {
        $this->pdo = $pdo;
    }
    // }}}

    // {{{ apply
    /**
     * executes the change plan of PatchPlanner::plan()
     *
     * @param $plan (array) plan returned by PatchPlanner::plan()
     */
    public function apply(array $plan): void
    {
        if (!empty($plan['deletes'])) {
            $delete = $this->pdo->prepare(
                "DELETE FROM {$this->table}
                WHERE id_doc = :doc_id AND id = :id",
            );

            foreach ($plan['deletes'] as $nodeId) {
                $delete->execute([
                    'doc_id' => $this->docId,
                    'id' => $nodeId,
                ]);
            }
        }

        if (!empty($plan['updates'])) {
            $update = $this->pdo->prepare(
                "UPDATE {$this->table}
                SET name = :name, value = :value
                WHERE id_doc = :doc_id AND id = :id",
            );

            foreach ($plan['updates'] as $node) {
                $update->execute([
                    'name' => $node['name'],
                    'value' => $node['value'],
                    'doc_id' => $this->docId,
                    'id' => $node['id'],
                ]);
            }
        }

        // new subtrees are appended to the child list of their parent,
        // the reordering below puts them in place
        $insertedIds = [];
        foreach ($plan['inserts'] as $index => $insert) {
            $insertedIds[$index] = $this->writer->saveNodeIn($insert['node'], $insert['parent_id'], -1, true);
        }

        foreach ($plan['renumber'] as $parentId => $layout) {
            $orderedIds = [];
            foreach ($layout as $slot) {
                $orderedIds[] = $slot['id'] ?? $insertedIds[$slot['insert']];
            }

            // skip the reordering when the children are already in the
            // right order (the appends did not change it)
            $current = $this->positions->siblings($parentId);
            $reorder = count($current) != count($orderedIds);
            foreach ($current as $pos => $sibling) {
                if (!$reorder && (int) $sibling->id != $orderedIds[$pos]) {
                    $reorder = true;
                }
            }

            if ($reorder) {
                $this->positions->rebalance($parentId, $orderedIds);
            }
        }
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
