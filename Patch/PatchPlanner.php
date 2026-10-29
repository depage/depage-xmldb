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
     * @param $doc_id (int) document the nodes belong to
     * @param $db_ns (XmlNs) namespace of the db:id attributes
     * @param $id_attribute (string) name of the id attribute
     */
    public function __construct(
        $pdo,
        protected string $table,
        protected $doc_id,
        protected XmlNs $db_ns,
        protected string $id_attribute = 'id'
    ) {
        $this->pdo = $pdo;
    }
    // }}}

    // {{{ plan
    /**
     * compares the nodes of $node_array with the nodes stored in the
     * database and collects the minimal set of changes into a plan for
     * PatchApplier::apply(); returns null when the subtree can not be
     * patched (replaced root, missing database id or too many changes)
     *
     * $check_root rejects the plan (instead of renaming the row in place)
     * when the root node is not compatible with the row its id points
     * to, i.e. was replaced by a node of another type or name;
     * saveNodePatch() uses this to fall back to a full saveNode()
     *
     * @param $node_array (array) flattened node array
     *        (DocumentWriter::getNodeArrayForSaving)
     *
     * @return (array|null) the change plan or null
     */
    public function plan(array $node_array, bool $check_root = false): ?array
    {
        if (count($node_array) == 0) {
            return null;
        }

        $root_id = $node_array[0]['id'];

        if (empty($root_id)) {
            // new subtree or the root node was replaced
            return null;
        }

        $root_id = (int) $root_id;

        // load the stored tree, children of each parent in document order
        $query = $this->pdo->prepare(
            "SELECT xml.id, xml.id_parent, xml.type, xml.name, xml.value, xml.subhash
            FROM {$this->table} AS xml
            WHERE xml.id_doc = :doc_id
            ORDER BY xml.id_parent ASC, xml.pos ASC",
        );
        $query->execute([
            'doc_id' => $this->doc_id,
        ]);

        $old_rows = [];
        $old_children = [];
        while ($row = $query->fetchObject()) {
            $old_rows[(int) $row->id] = $row;

            if (!is_null($row->id_parent)) {
                $old_children[(int) $row->id_parent][] = $row;
            }
        }
        $query->closeCursor();

        if (!isset($old_rows[$root_id])) {
            return null;
        }

        if (
            $check_root
            && !$this->nodesCompatible($this->nodeContent($node_array[0]['node']), $old_rows[$root_id])
        ) {
            // the root node replaced the node its id points to instead of
            // being a changed version of it
            return null;
        }

        // number of stored nodes below the subtree root; the change limit
        // is checked against the size of the patched subtree instead of
        // the whole document (for a document patch these are the same)
        $old_subtree = 0;
        $parents = [$root_id];
        while (!empty($parents)) {
            $parent_id = array_shift($parents);
            $old_subtree++;

            foreach ($old_children[$parent_id] ?? [] as $child_row) {
                $parents[] = (int) $child_row->id;
            }
        }

        // children of every node of the new document
        $new_children = [];
        foreach ($node_array as $index => $node) {
            if (!is_null($node['parent_index'])) {
                $new_children[$node['parent_index']][] = $index;
            }
        }

        // canonical content and subtree hashes of the new document; the
        // nodes are in document order, so going backwards processes the
        // children before their parents
        $content = [];
        $hashes = [];
        for ($index = count($node_array) - 1; $index >= 0; $index--) {
            $content[$index] = $this->nodeContent($node_array[$index]['node']);
            $content[$index][2] = \Normalizer::normalize($content[$index][2]);

            $child_hashes = [];
            foreach ($new_children[$index] ?? [] as $child_index) {
                $child_hashes[] = $hashes[$child_index];
            }

            $hashes[$index] = NodeContent::hash($content[$index][0], $content[$index][1], $content[$index][2], $child_hashes);
        }

        $plan = [
            'root_id' => $root_id,
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
                'old_nodes' => $old_subtree,
                'new_nodes' => count($node_array),
            ],
        ];

        $queue = [[0, $root_id]];

        while (!empty($queue)) {
            [$index, $old_id] = array_shift($queue);

            $old_row = $old_rows[$old_id];

            if (!is_null($old_row->subhash) && $old_row->subhash === $hashes[$index]) {
                // this whole subtree is identical, do not descend
                $plan['stats']['identical']++;
                continue;
            }

            // compare the content of the pair; this also normalizes the
            // attribute strings of nodes that were stored by older
            // versions of the library (which did not add the trailing
            // space after every attribute)
            [$type, $name, $value] = $content[$index];

            if ($old_row->type != $type || (string) ($old_row->name ?? '') !== (string) ($name ?? '') || $old_row->value !== $value) {
                $plan['updates'][] = [
                    'id' => $old_id,
                    'name' => $name,
                    'value' => $value,
                ];
                $plan['stats']['updates']++;
            }

            $new_kids = $new_children[$index] ?? [];
            $old_kids = $old_children[$old_id] ?? [];

            // match the children of this parent by database id first, the
            // leftover nodes are matched by their relative position
            $old_pos_by_id = [];
            foreach ($old_kids as $pos => $row) {
                $old_pos_by_id[(int) $row->id] = $pos;
            }

            $pair_of_new = [];
            $used_old = [];

            foreach ($new_kids as $new_index) {
                $id = $node_array[$new_index]['id'];

                // elements without a db:id have an empty id, other node
                // types have no id at all
                if (!empty($id) && isset($old_pos_by_id[(int) $id]) && !isset($used_old[$old_pos_by_id[(int) $id]])) {
                    $pair_of_new[$new_index] = $old_pos_by_id[(int) $id];
                    $used_old[$old_pos_by_id[(int) $id]] = true;
                }
            }

            // positional matching for the nodes without a (recognized)
            // id; the first pair that is not compatible counts as
            // replacement of one node by the other
            $rest_new = array_values(array_diff($new_kids, array_keys($pair_of_new)));
            $rest_old = array_values(array_diff(array_keys($old_kids), array_keys($used_old)));

            $insert_new = [];
            $delete_old = [];

            while (!empty($rest_new) && !empty($rest_old)) {
                $new_index = array_shift($rest_new);
                $old_pos = array_shift($rest_old);

                if ($this->nodesCompatible($content[$new_index], $old_kids[$old_pos])) {
                    $pair_of_new[$new_index] = $old_pos;
                } else {
                    $insert_new[] = $new_index;
                    $delete_old[] = $old_pos;
                }
            }
            $insert_new = array_merge($insert_new, $rest_new);
            $delete_old = array_merge($delete_old, $rest_old);

            foreach ($delete_old as $old_pos) {
                $plan['deletes'][] = (int) $old_kids[$old_pos]->id;
            }
            $plan['stats']['deletes'] += count($delete_old);

            // final order of the children: matched nodes keep their id,
            // the unmatched new nodes are inserted at their places
            $layout = [];
            $survivors_new_order = [];

            foreach ($new_kids as $new_index) {
                if (isset($pair_of_new[$new_index])) {
                    $child_old_id = (int) $old_kids[$pair_of_new[$new_index]]->id;

                    $layout[] = ['id' => $child_old_id];
                    $survivors_new_order[] = $child_old_id;
                } else {
                    $layout[] = ['insert' => count($plan['inserts'])];

                    $plan['inserts'][] = [
                        'parent_id' => $old_id,
                        'node' => $node_array[$new_index]['node'],
                    ];
                }
            }
            $plan['stats']['inserts'] += count($insert_new);

            $paired_old = array_values($pair_of_new);

            $survivors_stored_order = [];
            foreach ($old_kids as $pos => $row) {
                if (in_array($pos, $paired_old)) {
                    $survivors_stored_order[] = (int) $row->id;
                }
            }

            if (count($insert_new) > 0 || $survivors_new_order != $survivors_stored_order) {
                $plan['renumber'][$old_id] = $layout;
                $plan['stats']['renumber']++;
            }

            // the matched pairs are processed with the same procedure
            foreach ($pair_of_new as $new_index => $old_pos) {
                $queue[] = [$new_index, (int) $old_kids[$old_pos]->id];
            }
        }

        // structural changes: when more than MAX_CHANGE_RATIO of the
        // nodes of the subtree have to be inserted or deleted, a full
        // save is cheaper and safer than patching; updated nodes only
        // need one row write each and do not count into this
        $changed = count($plan['inserts']) + count($plan['deletes']);

        if ($changed > self::MAX_CHANGE_RATIO * max($old_subtree, count($node_array))) {
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
        $content = NodeContent::fromDomNode($node, $this->db_ns, $this->id_attribute);
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
