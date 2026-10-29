<?php

/**
 * @file    Patch/PatchPlanner.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2026 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb\Patch;

use Depage\XmlDb\Helpers\NodeContent;
use Depage\XmlDb\XmlNs;

/**
 * @internal comparing a DOM subtree with the stored nodes and collecting
 * the minimal set of changes; not part of the public api of this library
 *
 * The planner only reads the database, applying a plan is the job of
 * PatchApplier. The input is the flattened node array
 * (DocumentWriter::getNodeArrayForSaving), so the planning can be tested
 * without writing anything.
 */
class PatchPlanner
{
    /**
     * when more than this ratio of the nodes of a subtree has to be
     * inserted or deleted, a full save is cheaper and safer than patching
     */
    public const MAX_CHANGE_RATIO = 0.4;

    // {{{ variables
    protected $pdo;
    // }}}

    // {{{ __construct
    /**
     * @param $pdo database connection to read the stored tree with
     * @param $table (string) name of the xmltree table
     * @param $docId (int) document the nodes belong to
     * @param $dbNs (XmlNs) namespace of the db:id attributes
     * @param $idAttribute (string) name of the id attribute
     */
    public function __construct(
        $pdo,
        protected string $table,
        protected $docId,
        protected XmlNs $dbNs,
        protected string $idAttribute = 'id'
    ) {
        $this->pdo = $pdo;
    }
    // }}}

    // {{{ plan
    /**
     * compares the nodes of $nodeArray with the nodes stored in the
     * database and collects the minimal set of changes into a plan for
     * PatchApplier::apply(); returns null when the subtree can not be
     * patched (replaced root, missing database id or too many changes)
     *
     * $checkRoot rejects the plan (instead of renaming the row in place)
     * when the root node is not compatible with the row its id points
     * to, i.e. was replaced by a node of another type or name;
     * saveNodePatch() uses this to fall back to a full saveNode()
     *
     * @param $nodeArray (array) flattened node array
     *        (DocumentWriter::getNodeArrayForSaving)
     *
     * @return (array|null) the change plan or null
     */
    public function plan(array $nodeArray, bool $checkRoot = false): ?array
    {
        if (count($nodeArray) == 0) {
            return null;
        }

        $rootId = $nodeArray[0]['id'];

        if (empty($rootId)) {
            // new subtree or the root node was replaced
            return null;
        }

        $rootId = (int) $rootId;

        // load the stored tree, children of each parent in document order
        $query = $this->pdo->prepare(
            "SELECT xml.id, xml.id_parent, xml.type, xml.name, xml.value, xml.subhash
            FROM {$this->table} AS xml
            WHERE xml.id_doc = :doc_id
            ORDER BY xml.id_parent ASC, xml.pos ASC",
        );
        $query->execute([
            'doc_id' => $this->docId,
        ]);

        $oldRows = [];
        $oldChildren = [];
        while ($row = $query->fetchObject()) {
            $oldRows[(int) $row->id] = $row;

            if (!is_null($row->id_parent)) {
                $oldChildren[(int) $row->id_parent][] = $row;
            }
        }
        $query->closeCursor();

        if (!isset($oldRows[$rootId])) {
            return null;
        }

        if (
            $checkRoot
            && !$this->nodesCompatible($this->nodeContent($nodeArray[0]['node']), $oldRows[$rootId])
        ) {
            // the root node replaced the node its id points to instead of
            // being a changed version of it
            return null;
        }

        // number of stored nodes below the subtree root; the change limit
        // is checked against the size of the patched subtree instead of
        // the whole document (for a document patch these are the same)
        $oldSubtree = 0;
        $parents = [$rootId];
        while (!empty($parents)) {
            $parentId = array_shift($parents);
            $oldSubtree++;

            foreach ($oldChildren[$parentId] ?? [] as $childRow) {
                $parents[] = (int) $childRow->id;
            }
        }

        // children of every node of the new document
        $newChildren = [];
        foreach ($nodeArray as $index => $node) {
            if (!is_null($node['parent_index'])) {
                $newChildren[$node['parent_index']][] = $index;
            }
        }

        // canonical content and subtree hashes of the new document; the
        // nodes are in document order, so going backwards processes the
        // children before their parents
        $content = [];
        $hashes = [];
        for ($index = count($nodeArray) - 1; $index >= 0; $index--) {
            $content[$index] = $this->nodeContent($nodeArray[$index]['node']);
            $content[$index][2] = \Normalizer::normalize($content[$index][2]);

            $childHashes = [];
            foreach ($newChildren[$index] ?? [] as $childIndex) {
                $childHashes[] = $hashes[$childIndex];
            }

            $hashes[$index] = NodeContent::hash($content[$index][0], $content[$index][1], $content[$index][2], $childHashes);
        }

        $plan = [
            'root_id' => $rootId,
            'deletes' => [],
            'updates' => [],
            'inserts' => [],
            'renumber' => [],
            'stats' => [
                'identical' => 0,
                'updates' => 0,
                'deletes' => 0,
                'inserts' => 0,
                'renumber' => 0,
                'old_nodes' => $oldSubtree,
                'new_nodes' => count($nodeArray),
            ],
        ];

        $queue = [[0, $rootId]];

        while (!empty($queue)) {
            [$index, $oldId] = array_shift($queue);

            $oldRow = $oldRows[$oldId];

            if (!is_null($oldRow->subhash) && $oldRow->subhash === $hashes[$index]) {
                // this whole subtree is identical, do not descend
                $plan['stats']['identical']++;
                continue;
            }

            // compare the content of the pair; this also normalizes the
            // attribute strings of nodes that were stored by older
            // versions of the library (which did not add the trailing
            // space after every attribute)
            [$type, $name, $value] = $content[$index];

            if ($oldRow->type != $type || (string) ($oldRow->name ?? '') !== (string) ($name ?? '') || $oldRow->value !== $value) {
                $plan['updates'][] = [
                    'id' => $oldId,
                    'name' => $name,
                    'value' => $value,
                ];
                $plan['stats']['updates']++;
            }

            $newKids = $newChildren[$index] ?? [];
            $oldKids = $oldChildren[$oldId] ?? [];

            // match the children of this parent by database id first, the
            // leftover nodes are matched by their relative position
            $oldPosById = [];
            foreach ($oldKids as $pos => $row) {
                $oldPosById[(int) $row->id] = $pos;
            }

            $pairOfNew = [];
            $usedOld = [];

            foreach ($newKids as $newIndex) {
                $id = $nodeArray[$newIndex]['id'];

                // elements without a db:id have an empty id, other node
                // types have no id at all
                if (!empty($id) && isset($oldPosById[(int) $id]) && !isset($usedOld[$oldPosById[(int) $id]])) {
                    $pairOfNew[$newIndex] = $oldPosById[(int) $id];
                    $usedOld[$oldPosById[(int) $id]] = true;
                }
            }

            // positional matching for the nodes without a (recognized)
            // id; the first pair that is not compatible counts as
            // replacement of one node by the other
            $restNew = array_values(array_diff($newKids, array_keys($pairOfNew)));
            $restOld = array_values(array_diff(array_keys($oldKids), array_keys($usedOld)));

            $insertNew = [];
            $deleteOld = [];

            while (!empty($restNew) && !empty($restOld)) {
                $newIndex = array_shift($restNew);
                $oldPos = array_shift($restOld);

                if ($this->nodesCompatible($content[$newIndex], $oldKids[$oldPos])) {
                    $pairOfNew[$newIndex] = $oldPos;
                } else {
                    $insertNew[] = $newIndex;
                    $deleteOld[] = $oldPos;
                }
            }
            $insertNew = array_merge($insertNew, $restNew);
            $deleteOld = array_merge($deleteOld, $restOld);

            foreach ($deleteOld as $oldPos) {
                $plan['deletes'][] = (int) $oldKids[$oldPos]->id;
            }
            $plan['stats']['deletes'] += count($deleteOld);

            // final order of the children: matched nodes keep their id,
            // the unmatched new nodes are inserted at their places
            $layout = [];
            $survivorsNewOrder = [];

            foreach ($newKids as $newIndex) {
                if (isset($pairOfNew[$newIndex])) {
                    $childOldId = (int) $oldKids[$pairOfNew[$newIndex]]->id;

                    $layout[] = ['id' => $childOldId];
                    $survivorsNewOrder[] = $childOldId;
                } else {
                    $layout[] = ['insert' => count($plan['inserts'])];

                    $plan['inserts'][] = [
                        'parent_id' => $oldId,
                        'node' => $nodeArray[$newIndex]['node'],
                    ];
                }
            }
            $plan['stats']['inserts'] += count($insertNew);

            $pairedOld = array_values($pairOfNew);

            $survivorsStoredOrder = [];
            foreach ($oldKids as $pos => $row) {
                if (in_array($pos, $pairedOld)) {
                    $survivorsStoredOrder[] = (int) $row->id;
                }
            }

            if (count($insertNew) > 0 || $survivorsNewOrder != $survivorsStoredOrder) {
                $plan['renumber'][$oldId] = $layout;
                $plan['stats']['renumber']++;
            }

            // the matched pairs are processed with the same procedure
            foreach ($pairOfNew as $newIndex => $oldPos) {
                $queue[] = [$newIndex, (int) $oldKids[$oldPos]->id];
            }
        }

        // structural changes: when more than MAX_CHANGE_RATIO of the
        // nodes of the subtree have to be inserted or deleted, a full
        // save is cheaper and safer than patching; updated nodes only
        // need one row write each and do not count into this
        $changed = count($plan['inserts']) + count($plan['deletes']);

        if ($changed > self::MAX_CHANGE_RATIO * max($oldSubtree, count($nodeArray))) {
            return null;
        }

        return $plan;
    }
    // }}}

    // {{{ nodeContent
    /**
     * returns the (type, name, value) tupel a node of the flattened
     * array is stored as, normalized the same way the stored values are
     */
    protected function nodeContent($node): array
    {
        $content = NodeContent::fromDomNode($node, $this->dbNs, $this->idAttribute);
        $content[2] = \Normalizer::normalize($content[2]);

        return $content;
    }
    // }}}
    // {{{ nodesCompatible
    /**
     * whether a DOM node may take the place of a stored row, i.e. the
     * first two components of the content tupel match the type and name
     * of the row; a node that is not compatible with its position
     * candidate counts as insert plus delete instead of an update
     */
    protected function nodesCompatible(array $content, $row): bool
    {
        return $content[0] == $row->type && (string) ($content[1] ?? '') === (string) ($row->name ?? '');
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
