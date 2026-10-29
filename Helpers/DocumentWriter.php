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
     * @param $docId (int) document the nodes belong to
     * @param $dbNs (XmlNs) namespace to stamp the db:id attributes with
     * @param $idAttribute (string) name of the id attribute
     * @param $positions (Positions) position management of the document
     * @param $host (DocumentWriterHost) document the writer writes;
     *        provides change tracking, the doctype handler, the node
     *        lookups and the read path (see the interface)
     */
    public function __construct(
        $pdo,
        protected string $table,
        protected $docId,
        protected XmlNs $dbNs,
        protected string $idAttribute,
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
     * @param $nodeId (int) db-id of node
     *
     * @return $targetId (int) id of the parent node the node was child of
     */
    public function deleteNode($nodeId)
    {
        // get parent (needed by the doctype handler and as return value)
        $targetId = $this->host->getParentIdById($nodeId);

        if ($this->host->getDoctypeHandler()->onDeleteNode($nodeId, $targetId)) {
            // delete the node
            $query = $this->pdo->prepare(
                "DELETE FROM {$this->table}
                WHERE id_doc = :doc_id AND id = :node_id",
            );
            try {
                $query->execute([
                    'doc_id' => $this->docId,
                    'node_id' => $nodeId,
                ]);
            } catch (\PDOException $e) {
                $childquery = $this->pdo->prepare(
                    TreeSql::descendants($this->table, "id = :id AND id_doc = :doc_id", $this->host::SORTKEY_PAD) . "
                    SELECT id FROM tree ORDER BY sortkey DESC;",
                );
                $childquery->execute([
                    'doc_id' => $this->docId,
                    'id' => $nodeId,
                ]);

                // all ids have to be collected before the first delete,
                // statements must not run while the result is still open;
                // deleting deepest node first keeps the fk cascade shallow
                $childIds = $childquery->fetchAll(\PDO::FETCH_COLUMN);
                $childquery->closeCursor();

                foreach ($childIds as $childId) {
                    $query->execute([
                        'doc_id' => $this->docId,
                        'node_id' => $childId,
                    ]);
                }
            }
        }
        return $targetId;
    }
    // }}}
    // {{{ moveNode
    /**
     * moves node in database
     *
     * // TODO prevent moving a node to its children
     *
     * @param $nodeId (int) db-id of node
     * @param $targetId (int) db-id of target node
     * @param $targetIndex (int) index the node gets in the child list of the
     *        target node, -1 appends it
     *
     * @return   (int) id of old parent node, false if the node was not moved
     */
    public function moveNode($nodeId, $targetId, $targetIndex)
    {
        $result = false;
        $nodeParentId = $this->host->getParentIdById($nodeId);
        $nodeIndex = $this->positions->indexById($nodeId);

        // $targetIndex is the index the node has after the move; inside its
        // own parent the node is not part of the child list that index
        // counts, so it must not be counted there either (a move to the
        // index it already has is then the "no move" case)
        $targetIndex = $this->positions->normalizeIndex(
            $targetId,
            $targetIndex,
            ($targetId == $nodeParentId) ? $nodeId : null
        );

        if ($targetId != $nodeParentId || $targetIndex != $nodeIndex) {
            // with sparse positions the node only has to be reattached
            // between its new neighbours, all other nodes keep their
            // positions
            $pos = $this->positions->positionForIndex($targetId, $targetIndex, $nodeId);

            $query = $this->pdo->prepare(
                "UPDATE {$this->table}
                SET id_doc = :doc_id, id_parent = :target_id, pos = :pos
                WHERE id = :node_id AND id_doc = :node_doc_id",
            );
            $query->execute([
                'target_id' => $targetId,
                'pos' => $pos,
                'node_id' => $nodeId,
                'doc_id' => $this->docId,
                'node_doc_id' => $this->docId,
            ]);

            $this->host->updateLastChange();
            $result = $nodeParentId;
        }

        return $result;
    }
    // }}}
    // {{{ copyNode
    /**
     * copy node in database
     *
     * @param $nodeId (int) db-id of node
     * @param $targetId (int) db-id of target node
     * @param $targetIndex (int) index the copy gets in the child list of the
     *        target node, -1 appends it
     *
     * @return $saveId (int) db-id of the copy
     */
    public function copyNode($nodeId, $targetId, $targetIndex)
    {
        $xmlDoc = $this->host->getSubdocByNodeId($nodeId, false);
        $rootNode = $xmlDoc;
        $saveId = $this->saveNodeIn($rootNode, $targetId, $targetIndex, true);

        return $saveId;
    }
    // }}}
    // {{{ saveAttributes
    /**
     * writes the attribute string of a node
     *
     * @param $nodeId (int) db-id of node to set attribute
     * @param $attributes (array) array of attribute values
     */
    public function saveAttributes($nodeId, $attributes)
    {
        $query = $this->pdo->prepare(
            "UPDATE {$this->table} AS xml
            SET xml.value = :attr_str
            WHERE xml.id = :node_id AND xml.id_doc = :doc_id",
        );
        $success = $query->execute([
            'node_id' => $nodeId,
            'attr_str' => NodeContent::attributeString($attributes, $this->dbNs, $this->idAttribute),
            'doc_id' => $this->docId,
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
        $nodeArray = [];
        $this->getNodeArrayForSaving($nodeArray, $node);
        $rootId = $nodeArray[0]['id'];

        if (is_null($rootId)) {
            $targetId = null;
            $targetIndex = 0;
        } else {
            // set target_id/pos/doc
            $targetId = $this->host->getParentIdById($rootId);
            $targetIndex = $this->positions->indexById($rootId);

            if ($targetId === false) {
                $targetId = null;
            }
            if ($targetIndex === false) {
                $targetIndex = null;
            }

            // delete old node
            $this->deleteNode($rootId);
        }

        return $this->saveNodeArray($nodeArray, $targetId, $targetIndex, true);
    }
    // }}}
    // {{{ saveNodeIn
    /**
     * saves a node into the child list of a target node
     *
     * @param $node (domxmlnode) node to save
     * @param $targetId (int) db-id of the node to save the node in
     * @param $targetIndex (int) index the node gets in the child list of
     *        $targetId, -1 appends it
     * @param $incChildren (bool) whether to save the child nodes too
     *
     * @return (int) db-id the node was saved under
     */
    public function saveNodeIn($node, $targetId, $targetIndex, $incChildren)
    {
        $parentId = $this->host->getParentIdById($targetId);

        // delete child nodes, if target is document
        if ($parentId === false) {
            $this->pdo->exec('SET foreign_key_checks = 0;');

            try {
                $query = $this->pdo->prepare(
                    "DELETE
                    FROM {$this->table}
                    WHERE id_doc = :doc_id",
                );
                $query->execute([
                    'doc_id' => $this->docId,
                ]);
            } finally {
                // foreign key checks are session scoped, keep them enabled
                // even if the delete failed
                $this->pdo->exec('SET foreign_key_checks = 1;');
            }
        }

        // saveNodeToDb turns this index into the position between the
        // neighbours it is inserted at
        $targetIndex = $this->positions->normalizeIndex($targetId, $targetIndex);

        // get all nodes in array
        $nodeArray = [];
        $this->getNodeArrayForSaving($nodeArray, $node);

        return $this->saveNodeArray($nodeArray, $targetId, $targetIndex, $incChildren);
    }
    // }}}
    // {{{ saveNodeArray
    /**
     * writes a flattened node tree; the ids the nodes carry are reused
     * when they are free, new nodes are handed out free ids of the
     * preferred range
     */
    public function saveNodeArray($nodeArray, $targetId, $targetIndex, $incChildren)
    {
        $ids = [];

        foreach ($nodeArray as $node) {
            if ($node['id']) {
                $ids[] = $node['id'];
            }
        }

        $free = $this->getFreeNodeIds($ids);

        foreach ($nodeArray as $i => $node) {
            if (!is_null($node['id'])) {
                $index = array_search($node['id'], $free);

                if ($index === false) {
                    $nodeArray[$i]['id'] = null;
                } else {
                    array_splice($free, $index, 1);
                }
            }
        }

        foreach ($nodeArray as $i => $node) {
            if (is_null($node['id'])) {
                $nodeArray[$i]['id'] = array_shift($free);
            }
        }

        // save root node
        $nodeArray[0]['id'] = $this->saveNodeToDb($nodeArray[0]['node'], $nodeArray[0]['id'], $targetId, $targetIndex, true);
        $this->insertNodeQuery = null;

        if ($incChildren) {
            // save element nodes
            $this->saveNodesByType($nodeArray, true);
            // save other nodes
            $this->saveNodesByType($nodeArray, false);
        }

        $this->host->updateLastChange();

        return $nodeArray[0]['id'];
    }
    // }}}
    // {{{ saveNodesByType
    /**
     * writes all nodes of one type (element nodes first, the other node
     * types after them) level by level
     */
    public function saveNodesByType(&$nodeArray, $xmlElementNode)
    {
        $batch = new NodeBatch($this->pdo, $this->table, (int) $this->docId, $this->host::SAVE_BATCH_SIZE);

        // writing the tree level by level makes sure that every node's
        // parent has been saved (and its auto increment id read back)
        // before the node itself is written; within one level the
        // parents are always already resolved, so no batch has to be
        // interrupted
        $depths = [0];
        $maxDepth = 0;
        foreach ($nodeArray as $i => $node) {
            if ($i == 0) {
                continue;
            }
            $depths[$i] = $depths[$node['parent_index']] + 1;
            $maxDepth = max($maxDepth, $depths[$i]);
        }

        for ($level = 1; $level <= $maxDepth; $level++) {
            foreach ($nodeArray as $i => $node) {
                if (
                    $depths[$i] != $level
                    || (($node['node']->nodeType == XML_ELEMENT_NODE) != $xmlElementNode)
                ) {
                    continue;
                }

                $parentId = $nodeArray[$node['parent_index']]['id'];

                [$type, $name, $value] = NodeContent::fromDomNode($node['node'], $this->dbNs, $this->idAttribute);
                $value = \Normalizer::normalize($value);

                $batch->add($i, $node['id'], $parentId, ($node['pos'] + 1) * Positions::POS_GAP, $name, $value, $type);

                if ($batch->isFull()) {
                    $this->setResolvedNodeIds($batch->write(), $nodeArray);
                }
            }

            // the auto increment ids of this level have to be known
            // before its children can reference them
            $this->setResolvedNodeIds($batch->write(), $nodeArray);
        }

        $this->setResolvedNodeIds($batch->write(), $nodeArray);

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
     * @param $resolvedIds (array) database ids keyed by the index of the
     *        node in $nodeArray
     * @param $nodeArray (array) nodes to save, as built by
     *             getNodeArrayForSaving
     */
    protected function setResolvedNodeIds($resolvedIds, &$nodeArray)
    {
        foreach ($resolvedIds as $index => $id) {
            $nodeArray[$index]['id'] = $id;

            if ($nodeArray[$index]['node']->nodeType == XML_ELEMENT_NODE) {
                $nodeArray[$index]['node']->setAttributeNS(
                    $this->dbNs->uri,
                    $this->dbNs->ns . ':' . $this->idAttribute,
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
     * @param $nodeArray (array) list of nodes to add current node to
     * @param $node (domxmlnode) current node
     * @param $parentIndex (int) index of parent node in created node list
     * @param $pos (int) position of current node
     * @param $stripwhitespace (bool) whether to strip whitespace from
     *           textnodes while saving
     */
    public function getNodeArrayForSaving(&$nodeArray, $node, $parentIndex = null, $pos = 0, $stripwhitespace = true)
    {
        if (!$node) {
            return;
        }
        $type = $node->nodeType;

        if ($type === XML_DOCUMENT_NODE) {
            $rootNode = $node->documentElement;
            $this->getNodeArrayForSaving($nodeArray, $rootNode, $parentIndex, $pos, $stripwhitespace);
        } elseif ($type === XML_ELEMENT_NODE) {
            $id = NodeContent::dbIdOf($node, $this->dbNs, $this->idAttribute);
            $nodeArray[] = [
                'id' => $id,
                'id_old' => $id,
                'parent_index' => $parentIndex,
                'pos' => $pos,
                'node' => $node,
            ];

            $parentIndex = count($nodeArray) - 1;
            $nodeName = (($node->prefix != '') ? $node->prefix . ':' : '') . $node->localName;

            $preserveWhitespace = $this->host->getDoctypeHandler()->getPreserveWhitespace();

            if (
                !$stripwhitespace
                || in_array($nodeName, $preserveWhitespace)
            ) {
                $stripwhitespace = false;
            }

            $tmpNode = $node->firstChild;
            $i = 0;
            while ($tmpNode != null) {
                if (
                    $tmpNode->nodeType != XML_TEXT_NODE
                    || (
                        !$stripwhitespace
                        || trim($tmpNode->textContent) != ''
                    )
                ) {
                    $this->getNodeArrayForSaving($nodeArray, $tmpNode, $parentIndex, $i, $stripwhitespace);
                    $i++;
                }
                $tmpNode = $tmpNode->nextSibling;
            }
        } else {
            // is *_NODE
            $nodeArray[] = [
                'id' => null,
                'id_old' => null,
                'parent_index' => $parentIndex,
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
     * @param    $targetId (int) db-id of parent node
     * @param    $targetIndex (int) index the node gets in the child list of
     *                     $targetId, -1 appends it
     * @param    $setPosition (bool) whether to give the node a position in
     *                     the child list of the target node; without it the
     *                     node is stored with $targetIndex as position
     *
     * @return    $id (int) db-id under which node has been saved
     */
    public function saveNodeToDb($node, $id, $targetId, $targetIndex, $setPosition = false)
    {
        if (is_null($this->insertNodeQuery)) {
            $this->insertNodeQuery = $this->pdo->prepare(
                "INSERT {$this->table}
                SET
                    id = :id_query,
                    id_parent = :target_id,
                    id_doc = :doc_id,
                    pos = :pos,
                    name = :name,
                    value = :value,
                    type = :type
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            );
        }

        if (is_null($id) || !is_numeric($id)) {
            $idQuery = null;
        } else {
            $idQuery = (int) $id;
        }

        if ($targetId !== null && $setPosition) {
            // sparse positioning: the node gets a position between its
            // future neighbours, the other children keep theirs; this is
            // done for every node type, a text node stored with the plain
            // index would sort in front of the sparse positions
            $targetIndex = $this->positions->positionForIndex($targetId, $targetIndex);
        }

        // same tupel NodeContent calculates the subhashes from
        [$nodeType, $nodeName, $nodeData] = NodeContent::fromDomNode($node, $this->dbNs, $this->idAttribute);
        $nodeData = \Normalizer::normalize($nodeData);

        $this->insertNodeQuery->execute([
            'id_query' => $idQuery,
            'target_id' => $targetId,
            'pos' => $targetIndex,
            'name' => $nodeName,
            'value' => $nodeData,
            'type' => $nodeType,
            'doc_id' => $this->docId,
        ]);

        if (is_null($id)) {
            $id = $this->pdo->lastInsertId();
        }

        if ($node->nodeType == XML_ELEMENT_NODE) {
            $node->setAttributeNS($this->dbNs->uri, $this->dbNs->ns . ':' . $this->idAttribute, $id);
        }

        return $id;
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
