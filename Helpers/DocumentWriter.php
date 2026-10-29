<?php

/**
 * @file    Helpers/DocumentWriter.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2026 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb\Helpers;

use Depage\XmlDb\Interfaces\DocumentWriterHost;
use Depage\XmlDb\XmlNs;

/**
 * @internal the write pipeline of Document; not part of the public api
 * of this library
 *
 * Turns DOM trees into rows of the xmltree table: flattens a subtree,
 * hands out database ids, writes the rows level by level and resolves
 * the auto increment ids batch by batch. The writer runs inside whatever
 * transaction the caller (Document) opened; the transaction, the
 * doctype handler and the read path stay on the document and are
 * reached through the DocumentWriterHost interface.
 */
class DocumentWriter
{
    // {{{ variables
    protected $pdo;

    /**
     * prepared statement that inserts or updates a single node row;
     * reused between the nodes of one save and dropped whenever other
     * statements wrote rows (it must not stay open over them)
     */
    private $insertNodeQuery = null;
    // }}}

    // {{{ __construct
    /**
     * @param $pdo database connection to write with
     * @param $table (string) name of the xmltree table
     * @param $doc_id (int) document the nodes belong to
     * @param $db_ns (XmlNs) namespace to stamp the db:id attributes with
     * @param $id_attribute (string) name of the id attribute
     * @param $positions (Positions) position management of the document
     * @param $host (DocumentWriterHost) document the writer writes;
     *        provides change tracking, the doctype handler, the node
     *        lookups and the read path (see the interface)
     */
    public function __construct(
        $pdo,
        protected string $table,
        protected $doc_id,
        protected XmlNs $db_ns,
        protected string $id_attribute,
        protected Positions $positions,
        protected DocumentWriterHost $host
    ) {
        $this->pdo = $pdo;
    }
    // }}}

    // {{{ getFreeNodeIds
    /**
     * gets unused db-node-ids for saving nodes
     *
     * @param $preference (array) ids to check first (the ids the nodes to
     *        save carried in the dom)
     *
     * @return (array) list of ids that are free of the preferred ones
     */
    public function getFreeNodeIds($preference = [])
    {
        if (!empty($preference)) {
            $ids = str_repeat('?,', count($preference) - 1) . '?';
            $query = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE id IN ($ids)");
            $query->execute($preference);
            $results = $query->fetchAll(\PDO::FETCH_COLUMN);

            $free = array_keys(array_flip(array_diff($preference, $results)));
        } else {
            $free = [];
        }

        return $free;
    }
    // }}}

    // {{{ deleteNode
    /**
     * deletes a node in the database
     *
     * @param $node_id (int) db-id of node
     *
     * @return $target_id (int) id of the parent node the node was child of
     */
    public function deleteNode($node_id)
    {
        // get parent (needed by the doctype handler and as return value)
        $target_id = $this->host->getParentIdById($node_id);

        if ($this->host->getDoctypeHandler()->onDeleteNode($node_id, $target_id)) {
            // delete the node
            $query = $this->pdo->prepare(
                "DELETE FROM {$this->table}
                WHERE id_doc = :doc_id AND id = :node_id",
            );
            try {
                $query->execute([
                    'doc_id' => $this->doc_id,
                    'node_id' => $node_id,
                ]);
            } catch (\PDOException $e) {
                $childquery = $this->pdo->prepare(
                    TreeSql::descendants($this->table, "id = :id AND id_doc = :doc_id", $this->host::SORTKEY_PAD) . "
                    SELECT id FROM tree ORDER BY sortkey DESC;",
                );
                $childquery->execute([
                    'doc_id' => $this->doc_id,
                    'id' => $node_id,
                ]);

                // all ids have to be collected before the first delete,
                // statements must not run while the result is still open;
                // deleting deepest node first keeps the fk cascade shallow
                $child_ids = $childquery->fetchAll(\PDO::FETCH_COLUMN);
                $childquery->closeCursor();

                foreach ($child_ids as $child_id) {
                    $query->execute([
                        'doc_id' => $this->doc_id,
                        'node_id' => $child_id,
                    ]);
                }
            }
        }
        return $target_id;
    }
    // }}}
    // {{{ moveNode
    /**
     * moves node in database
     *
     * // TODO prevent moving a node to its children
     *
     * @param $node_id (int) db-id of node
     * @param $target_id (int) db-id of target node
     * @param $target_pos (int) index the node gets in the child list of the
     *        target node, -1 appends it
     *
     * @return   (int) id of old parent node, false if the node was not moved
     */
    public function moveNode($node_id, $target_id, $target_pos)
    {
        $result = false;
        $node_parent_id = $this->host->getParentIdById($node_id);
        $node_index = $this->positions->indexById($node_id);

        // $target_pos is the index the node has after the move; inside its
        // own parent the node is not part of the child list that index
        // counts, so it must not be counted there either (a move to the
        // index it already has is then the "no move" case)
        $target_pos = $this->positions->normalizeIndex(
            $target_id,
            $target_pos,
            ($target_id == $node_parent_id) ? $node_id : null
        );

        if ($target_id != $node_parent_id || $target_pos != $node_index) {
            // with sparse positions the node only has to be reattached
            // between its new neighbours, all other nodes keep their
            // positions
            $pos = $this->positions->positionForIndex($target_id, $target_pos, $node_id);

            $query = $this->pdo->prepare(
                "UPDATE {$this->table}
                SET id_doc = :doc_id, id_parent = :target_id, pos = :pos
                WHERE id = :node_id AND id_doc = :node_doc_id",
            );
            $query->execute([
                'target_id' => $target_id,
                'pos' => $pos,
                'node_id' => $node_id,
                'doc_id' => $this->doc_id,
                'node_doc_id' => $this->doc_id,
            ]);

            $this->host->updateLastChange();
            $result = $node_parent_id;
        }

        return $result;
    }
    // }}}
    // {{{ copyNode
    /**
     * copy node in database
     *
     * @param $node_id (int) db-id of node
     * @param $target_id (int) db-id of target node
     * @param $target_pos (int) index the copy gets in the child list of the
     *        target node, -1 appends it
     *
     * @return $save_id (int) db-id of the copy
     */
    public function copyNode($node_id, $target_id, $target_pos)
    {
        $xmlDoc = $this->host->getSubdocByNodeId($node_id, false);
        $root_node = $xmlDoc;
        $save_id = $this->saveNodeIn($root_node, $target_id, $target_pos, true);

        return $save_id;
    }
    // }}}
    // {{{ saveAttributes
    /**
     * writes the attribute string of a node
     *
     * @param $node_id (int) db-id of node to set attribute
     * @param $attributes (array) array of attribute values
     */
    public function saveAttributes($node_id, $attributes)
    {
        $query = $this->pdo->prepare(
            "UPDATE {$this->table} AS xml
            SET xml.value = :attr_str
            WHERE xml.id = :node_id AND xml.id_doc = :doc_id",
        );
        $success = $query->execute([
            'node_id' => $node_id,
            'attr_str' => NodeContent::attributeString($attributes, $this->db_ns, $this->id_attribute),
            'doc_id' => $this->doc_id,
        ]);

        $this->host->updateLastChange();

        return $success;
    }
    // }}}

    // {{{ saveNodePrivate
    /**
     * re-saves a node in place: the subtree replaces the stored nodes
     * under the db:id its root carries
     */
    public function saveNodePrivate($node)
    {
        // get all nodes in array
        $node_array = [];
        $this->getNodeArrayForSaving($node_array, $node);
        $rootId = $node_array[0]['id'];

        if (is_null($rootId)) {
            $target_id = null;
            $target_pos = 0;
        } else {
            // set target_id/pos/doc
            $target_id = $this->host->getParentIdById($rootId);
            $target_pos = $this->positions->indexById($rootId);

            if ($target_id === false) {
                $target_id = null;
            }
            if ($target_pos === false) {
                $target_pos = null;
            }

            // delete old node
            $this->deleteNode($rootId);
        }

        return $this->saveNodeArray($node_array, $target_id, $target_pos, true);
    }
    // }}}
    // {{{ saveNodeIn
    /**
     * saves a node into the child list of a target node
     *
     * @param $node (domxmlnode) node to save
     * @param $target_id (int) db-id of the node to save the node in
     * @param $target_pos (int) index the node gets in the child list of
     *        $target_id, -1 appends it
     * @param $inc_children (bool) whether to save the child nodes too
     *
     * @return (int) db-id the node was saved under
     */
    public function saveNodeIn($node, $target_id, $target_pos, $inc_children)
    {
        $parent_id = $this->host->getParentIdById($target_id);

        // delete child nodes, if target is document
        if ($parent_id === false) {
            $this->pdo->exec('SET foreign_key_checks = 0;');

            try {
                $query = $this->pdo->prepare(
                    "DELETE
                    FROM {$this->table}
                    WHERE id_doc = :doc_id",
                );
                $query->execute([
                    'doc_id' => $this->doc_id,
                ]);
            } finally {
                // foreign key checks are session scoped, keep them enabled
                // even if the delete failed
                $this->pdo->exec('SET foreign_key_checks = 1;');
            }
        }

        // saveNodeToDb turns this index into the position between the
        // neighbours it is inserted at
        $target_pos = $this->positions->normalizeIndex($target_id, $target_pos);

        // get all nodes in array
        $node_array = [];
        $this->getNodeArrayForSaving($node_array, $node);

        return $this->saveNodeArray($node_array, $target_id, $target_pos, $inc_children);
    }
    // }}}
    // {{{ saveNodeArray
    /**
     * writes a flattened node tree; the ids the nodes carry are reused
     * when they are free, new nodes are handed out free ids of the
     * preferred range
     */
    public function saveNodeArray($node_array, $target_id, $target_pos, $inc_children)
    {
        $ids = [];

        foreach ($node_array as $node) {
            if ($node['id']) {
                $ids[] = $node['id'];
            }
        }

        $free = $this->getFreeNodeIds($ids);

        foreach ($node_array as $i => $node) {
            if (!is_null($node['id'])) {
                $index = array_search($node['id'], $free);

                if ($index === false) {
                    $node_array[$i]['id'] = null;
                } else {
                    array_splice($free, $index, 1);
                }
            }
        }

        foreach ($node_array as $i => $node) {
            if (is_null($node['id'])) {
                $node_array[$i]['id'] = array_shift($free);
            }
        }

        // save root node
        $node_array[0]['id'] = $this->saveNodeToDb($node_array[0]['node'], $node_array[0]['id'], $target_id, $target_pos, true);
        $this->insertNodeQuery = null;

        if ($inc_children) {
            // save element nodes
            $this->saveNodesByType($node_array, true);
            // save other nodes
            $this->saveNodesByType($node_array, false);
        }

        $this->host->updateLastChange();

        return $node_array[0]['id'];
    }
    // }}}
    // {{{ saveNodesByType
    /**
     * writes all nodes of one type (element nodes first, the other node
     * types after them) level by level
     */
    public function saveNodesByType(&$node_array, $xml_element_node)
    {
        $batch = new NodeBatch($this->pdo, $this->table, (int) $this->doc_id, $this->host::SAVE_BATCH_SIZE);

        // writing the tree level by level makes sure that every node's
        // parent has been saved (and its auto increment id read back)
        // before the node itself is written; within one level the
        // parents are always already resolved, so no batch has to be
        // interrupted
        $depths = [0];
        $max_depth = 0;
        foreach ($node_array as $i => $node) {
            if ($i == 0) {
                continue;
            }
            $depths[$i] = $depths[$node['parent_index']] + 1;
            $max_depth = max($max_depth, $depths[$i]);
        }

        for ($level = 1; $level <= $max_depth; $level++) {
            foreach ($node_array as $i => $node) {
                if (
                    $depths[$i] != $level
                    || (($node['node']->nodeType == XML_ELEMENT_NODE) != $xml_element_node)
                ) {
                    continue;
                }

                $parent_id = $node_array[$node['parent_index']]['id'];

                [$type, $name, $value] = NodeContent::fromDomNode($node['node'], $this->db_ns, $this->id_attribute);
                $value = \Normalizer::normalize($value);

                $batch->add($i, $node['id'], $parent_id, ($node['pos'] + 1) * Positions::POS_GAP, $name, $value, $type);

                if ($batch->isFull()) {
                    $this->setResolvedNodeIds($batch->write(), $node_array);
                }
            }

            // the auto increment ids of this level have to be known
            // before its children can reference them
            $this->setResolvedNodeIds($batch->write(), $node_array);
        }

        $this->setResolvedNodeIds($batch->write(), $node_array);

        $this->insertNodeQuery = null;
    }
    // }}}
    // {{{ setResolvedNodeIds
    /**
     * stores the database ids a batch insert handed out for its nodes in
     * the list of nodes to save (the parents of the following tree level
     * and the caller of the save work with them) and adds them as db:id
     * attribute to the nodes of the saved dom
     *
     * @param $resolved_ids (array) database ids keyed by the index of the
     *        node in $node_array
     * @param $node_array (array) nodes to save, as built by
     *             getNodeArrayForSaving
     */
    protected function setResolvedNodeIds($resolved_ids, &$node_array)
    {
        foreach ($resolved_ids as $index => $id) {
            $node_array[$index]['id'] = $id;

            if ($node_array[$index]['node']->nodeType == XML_ELEMENT_NODE) {
                $node_array[$index]['node']->setAttributeNS(
                    $this->db_ns->uri,
                    $this->db_ns->ns . ':' . $this->id_attribute,
                    $id
                );
            }
        }
    }
    // }}}
    // {{{ getNodeArrayForSaving
    /**
     * gets all nodes of a document in one array
     *
     * @param $node_array (array) list of nodes to add current node to
     * @param $node (domxmlnode) current node
     * @param $parent_index (int) index of parent node in created node list
     * @param $pos (int) position of current node
     * @param $stripwhitespace (bool) whether to strip whitespace from
     *           textnodes while saving
     */
    public function getNodeArrayForSaving(&$node_array, $node, $parent_index = null, $pos = 0, $stripwhitespace = true)
    {
        if (!$node) {
            return;
        }
        $type = $node->nodeType;

        if ($type === XML_DOCUMENT_NODE) {
            $root_node = $node->documentElement;
            $this->getNodeArrayForSaving($node_array, $root_node, $parent_index, $pos, $stripwhitespace);
        } elseif ($type === XML_ELEMENT_NODE) {
            $id = NodeContent::dbIdOf($node, $this->db_ns, $this->id_attribute);
            $node_array[] = [
                'id' => $id,
                'id_old' => $id,
                'parent_index' => $parent_index,
                'pos' => $pos,
                'node' => $node,
            ];

            $parent_index = count($node_array) - 1;
            $node_name = (($node->prefix != '') ? $node->prefix . ':' : '') . $node->localName;

            $preserveWhitespace = $this->host->getDoctypeHandler()->getPreserveWhitespace();

            if (
                !$stripwhitespace
                || in_array($node_name, $preserveWhitespace)
            ) {
                $stripwhitespace = false;
            }

            $tmp_node = $node->firstChild;
            $i = 0;
            while ($tmp_node != null) {
                if (
                    $tmp_node->nodeType != XML_TEXT_NODE
                    || (
                        !$stripwhitespace
                        || trim($tmp_node->textContent) != ''
                    )
                ) {
                    $this->getNodeArrayForSaving($node_array, $tmp_node, $parent_index, $i, $stripwhitespace);
                    $i++;
                }
                $tmp_node = $tmp_node->nextSibling;
            }
        } else {
            // is *_NODE
            $node_array[] = [
                'id' => null,
                'id_old' => null,
                'parent_index' => $parent_index,
                'pos' => $pos,
                'node' => $node,
            ];
        }
    }
    // }}}
    // {{{ saveNodeToDb
    /**
     * saves a node to database
     *
     * @param    $node (domxmlnode) node to save
     * @param    $id (int) db-id to save node in
     * @param    $target_id (int) db-id of parent node
     * @param    $target_pos (int) index the node gets in the child list of
     *                     $target_id, -1 appends it
     * @param    $set_position (bool) whether to give the node a position in
     *                     the child list of the target node; without it the
     *                     node is stored with $target_pos as position
     *
     * @return    $id (int) db-id under which node has been saved
     */
    public function saveNodeToDb($node, $id, $target_id, $target_pos, $set_position = false)
    {
        if (is_null($this->insertNodeQuery)) {
            $this->insertNodeQuery = $this->pdo->prepare(
                "INSERT {$this->table}
                SET
                    id = :id_query,
                    id_parent = :target_id,
                    id_doc = :doc_id,
                    pos = :target_pos,
                    name = :name,
                    value = :value,
                    type = :type
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            );
        }

        if (is_null($id) || !is_numeric($id)) {
            $id_query = null;
        } else {
            $id_query = (int) $id;
        }

        if ($target_id !== null && $set_position) {
            // sparse positioning: the node gets a position between its
            // future neighbours, the other children keep theirs; this is
            // done for every node type, a text node stored with the plain
            // index would sort in front of the sparse positions
            $target_pos = $this->positions->positionForIndex($target_id, $target_pos);
        }

        // same tupel NodeContent calculates the subhashes from
        [$node_type, $node_name, $node_data] = NodeContent::fromDomNode($node, $this->db_ns, $this->id_attribute);
        $node_data = \Normalizer::normalize($node_data);

        $this->insertNodeQuery->execute([
            'id_query' => $id_query,
            'target_id' => $target_id,
            'target_pos' => $target_pos,
            'name' => $node_name,
            'value' => $node_data,
            'type' => $node_type,
            'doc_id' => $this->doc_id,
        ]);

        if (is_null($id)) {
            $id = $this->pdo->lastInsertId();
        }

        if ($node->nodeType == XML_ELEMENT_NODE) {
            $node->setAttributeNS($this->db_ns->uri, $this->db_ns->ns . ':' . $this->id_attribute, $id);
        }

        return $id;
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
