<?php

/**
 * @file    modules/xmlDb/Document.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2011 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb;

class Document implements Interfaces\DocumentWriterHost
{
    // {{{ constants
    /**
     * distance between the sparse positions of sibling nodes; inserting a
     * node between two neighbours takes the midpoint of their positions,
     * the child list is rebalanced once the space between two neighbours
     * drops below POS_MIN_GAP (the position management itself is done by
     * Helpers\Positions)
     */
    public const POS_GAP = Helpers\Positions::POS_GAP;
    public const POS_MIN_GAP = Helpers\Positions::POS_MIN_GAP;

    /**
     * savePatch() falls back to a full save() and saveNodePatch() to a
     * full saveNode() when more than this ratio of the nodes of the
     * patched subtree changed; below that limit patching writes fewer
     * rows than deleting and reinserting the tree would
     */
    public const PATCH_MAX_CHANGE_RATIO = Patch\PatchPlanner::MAX_CHANGE_RATIO;
    // }}}

    // {{{ variables
    protected $pdo;
    protected $cache;
    protected $dbNs;

    protected $tablePrefix;
    protected $tableDocs;
    protected $tableXml;

    protected $xmlDb;
    protected $docId;

    protected $idAttribute = 'id';
    protected $idDataAttribute = 'dataid';

    protected $freeElementIds = [];
    protected $doctypeHandlers = [];

    protected $entities = '';
    protected $namespaceString = '';
    protected $namespaces = [];
    protected $lastchange = '';
    protected $lastchangeUid = '';

    /**
     * memoized result of getDocInfo(); every altering save updates the
     * document row (and the history may change the release state), so the
     * memo is dropped in updateLastChange, endTransaction and
     * rollBackTransaction
     */
    protected $docInfo = null;
    protected $docInfoLoaded = false;

    /**
     * lazily built collaborators: the sparse position management, shared
     * by the read (index), save, move and patch code paths, and the
     * subhash updater
     */
    protected $positions = null;
    protected $subhashUpdater = null;
    protected $patchPlanner = null;
    protected $patchApplier = null;
    protected $writer = null;

    /**
     * set by updateLastChange, checked in endTransaction: the subhashes
     * of the document are recalculated before the outermost altering
     * transaction is committed
     */
    protected $subhashesDirty = false;

    /**
     * statistics about the changes applied by the last savePatch() or
     * saveNodePatch() call, null before the first incremental save
     */
    protected $lastPatchStats = null;
    // }}}
    // {{{ constructor
    /**
     * Construct
     *
     * @param $xmlDb
     * @param $docId
     */
    public function __construct($xmlDb, $docId)
    {
        $this->pdo = $xmlDb->pdo;
        $this->cache = $xmlDb->cache;

        $this->dbNs = new XmlNs('db', 'http://cms.depagecms.net/ns/database');

        $this->xmlDb = $xmlDb;

        $this->tablePrefix = $xmlDb->tablePrefix;
        $this->tableDocs = $xmlDb->tableDocs;
        $this->tableXml = $xmlDb->tableXml;

        $this->docId = $docId;
    }
    // }}}

    // {{{ getDocId
    /**
     * Get Doc ID
     *
     * @return int
     */
    public function getDocId()
    {
        return $this->docId;
    }
    // }}}
    // {{{ getXml
    /**
     * getXml
     *
     * @return string
     */
    public function getXml($addIdAttribute = true)
    {
        $rootId = $this->getDocInfo()->rootid;

        if (is_null($rootId)) {
            throw new Exceptions\XmlDbException('Trying to get contents of empty document.');
        } else {
            $xml = $this->getSubdocByNodeId($rootId, $addIdAttribute);
        }

        return $xml;
    }
    // }}}
    // {{{ getDocInfo
    /**
     * gets info about a document by doc_id
     *
     * the result is memoized for the lifetime of this document instance
     * and reloaded whenever the document or its history changed; the
     * returned object is shared between the calls of one generation, so
     * callers must not alter it
     *
     * @return $info (object|false)
     */
    public function getDocInfo()
    {
        if (!$this->docInfoLoaded) {
            $query = $this->pdo->prepare(
                "SELECT
                    docs.id AS id,
                    docs.name AS name,
                    docs.rootid AS rootid,
                    docs.type AS type,
                    docs.ns AS namespaces,
                    docs.lastchange AS lastchange,
                    docs.lastchange_uid AS lastchangeUid
                FROM {$this->tableDocs} AS docs
                WHERE docs.id = :doc_id
                LIMIT 1",
            );
            $query->execute([
                'doc_id' => $this->docId,
            ]);

            $info = $query->fetchObject();

            if ($info) {
                $info->lastchange = new \DateTime($info->lastchange);
                $info->lastrelease = $this->getHistory()->getLatestVersion()->lastsaved ?? false;
            }

            $this->docInfo = $info;
            $this->docInfoLoaded = true;
        }

        return $this->docInfo;
    }
    // }}}
    // {{{ getDoctypeHandler
    /**
     * @param $docId
     *
     * @return mixed
     */
    public function getDoctypeHandler()
    {
        if (!isset($this->doctypeHandlers[$this->docId])) {
            $className = $this->getDocInfo()->type;

            if (empty($className)) {
                $handler = new XmlDoctypes\Base($this->xmlDb, $this);
            } else {
                // don't instantiate handlers straight from the database
                // before validating them
                $className = '\\' . ltrim($className, '\\');

                if (!class_exists($className)) {
                    throw new Exceptions\XmlDbException("Doctype handler class \"$className\" does not exist");
                }
                if (!is_a($className, Interfaces\DoctypeInterface::class, true)) {
                    throw new Exceptions\XmlDbException('Doctype handler must implement DoctypeInterface');
                }

                $handler = new $className($this->xmlDb, $this);
            }

            if ($handler instanceof Interfaces\DoctypeInterface) {
                $this->doctypeHandlers[$this->docId] = $handler;
            } else {
                throw new Exceptions\XmlDbException('Doctype handler must implement DoctypeInterface');
            }
        }

        return $this->doctypeHandlers[$this->docId];
    }
    // }}}
    // {{{ getPermissions
    /**
     * @return mixed
     */
    public function getPermissions()
    {
        return $this->getDoctypeHandler()->getPermissions();
    }
    // }}}
    // {{{ getNamespacesAndEntities
    /**
     * gets the namespaces and the entities of the document
     *
     * @return object|false object with the properties "namespaces" and
     *         "entities" or false if the document does not exist
     */
    public function getNamespacesAndEntities()
    {
        $query = $this->pdo->prepare(
            "SELECT docs.entities AS entities, docs.ns AS namespaces
            FROM {$this->tableDocs} AS docs
            WHERE docs.id = :doc_id",
        );
        $query->execute([
            'doc_id' => $this->docId,
        ]);

        return $query->fetchObject();
    }
    // }}}
    // {{{ getHistory
    /**
     * getHistory
     *
     * @return history
     */
    public function getHistory()
    {
        return new DocumentHistory($this->pdo, $this->tablePrefix, $this);
    }
    // }}}
    // {{{ positions
    /**
     * the collaborator managing the sparse sibling positions of this
     * document's nodes
     *
     * @internal
     *
     * @return Helpers\Positions
     */
    protected function positions()
    {
        if (is_null($this->positions)) {
            $this->positions = new Helpers\Positions($this->pdo, $this->tableXml, $this->docId, self::SAVE_BATCH_SIZE);
        }

        return $this->positions;
    }
    // }}}
    // {{{ writer
    /**
     * the collaborator running the write pipeline (DOM trees to rows)
     * for this document; the transaction stays on the document and the
     * doctype handler, the node lookups and the read path are reached
     * through the Interfaces\DocumentWriterHost interface this class
     * implements
     *
     * @internal
     *
     * @return Helpers\DocumentWriter
     */
    protected function writer()
    {
        if (is_null($this->writer)) {
            $this->writer = new Helpers\DocumentWriter(
                pdo: $this->pdo,
                table: $this->tableXml,
                docId: $this->docId,
                dbNs: $this->dbNs,
                idAttribute: $this->idAttribute,
                positions: $this->positions(),
                host: $this
            );
        }

        return $this->writer;
    }
    // }}}
    // {{{ isReleased()
    /**
     * @brief isReleased
     *
     * @param mixed
     * @return void
     **/
    public function isReleased()
    {
        $info = $this->getDocInfo();

        if (!empty($info->lastrelease) && $info->lastchange->getTimestamp() <= $info->lastrelease->getTimestamp()) {
            return true;
        }

        return false;
    }
    // }}}
    // {{{ isPublished()
    /**
     * @brief isPublished
     *
     * @param mixed
     * @return void
     **/
    public function isPublished()
    {
        $info = $this->getDocInfo();

        return !empty($info->lastrelease);
    }
    // }}}

    // {{{ getNodeId
    /**
     * gets node db-id from db-id attribute
     *
     * @param    $node (domxmlnode) node to get id from
     *
     * @return    $dbId (int)
     */
    public function getNodeId($node)
    {
        return $node->nodeType == XML_ELEMENT_NODE
            ? $node->getAttributeNS($this->dbNs->uri, $this->idAttribute)
            : null;
    }
    // }}}
    // {{{ getNodeDataId
    /**
     * gets node db-dataid from db-dataid attribute
     *
     * @public
     *
     * @param    $node (domxmlnode) node to get id from
     *
     * @return    $dbId (int)
     */
    public function getNodeDataId($node)
    {
        return $node->nodeType == XML_ELEMENT_NODE
            ? $node->getAttributeNS($this->dbNs->uri, $this->idDataAttribute)
            : null;
    }
    // }}}

    // {{{ getNodeAttributeById
    protected function getNodeAttributeById($id, $attribute)
    {
        $query = $this->pdo->prepare(
            "SELECT xml.$attribute AS $attribute
            FROM {$this->tableXml} AS xml
            WHERE xml.id = :id AND xml.id_doc = :doc_id",
        );
        $query->execute([
            'id' => $id,
            'doc_id' => $this->docId,
        ]);

        return ($result = $query->fetchObject()) ? $result->$attribute : false;
    }
    // }}}
    // {{{ getNodeNameById
    /**
     * gets node_name by node db-id
     *
     * @param $id (int) node db-id
     *
     * @return $nodeName (string) name of node, false if node doesn't exist.
     */
    public function getNodeNameById($id)
    {
        return $this->getNodeAttributeById($id, 'name');
    }
    // }}}
    // {{{ getParentIdById
    /**
     * gets parent db-id by one of its child_nodes-id
     *
     * @param $id (int) node db-id
     *
     * @return $parentId (id) db-id of parent node, false, if node doesn't exist.
     */
    public function getParentIdById($id)
    {
        return $this->getNodeAttributeById($id, 'id_parent');
    }
    // }}}
    // {{{ getIndexById
    /**
     * gets the index of a node in the child list of its parent node
     * (number of siblings before it); this is the index the targetIndex of
     * addNode, moveNode and copyNode refers to, so
     *
     *   $doc->addNode($node, $parentId, $doc->getIndexById($siblingId));
     *
     * adds a node in front of one of its siblings (moveNodeBefore() and
     * moveNodeAfter() resolve that index for a node that already sits in
     * the child list)
     *
     * @param $id (int) node db-id
     *
     * @return $index (int) index in parents childlist, false if node doesn't exist.
     */
    public function getIndexById($id)
    {
        return $this->positions()->indexById($id);
    }
    // }}}
    // {{{ rebalancePositions
    /**
     * renumbers the children of a parent node to have an even distance of
     * self::POS_GAP between their positions; called when the sparse
     * positions of a child list are exhausted
     *
     * @param $parentId (int) db-id of parent node
     */
    protected function rebalancePositions($parentId, $order = null)
    {
        $this->positions()->rebalance($parentId, $order);
    }
    // }}}

    // {{{ getNodeIdsByXpath
    /**
     * gets node_ids by xpath
     *
     * @attention this supports only a small subset of xpath-queries. so recheck source before using.
     *
     * @param $this->docId (int) id of document
     * @param $xpath (string) xpath to target node
     *
     * @return $nodeids (array) array of found node ids
     *
     * @todo implement full xpath specifications
     */
    public function getNodeIdsByXpath($xpath)
    {
        $identifier = Helpers\CacheKey::forDocument($this->tableDocs, $this->docId) . 'xpath_' . sha1($xpath);
        $fetchedIds = $this->cache->get($identifier);

        if ($fetchedIds === false) {
            $fetchedIds = $this->xmlDb->getNodeIdsByXpath($xpath, $this->docId);

            $this->cache->set($identifier, $fetchedIds);
        }
        return $fetchedIds;
    }
    // }}}
    // {{{ getSubdocByNodeId
    /**
     * gets an xml-document-object from specific db-id
     *
     * @param $id (int) db-id of node to get
     * @param $addIdAttribute (bool) true, if you want to add the db-id
     *        attributes to xml-definition, false to remove them.
     */
    public function getSubdocByNodeId($id, $addIdAttribute = true)
    {
        $identifier = Helpers\CacheKey::forDocument($this->tableDocs, $this->docId) . "{$id}.xml";
        $xmlDoc = new \Depage\Xml\Document();

        if ($xmlStr = $this->cache->get($identifier)) {
            // read from cache
            $xmlDoc->loadXML($xmlStr);
        } else {
            // read from database
            $this->inTransaction(function () use ($id, &$xmlDoc) {
                $query = null;

                try {
                    $query = $this->pdo->prepare(
                        "SELECT
                            docs.entities AS entities,
                            docs.ns AS namespaces,
                            docs.lastchange AS lastchange,
                            docs.lastchange_uid AS lastchangeUid
                        FROM {$this->tableDocs} AS docs
                        WHERE docs.id = :doc_id",
                    );
                    $query->execute([
                        'doc_id' => $this->docId,
                    ]);
                    $result = $query->fetchObject();

                    $this->entities = $result->entities;
                    $this->namespaceString = $result->namespaces;
                    $this->namespaces = $this->extractNamespaces($this->namespaceString);
                    $this->namespaces[$this->dbNs->ns] = $this->dbNs;
                    $this->lastchange = $result->lastchange;
                    $this->lastchangeUid = $result->lastchangeUid;

                    $query = $this->pdo->prepare(
                        Helpers\TreeSql::descendants($this->tableXml, "id = :id AND id_doc = :doc_id", self::SORTKEY_PAD) . "
                        SELECT lvl, xml.* FROM tree JOIN {$this->tableXml} AS xml ON tree.id = xml.id ORDER BY sortkey;",
                        \Depage\Db\Pdo::unbuffered(),
                    );
                    $query->execute([
                        'doc_id' => $this->docId,
                        'id' => $id,
                    ]);
                    $row = $query->fetchObject();

                    //get ROOT-NODE
                    if (!($row && $row->type == 'ELEMENT_NODE')) {
                        throw new Exceptions\XmlDbException('This node is no ELEMENT_NODE or node does not exist' . " {$this->docId}/{$id}");
                    }

                    $this->addChildnodesByQuery($query, $row, $xmlDoc);
                } catch (\Throwable $e) {
                    // the tree query may be an unbuffered result, it has to be
                    // closed before any other statement can run on the connection
                    if (isset($query)) {
                        $query->closeCursor();
                    }

                    throw $e;
                }
            }, false);

            $dth = $this->getDoctypeHandler();
            if ($dth->testDocument($xmlDoc)) { // test whether the document was altered
                $this->saveNode($xmlDoc);
            }

            // add xml to xml-cache
            if (is_object($xmlDoc) && $xmlDoc->documentElement != null) {
                $this->cache->set($identifier, $xmlDoc->saveXML());
            }
        }

        if (is_a($xmlDoc, 'DOMDocument') && $xmlDoc->documentElement != null && !$addIdAttribute) {
            $this->removeIdAttr($xmlDoc);
        }

        return $xmlDoc;
    }
    // }}}
    // {{{ getSubdocByXpath
    /**
     * gets document by xpath. if xpath directs to more than
     * one node, only the first node will be returned.
     *
     * @param    $xpath (string) xpath to target node
     * @param    $addIdAttribute (bool) whether to add db:id attribute or not
     *
     * @return    $doc (domxmlobject)
     */
    public function getSubdocByXpath($xpath, $addIdAttribute = true)
    {
        $subdoc = false;
        $ids = $this->getNodeIdsByXpath($xpath);

        if (isset($ids[0])) {
            $subdoc = $this->getSubdocByNodeId($ids[0], $addIdAttribute);
        }

        return $subdoc;
    }
    // }}}

    // {{{ getAttribute
    /**
     * gets attribute of node
     *
     * @param    $nodeId (int) db-id of node
     * @param    $attrName (string) name of attribute
     *
     * @return    $val (string) value
     */
    public function getAttribute($nodeId, $attrName)
    {
        $attributes = $this->getAttributes($nodeId);

        return (isset($attributes[$attrName])) ? $attributes[$attrName] : false;
    }
    // }}}
    // {{{ getAttributes
    /**
     * gets all attributes of a node by id
     *
     * @param    $nodeId (int) db-id of node
     *
     * @return    $attributes (array) array of attributes
     */
    public function getAttributes($nodeId)
    {
        $attributes = [];

        $query = $this->pdo->prepare(
            "SELECT xml.value
            FROM {$this->tableXml} AS xml
            WHERE xml.id = :node_id AND xml.type='ELEMENT_NODE' AND xml.id_doc = :doc_id",
        );
        $query->execute([
            'node_id' => $nodeId,
            'doc_id' => $this->docId,
        ]);

        if ($result = $query->fetchObject()) {
            $attributes = self::parseAttributeString($result->value);
        }

        return $attributes;
    }
    // }}}
    // {{{ parseAttributeString
    /**
     * parses a stored attribute string into a name => value map; used by
     * getAttributes and by the batch value resolution of the Indexer
     */
    public static function parseAttributeString($value)
    {
        $attributes = [];

        $matches = preg_split('/(="|"$|" )/', (string) $value);
        $matches = array_chunk($matches, 2);
        foreach ($matches as $match) {
            $name = trim($match[0]);
            if ($name != '') {
                $attributes[$name] = htmlspecialchars_decode($match[1] ?? '');
            }
        }

        return $attributes;
    }
    // }}}

    // {{{ buildNode
    /**
     * @param $name
     * @param $attributes
     *
     * @return \DOMElement
     */
    public function buildNode($name, $attributes)
    {
        //@todo dont build node directly but get from templates according to document type
        $docInfo = $this->getNamespacesAndEntities($this->docId);
        $xml = "<$name {$docInfo->namespaces}";
        foreach ($attributes as $attr => $value) {
            $xml .= " $attr=\"$value\"";
        }
        $xml .= '/>';

        $doc = new \Depage\Xml\Document();
        $doc->loadXML($xml);

        return $doc->documentElement;
    }
    // }}}
    // {{{ removeIdAttr
    /**
     * recursively remove all db-id attributes from node
     *
     * @param    $node (domxmlnode) node to remove attribute from
     */
    public function removeIdAttr($node)
    {
        self::removeNodeAttr($node, $this->dbNs, $this->idAttribute);
    }
    // }}}

    // {{{ clearDoc
    /**
     * clean all nodes inside of document
     *
     * @return $doc (array)
     */
    public function clearDoc()
    {
        $info = $this->getDocInfo();

        $this->inTransaction(function () use ($info) {
            if ($info) {
                $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0;");

                try {
                    $query = $this->pdo->prepare(
                        "DELETE FROM {$this->tableXml}
                        WHERE
                            id_doc = :id",
                    );
                    $query->execute([
                        'id' => $info->id,
                    ]);
                } finally {
                    // keep foreign key checks enabled even if the
                    // delete failed, they are session scoped
                    $this->pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
                }
            }
        });

        return $info;
    }
    // }}}
    // {{{ save
    /**
     * @param $xml
     * @return mixed
     * @throws xmlDbException
     */
    public function save(\DomDocument $xml)
    {
        return $this->inTransaction(function () use ($xml) {
            $docInfo = $this->clearDoc();

            $namespaces = $this->getNamespaceString($xml);

            // @TODO get document and entities or set html_entities as standard as long as php does not inherit the entites() function
            $docInfo->rootid = $this->saveNodePrivate($xml);
            $query = $this->pdo->prepare(
                "UPDATE {$this->tableDocs}
                SET
                    rootid = :rootid,
                    ns = :ns,
                    entities=''
                WHERE id = :doc_id",
            );

            $query->execute([
                'rootid' => $docInfo->rootid,
                'ns' => $namespaces,
                'doc_id' => $docInfo->id,
            ]);

            return $docInfo->id;
        });
    }
    // }}}

    // {{{ savePatch
    /**
     * saves a document reusing the parts of the tree that did not change;
     * subtrees whose merkle subhash equals the subhash stored in the
     * database are skipped without looking at their content, only changed
     * nodes are updated and only added or removed subtrees are inserted
     * or deleted.
     *
     * the document is expected to contain the database ids of the current
     * document (like the result of getXml()); elements without a db:id
     * are matched against the leftover children of their parent by
     * position.
     *
     * a full save() is done instead when the root node was replaced, when
     * the document carries no database ids or when more than
     * PATCH_MAX_CHANGE_RATIO of the nodes changed.
     *
     * @see saveNodePatch() for incrementally saving a subtree
     *
     * @public
     *
     * @param    $xml (DomDocument) xml document to save
     *
     * @returns $id (int) id of the document
     */
    public function savePatch(\DomDocument $xml)
    {
        $docInfo = $this->getDocInfo();
        $plan = null;

        if ($docInfo) {
            $plan = $this->planPatch($xml, $docInfo);
        }

        if (is_null($plan)) {
            $this->lastPatchStats = ['fallback' => true];

            return $this->save($xml);
        }

        $this->inTransaction(function () use ($plan, $xml) {
            $query = $this->pdo->prepare(
                "UPDATE {$this->tableDocs}
                SET
                    ns = :ns,
                    entities = ''
                WHERE id = :doc_id",
            );
            $query->execute([
                'ns' => $this->getNamespaceString($xml),
                'doc_id' => $this->docId,
            ]);

            $this->applyPatch($plan);

            $this->updateLastChange();
        });

        $this->lastPatchStats = $plan['stats'] + ['fallback' => false];

        return $docInfo->id;
    }
    // }}}
    // {{{ getLastPatchStats
    /**
     * number of nodes that could be skipped/updated/deleted/inserted by
     * the last savePatch()/saveNodePatch() call, ['fallback' => true]
     * when it fell back to a full save()/saveNode(); the counts of a
     * saveNodePatch() cover the patched subtree, not the whole document
     */
    public function getLastPatchStats()
    {
        return $this->lastPatchStats;
    }
    // }}}
    // {{{ planPatch
    /**
     * compares the nodes of $xml with the nodes stored in the database
     * and collects the minimal set of changes into a plan for
     * applyPatch(); returns null when the document can not be patched
     * (replaced root, missing database ids or too many changes)
     */
    protected function planPatch(\DomDocument $xml, $docInfo)
    {
        $root = $xml->documentElement;
        $rootId = is_null($root) ? '' : $this->getNodeId($root);

        if (empty($rootId) || (int) $rootId !== (int) $docInfo->rootid) {
            // new document or the root node was replaced
            return null;
        }

        return $this->planNodePatch($xml);
    }
    // }}}
    // {{{ planNodePatch
    /**
     * compares the subtree rooted at $node with the nodes stored under
     * the db:id its root element carries and collects the minimal set of
     * changes into a plan for applyPatch(); returns null when the subtree
     * can not be patched (replaced root, missing database id or too many
     * changes)
     *
     * $checkRoot rejects the plan (instead of renaming the row in place)
     * when the root node is not compatible with the row its id points
     * to, i.e. was replaced by a node of another type or name;
     * saveNodePatch() uses this to fall back to a full saveNode()
     */
    protected function planNodePatch($node, $checkRoot = false)
    {
        // run the subtree through the same pipeline save() uses, this
        // also applies the doctype whitespace handling
        $nodeArray = [];
        $this->getNodeArrayForSaving($nodeArray, $node);

        if (is_null($this->patchPlanner)) {
            $this->patchPlanner = new Patch\PatchPlanner($this->pdo, $this->tableXml, $this->docId, $this->dbNs, $this->idAttribute);
        }

        return $this->patchPlanner->plan($nodeArray, $checkRoot);
    }
    // }}}
    // {{{ applyPatch
    /**
     * executes the change plan of planPatch(), all nodes that were not
     * touched by the plan keep their database row (and with it position
     * and subhash) untouched
     */
    protected function applyPatch($plan)
    {
        if (is_null($this->patchApplier)) {
            $this->patchApplier = new Patch\PatchApplier($this->pdo, $this->tableXml, $this->docId, $this->positions(), $this->writer());
        }

        $this->patchApplier->apply($plan);
    }
    // }}}
    // {{{ getNamespaceString
    /**
     * returns the namespace string that save() stores in the ns column of
     * the document for the given xml document
     */
    protected function getNamespaceString($xml)
    {
        $xpath = new \DOMXPath($xml);

        $namespaces = '';
        foreach ($xpath->query('namespace::*') as $node) {
            if ($node->nodeName == 'xmlns:xml') {
                continue;
            }
            $namespaces .= " " . $node->nodeName . "=\"" . htmlspecialchars($node->nodeValue) . "\"";
        }

        return $namespaces;
    }
    // }}}

    // {{{ deleteNode
    /**
     * @param $nodeId
     *
     * @return bool
     */
    public function deleteNode($nodeId)
    {
        $dth = $this->getDoctypeHandler();

        if (!$dth->isAllowedDelete($nodeId)) {
            return false;
        }

        return $this->inTransaction(function () use ($nodeId) {
            $success = $this->writer()->deleteNode($nodeId);

            $this->updateLastChange();

            return $success;
        });
    }
    // }}}
    // {{{ saveNode
    /**
     * saves the subtree of a node; the subtree is deleted and written again,
     * the parent and the position of the node are kept
     *
     * @see saveNodePatch() for incrementally saving a subtree
     *
     * @param $node (DomNode) subtree to save
     *
     * @returns $id (int) id of the root node of the subtree
     */
    public function saveNode($node)
    {
        return $this->inTransaction(
            fn() => $this->saveNodePrivate($node)
        );
    }
    // }}}

    // {{{ saveNodePatch
    /**
     * saves a subtree of the document reusing the parts of the tree that
     * did not change; works like savePatch() below $node: subtrees whose
     * merkle subhash equals the subhash stored in the database are
     * skipped without looking at their content, only changed nodes are
     * updated and only added or removed subtrees are inserted or deleted.
     *
     * $node is expected to carry the database id of the node it is saved
     * over (like the result of getNode()); elements of the subtree
     * without a db:id are matched against the leftover children of their
     * parent by position.
     *
     * a full saveNode() is done instead when the root node carries no
     * database id, when it replaces the node its id points to or when
     * more than PATCH_MAX_CHANGE_RATIO of the nodes of the subtree
     * changed; parent and position of the subtree root are kept, as they
     * are by saveNode().
     *
     * @public
     *
     * @param    $node (DomNode) subtree to save
     *
     * @returns $id (int) id of the root node of the subtree
     */
    public function saveNodePatch($node)
    {
        $plan = $this->planNodePatch($node, true);

        if (is_null($plan)) {
            $this->lastPatchStats = ['fallback' => true];

            return (int) $this->saveNode($node);
        }

        $this->inTransaction(function () use ($plan) {
            $this->applyPatch($plan);

            $this->updateLastChange();
        });

        $this->lastPatchStats = $plan['stats'] + ['fallback' => false];

        return $plan['root_id'];
    }
    // }}}

    // {{{ addNode
    /**
     * adds a node to the child list of a node
     *
     * @param $node (\DomNode) node to add
     * @param $targetId (int) db-id of the node to add the node to
     * @param $targetIndex (int) index the node gets in the child list of
     *        $targetId, -1 appends it
     * @param $extras (array) additional data for the doctype handler
     *
     * @return (int|false) db-id of the added node, false if it was not added
     */
    public function addNode(\DomNode $node, $targetId, $targetIndex = -1, $extras = [])
    {
        $dth = $this->getDoctypeHandler();

        if (!$dth->isAllowedAdd($node, $targetId)) {
            return false;
        }

        $dth->onAddNode($node, $targetId, $targetIndex, $extras);

        return $this->inTransaction(
            fn() => $this->saveNodeIn($node, $targetId, $targetIndex, true)
        );
    }
    // }}}
    // {{{ addNodeByName
    /**
     * creates the node(s) the doctype handler defines for a name and adds
     * them to the child list of a node
     *
     * @param $name (string) name of the node to create
     * @param $targetId (int) db-id of the node to add the node to
     * @param $targetIndex (int) index the first new node gets in the child
     *        list of $targetId, the other ones are added behind it, -1
     *        appends all of them
     * @param $extras (array) additional data for the doctype handler
     *
     * @return (int|false) db-id of the last node that was added, false if
     *         the nodes were not added
     */
    public function addNodeByName($name, $targetId, $targetIndex, $extras = [])
    {
        $dth = $this->getDoctypeHandler();
        $newNodes = $dth->getNewNodeFor($name);

        if (!$newNodes) {
            return false;
        }

        // collect the element nodes the handler offers first: a node the
        // doctype handler does not allow must not leave the ones added
        // before it in the document
        $nodes = [];
        foreach ($newNodes as $node) {
            if ($node->nodeType != \XML_ELEMENT_NODE) {
                continue;
            }

            if (!$dth->isAllowedAdd($node, $targetId)) {
                return false;
            }

            $nodes[] = $node;
        }

        // the position of the first node is resolved once and the others
        // are added behind it; adding all of them at the same position
        // would add them in the reverse of the order the handler returns
        // them in. this is read before the adds open their own
        // transactions, the doctype handler hooks of addNode have to run
        // outside of a transaction
        $targetIndex = $this->positions()->normalizeIndex($targetId, $targetIndex);

        $success = false;
        foreach ($nodes as $index => $node) {
            $added = $this->addNode($node, $targetId, $targetIndex + $index, $extras);

            if ($added === false) {
                break;
            }

            $success = $added;
        }

        return $success;
    }
    // }}}

    // {{{ replaceNode
    /**
     * replaces a node in database
     *
     * @param    $node (domxmlnode) node to save
     * @param    $idToReplace (int) db-id of node to be replaced
     * @param    $this->docId (int) document db-id
     *
     * @return    $changedIds (array) list of db-ids that has been changed
     */
    public function replaceNode($node, $idToReplace)
    {
        return $this->inTransaction(function () use ($node, $idToReplace) {
            $targetId = $this->getParentIdById($idToReplace);
            $targetIndex = $this->getIndexById($idToReplace);

            $this->writer()->deleteNode($idToReplace);

            $changedIds = [];
            $changedIds[] = $this->saveNodeIn($node, $targetId, $targetIndex, true);
            $changedIds[] = $targetId;

            return $changedIds;
        });
    }
    // }}}
    // {{{ duplicateNode
    /**
     * duplicates node in database, and inserts it in the next position
     *
     * @TODO behaviour should differ according to tree type - don't usually want to copy all sub nodes
     *
     * @param $nodeId (int) db-id of node
     *
     * @return $success (bool)
     */
    public function duplicateNode($nodeId, $recursive = false)
    {
        // get parent and position for new node
        $targetId = $this->getParentIdById($nodeId);
        $targetIndex = $this->getIndexById($nodeId) + 1;
        $dth = $this->getDoctypeHandler();

        if (!$dth->isAllowedMove($nodeId, $targetId)) {
            return false;
        }

        $xmlDoc = $this->getSubdocByNodeId($nodeId, false);

        $this->clearCache();

        $copyId = $this->inTransaction(
            fn() => $this->saveNodeIn($xmlDoc, $targetId, $targetIndex, $recursive)
        );

        $dth->onCopyNode($nodeId, $copyId);

        return $copyId;
    }
    // }}}

    // {{{ moveNode
    /**
     * moves a node to a position in the child list of another node
     *
     * @param    $nodeId (int) db-id of node
     * @param    $targetId (int) db-id of target (parent) node
     * @param    $targetIndex (int) index the node gets in the child list of
     *           $targetId, -1 appends it
     *
     * @return   (int|false) id of the old parent node, false if the node
     *         was not moved
     */
    public function moveNode($nodeId, $targetId, $targetIndex)
    {
        return $this->moveNodeChecked($nodeId, $targetId, fn() => $targetIndex);
    }
    // }}}
    // {{{ moveNodeIn
    /**
     * moves node to another node (append child)
     *
     * @param    $nodeId (int) db-id of node
     * @param    $targetId (int) db-id of target node
     */
    public function moveNodeIn($nodeId, $targetId)
    {
        return $this->moveNodeChecked($nodeId, $targetId, fn() => -1);
    }
    // }}}
    // {{{ moveNodeBefore
    /**
     * moves node before another node (insert before)
     *
     * @param    $nodeId (int) db-id of node
     * @param    $targetId (int) db-id of target node
     */
    public function moveNodeBefore($nodeId, $targetId)
    {
        $targetParentId = $this->getParentIdById($targetId);

        return $this->moveNodeChecked(
            $nodeId,
            $targetParentId,
            fn() => $this->indexToMoveTo($nodeId, $targetId)
        );
    }
    // }}}
    // {{{ moveNodeAfter
    /**
     * moves node after another node
     *
     * @param    $nodeId (int) db-id of node
     * @param    $targetId (int) db-id of target node
     */
    public function moveNodeAfter($nodeId, $targetId)
    {
        $targetParentId = $this->getParentIdById($targetId);

        return $this->moveNodeChecked(
            $nodeId,
            $targetParentId,
            fn() => $this->indexToMoveTo($nodeId, $targetId) + 1
        );
    }
    // }}}
    // {{{ indexToMoveTo
    /**
     * resolves "in front of this sibling" (plus an offset) into the index
     * a node is moved to; the index moveNode counts is the one of the
     * child list without the node that is moved, so moving a node that
     * sits in front of the target shifts the target up by one
     *
     * @param    $nodeId (int) db-id of the node to move
     * @param    $targetId (int) db-id of the sibling to move to
     * @param    $offset (int) positions to move behind the sibling
     *
     * @return   (int) index to move the node to
     */
    private function indexToMoveTo($nodeId, $targetId, $offset = 0)
    {
        $targetIndex = (int) $this->getIndexById($targetId);
        $nodeIndex = $this->getIndexById($nodeId);

        if (
            $nodeIndex !== false
            && $this->getParentIdById($nodeId) == $this->getParentIdById($targetId)
            && $nodeIndex < $targetIndex
        ) {
            $targetIndex--;
        }

        return max(0, $targetIndex + $offset);
    }
    // }}}
    // {{{ moveNodeChecked
    /**
     * moves a node if the doctype handler allows it; the four moveNode*
     * variants differ only in how they compute the index to move to,
     * $getIndex resolves it inside the transaction
     *
     * @param    $nodeId (int) db-id of node
     * @param    $targetId (int) db-id of the target (parent) node
     * @param    $getIndex (callable) resolves the index to move to
     *
     * @return   (int|false) id of the old parent node, false if the node
     *         was not moved
     */
    protected function moveNodeChecked($nodeId, $targetId, callable $getIndex)
    {
        $dth = $this->getDoctypeHandler();

        if (
            $nodeId !== $targetId
            && $dth->isAllowedMove($nodeId, $targetId)
        ) {
            $parentId = $this->inTransaction(
                fn() => $this->writer()->moveNode($nodeId, $targetId, $getIndex())
            );

            $dth->onMoveNode($nodeId, $parentId);

            return $parentId;
        }

        return false;
    }
    // }}}

    // {{{ copyNode
    /**
     * copies a node to a position in the child list of another node
     *
     * @param    $nodeId (int) db-id of node
     * @param    $targetId (int) db-id of target (parent) node
     * @param    $targetIndex (int) index the copy gets in the child list of
     *           $targetId, -1 appends it
     *
     * @return   (int|false) id of the copy, false if the node was not copied
     */
    public function copyNode($nodeId, $targetId, $targetIndex)
    {
        return $this->copyNodeChecked($nodeId, $targetId, fn() => $targetIndex);
    }
    // }}}
    // {{{ copyNodeIn
    /**
     * copy node to another node
     *
     * @param    $nodeId (int) db-id of node
     * @param    $targetId (int) db-id of target node
     */
    public function copyNodeIn($nodeId, $targetId)
    {
        return $this->copyNodeChecked($nodeId, $targetId, fn() => -1);
    }
    // }}}
    // {{{ copyNodeBefore
    /**
     * copy node before another node
     *
     * (the node that is copied stays where it is, so the index of the copy
     * is the plain index of the target node, it is not shifted by the move
     * like it is in moveNodeBefore)
     *
     * @param    $nodeId (int) db-id of node
     * @param    $targetId (int) db-id of target node
     */
    public function copyNodeBefore($nodeId, $targetId)
    {
        return $this->copyNodeChecked(
            $nodeId,
            $this->getParentIdById($targetId),
            fn() => $this->getIndexById($targetId)
        );
    }
    // }}}
    // {{{ copyNodeAfter
    /**
     * copy node after another node
     *
     * @param    $nodeId (int) db-id of node
     * @param    $targetId (int) db-id of target node
     */
    public function copyNodeAfter($nodeId, $targetId)
    {
        return $this->copyNodeChecked(
            $nodeId,
            $this->getParentIdById($targetId),
            fn() => $this->getIndexById($targetId) + 1
        );
    }
    // }}}
    // {{{ copyNodeChecked
    /**
     * copies a node if the doctype handler allows it; the four copyNode*
     * variants differ only in how they compute the index to copy to,
     * $getIndex resolves it inside the transaction
     *
     * @param    $nodeId (int) db-id of node
     * @param    $targetId (int) db-id of the target (parent) node
     * @param    $getIndex (callable) resolves the index to copy to
     *
     * @return   (int|false) id of the copy, false if it was not made
     */
    protected function copyNodeChecked($nodeId, $targetId, callable $getIndex)
    {
        $dth = $this->getDoctypeHandler();

        if ($dth->isAllowedCopy($nodeId, $targetId)) {
            $copyId = $this->inTransaction(
                fn() => $this->writer()->copyNode($nodeId, $targetId, $getIndex())
            );

            $dth->onCopyNode($nodeId, $copyId);

            return $copyId;
        }

        return false;
    }
    // }}}

    // {{{ setAttribute
    /**
     * sets attribute of node
     *
     * @param    $nodeId (int) db-id of node to set attribute
     * @param    $attrName (string) name of attribute
     * @param    $attrValue (string) new value of attribute
     */
    public function setAttribute($nodeId, $attrName, $attrValue)
    {
        return $this->inTransaction(function () use ($nodeId, $attrName, $attrValue) {
            $attributes = $this->getAttributes($nodeId);

            if (
                (
                    isset($attributes[$attrName])
                    && $attributes[$attrName] !== htmlspecialchars($attrValue)
                )
                || !isset($attributes[$attrName])
            ) {
                $dth = $this->getDoctypeHandler();

                $dth->onSetAttribute($nodeId, $attrName, $attributes[$attrName] ?? null, $attrValue);

                $attributes[$attrName] = $attrValue;

                return $this->writer()->saveAttributes($nodeId, $attributes);
            }

            return false;
        });
    }
    // }}}
    // {{{ removeAttribute
    /**
     * removes an attribute of a node
     *
     * @param    $nodeId (int) db-id of node to set attribute
     * @param    $attrName (string) name of attribute
     */
    public function removeAttribute($nodeId, $attrName)
    {
        return $this->inTransaction(function () use ($nodeId, $attrName) {
            $attributes = $this->getAttributes($nodeId);

            if (isset($attributes[$attrName])) {
                unset($attributes[$attrName]);

                return $this->writer()->saveAttributes($nodeId, $attributes);
            }

            return false;
        });
    }
    // }}}

    // {{{ removeNodeAttr
    /**
     * recursively remove attributes from node
     */
    public static function removeNodeAttr($node, $dbNs, $attribute)
    {
        if ($node->nodeType == XML_ELEMENT_NODE || $node->nodeType == XML_DOCUMENT_NODE) {
            [$xml, $node] = \Depage\Xml\Document::getDocAndNode($node);

            $xpath = new \DOMXPath($xml);
            $xpath->registerNamespace($dbNs->ns, $dbNs->uri);
            $xpResult = $xpath->query(".//@{$dbNs->ns}:{$attribute}", $node);

            foreach ($xpResult as $node) {
                $node->parentNode->removeAttributeNode($node);
            }
        }
    }
    // }}}
    // {{{ hashDomNode()
    /**
     * @brief hashDomNode
     *
     * @param mixed $node
     * @return void
     **/
    public function hashDomNode($node)
    {
        $doc = new \DOMDocument();
        $doc->formatOutput = false;

        [$d, $node] = \Depage\Xml\Document::getDocAndNode($node);
        $rootNode = $doc->importNode($node, true);
        $doc->appendChild($rootNode);

        self::removeIdAttr($rootNode);
        self::removeNodeAttr($rootNode, $this->dbNs, 'lastchange');
        self::removeNodeAttr($rootNode, $this->dbNs, 'lastchangeUid');
        self::removeNodeAttr($rootNode, $this->dbNs, 'docid');
        self::removeNodeAttr($rootNode, $this->dbNs, 'released');

        return hash("sha256", $doc->saveXML());
    }
    // }}}
    // {{{ getFreeNodeIds
    /**
     * gets unused db-node-ids for saving nodes; the result is kept in
     * free_element_ids, where the write pipeline takes the ids for its
     * nodes from
     */
    protected function getFreeNodeIds($preference = [])
    {
        $this->freeElementIds = $this->writer()->getFreeNodeIds($preference);
    }
    // }}}
    // {{{ addChildnodesByQuery
    /**
     * add child nodes of a node to DOMDocument
     *
     * @param   $query (object) database query to get next row
     * @param   $row (object) last result row for parent
     * @param   $parentNode (object) DOMElement of parent node or empty DOMDocument
     *
     * @return  $row (object) values of last queried row
     */
    protected function addChildnodesByQuery($query, $row, $parentNode)
    {
        $doc = $parentNode->ownerDocument ?? $parentNode;

        do {
            //get ELMEMENT_NODE
            if ($row->type == 'ELEMENT_NODE') {
                //create node
                if ($ns = $this->getNamespace($row->name)) {
                    $node = $doc->createElementNS($ns->uri, $row->name);
                } else {
                    $node = $doc->createElement($row->name);
                }
                $parentNode->appendChild($node);

                if ($row->lvl == 0) {
                    foreach ($this->namespaces as $n) {
                        $node->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:{$n->ns}", $n->uri);
                    }

                    //add lastchange-data
                    $node->setAttributeNS($this->dbNs->uri, "{$this->dbNs->ns}:lastchange", $this->lastchange);
                    $node->setAttributeNS($this->dbNs->uri, "{$this->dbNs->ns}:lastchangeUid", $this->lastchangeUid ?? '');
                }

                //add attributes to node
                foreach (self::parseAttributeString($row->value) as $attrName => $attrValue) {
                    if ($ns = $this->getNamespace($attrName)) {
                        $node->setAttributeNS($ns->uri, $attrName, $attrValue);
                    } else {
                        $node->setAttribute($attrName, $attrValue);
                    }
                }

                //add id_attribute to node
                $node->setAttributeNS($this->dbNs->uri, "{$this->dbNs->ns}:{$this->idAttribute}", $row->id);

                //get TEXT_NODES
            } elseif ($row->type == 'TEXT_NODE') {
                $node = $doc->createTextNode(strtr($row->value, "\r", " "));
                $parentNode->appendChild($node);
                //get CDATA_SECTION
            } elseif ($row->type == 'CDATA_SECTION_NODE') {
                // @todo CDATA not implemented yet
                //get COMMENT_NODE
            } elseif ($row->type == 'COMMENT_NODE') {
                $node = $doc->createComment($row->value);
                $parentNode->appendChild($node);
                //get PROCESSING_INSTRUCTION
            } elseif ($row->type == 'PI_NODE') {
                $node = $doc->createProcessingInstruction($row->name, $row->value);
                $parentNode->appendChild($node);
                //get ENTITY_REF Node
            } elseif ($row->type == 'ENTITY_REF_NODE') {
                // @todo ENTITY_REF_NODE not implemented yet
            }

            $lastRow = $row;
            $row = $query->fetchObject();

            if ($lastRow->type == 'ELEMENT_NODE') {
                if ($row && $lastRow->lvl < $row->lvl) {
                    //add child_nodes
                    $row = $this->addChildnodesByQuery($query, $row, $node);
                }
            }
            if (!$row || $lastRow->lvl > $row->lvl) {
                return $row;
            }
        } while ($row);

        return $row;
    }
    // }}}
    // {{{ getAppendIndex
    /**
     * gets the index a child would get when it is appended to the child
     * list of a node, i.e. the number of children it has now; this is the
     * index -1 as targetIndex stands for
     *
     * @param $targetId (int) db-id of parent node
     *
     * @return (int) index of an appended child
     */
    protected function getAppendIndex($targetId)
    {
        return $this->positions()->appendIndex($targetId);
    }
    // }}}

    // {{{ parseNodename()
    /**
     * @brief parseNodename
     *
     * @param mixed $name
     * @return void
     **/
    protected function getNamespace($name)
    {
        $parts = explode(":", $name, 2);

        if (!isset($parts[1])) {
            return false;
        }

        return $this->namespaces[$parts[0]];
    }
    // }}}
    // {{{ extractNamespaces
    protected function extractNamespaces($str)
    {
        $namespaces = [];
        $pName = '([a-zA-Z0-9]*)';
        $pAttr = '([^"]*)';
        preg_match_all("/xmlns:$pName=\"$pAttr\"/", $str, $nsElements, PREG_SET_ORDER);

        foreach ($nsElements as $nsElement) {
            $namespaces[$nsElement[1]] = new XmlNs($nsElement[1], $nsElement[2]);
        }

        return $namespaces;
    }
    // }}}

    // {{{ saveNodePrivate
    protected function saveNodePrivate($node)
    {
        return $this->writer()->saveNodePrivate($node);
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
     */
    protected function saveNodeIn($node, $targetId, $targetIndex, $incChildren)
    {
        return $this->writer()->saveNodeIn($node, $targetId, $targetIndex, $incChildren);
    }
    // }}}
    // {{{ getNodeArrayForSaving
    /**
     * gets all nodes of a document in one array
     *
     * @param    $nodeArray (array) list of nodes to add current node to
     * @param    $node (domxmlnode) current node
     * @param    $parentIndex (int) index of parent node in created node list
     * @param    $pos (int) position of current node
     * @param    $stripwhitespace (bool) whether to strip whitespace from
     *           textnodes while saving
     */
    protected function getNodeArrayForSaving(&$nodeArray, $node, $parentIndex = null, $pos = 0, $stripwhitespace = true)
    {
        return $this->writer()->getNodeArrayForSaving($nodeArray, $node, $parentIndex, $pos, $stripwhitespace);
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
     *           $targetId, -1 appends it
     * @param    $setPosition (bool) whether to give the node a position in
     *           the child list of the target node
     *
     * @return    $id (int) db-id under which node has been saved
     */
    protected function saveNodeToDb($node, $id, $targetId, $targetIndex, $setPosition = false)
    {
        return $this->writer()->saveNodeToDb($node, $id, $targetId, $targetIndex, $setPosition);
    }
    // }}}
    // {{{ inTransaction
    /**
     * runs $work() inside a transaction and ends the transaction
     * afterwards; rolls back to the transaction level (keeping outer
     * transaction levels intact) and rethrows the exception when the
     * work fails, so the transaction never stays open on an error
     *
     * @param $work (callable)
     * @param $altering (bool) whether the work alters the document
     *
     * @return mixed return value of $work
     */
    protected function inTransaction(callable $work, bool $altering = true)
    {
        if ($altering) {
            $this->beginTransactionAltering();
        } else {
            $this->beginTransactionNonAltering();
        }

        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->rollBackTransaction();

            throw $e;
        }

        $this->endTransaction();

        return $result;
    }
    // }}}
    // {{{ beginTransactionAltering
    /**
     * opens a transaction that changes the document
     *
     * the outermost level starts a database transaction, the inner levels
     * use savepoints
     *
     * @return int the level of the transaction
     */
    public function beginTransactionAltering()
    {
        return $this->xmlDb->beginTransactionAltering();
    }
    // }}}
    // {{{ beginTransactionNonAltering
    /**
     * opens a transaction that only reads the document
     *
     * the outermost level starts a database transaction, the inner levels
     * use savepoints
     *
     * @return int the level of the transaction
     */
    public function beginTransactionNonAltering()
    {
        return $this->xmlDb->beginTransactionNonAltering();
    }
    // }}}
    // {{{ endTransaction
    /**
     * closes the innermost open transaction level
     *
     * the outermost level commits the database transaction, an inner level
     * releases its savepoint; the hashes of the document are recalculated
     * before the outermost level is committed
     *
     * @return bool true if the outermost transaction has changed the
     *         document
     */
    public function endTransaction()
    {
        if ($this->subhashesDirty && $this->xmlDb->transactions == 1) {
            // last open transaction level; the subhashes still have to be
            // written before the actual commit runs to stay atomic
            $this->subhashesDirty = false;
            $this->refreshSubhashes();
        }

        $altered = $this->xmlDb->endTransaction();

        if ($altered) {
            $this->clearDocInfoCache();
            $this->clearCache();
            $this->getDoctypeHandler()->onDocumentChange();
        }

        return $altered;
    }
    // }}}
    // {{{ rollBackTransaction
    /**
     * rolls back to the start of the innermost open transaction level,
     * keeping the outer transaction levels (if any) intact
     *
     * @return bool true if a transaction level was rolled back, false if no
     *         transaction was open
     */
    public function rollBackTransaction()
    {
        // the document may have changed before the rollback, reload the
        // doc info instead of handing out information about rolled back
        // changes
        $this->docInfoLoaded = false;

        return $this->xmlDb->rollBackTransaction();
    }
    // }}}
    // {{{ clearDocInfoCache
    /**
     * drops the memoized document info; has to be called when the
     * document row or its history changes outside of the document's own
     * transactions (like the history writes of DocumentHistory do)
     */
    public function clearDocInfoCache()
    {
        $this->docInfoLoaded = false;
    }
    // }}}
    // {{{ updateLastChange
    /**
     * set or updates the lastchange date and uid for the current document
     *
     * @param $timestamp (int) optional timestamp, defaults to now
     * @param $uid (int) optional user id, defaults to current user, when user
     *        is set in xmlDb options
     */
    public function updateLastChange($timestamp = null, $uid = null)
    {
        $this->subhashesDirty = true;

        // the document row changes, the memoized info is stale
        $this->docInfoLoaded = false;

        $query = $this->pdo->prepare(
            "UPDATE {$this->tableDocs}
            SET
                lastchange=:timestamp,
                lastchange_uid=IFNULL(:user_id, lastchange_uid)
            WHERE
                id=:doc_id;",
        );

        if (empty($timestamp)) {
            $timestamp = time();
        } elseif (is_string($timestamp)) {
            $timestamp = strtotime($timestamp);
        }

        if (!empty($uid)) {
            $userId = $uid;
        } elseif (!empty($this->xmlDb->options['userId'])) {
            $userId = $this->xmlDb->options['userId'];
        } else {
            $userId = null;
        }

        $params = [
            'doc_id' => $this->getDocId(),
            'timestamp' => date('Y-m-d H:i:s', $timestamp),
            'user_id' => $userId,
        ];

        return ($query->execute($params)) ? $timestamp : false;
    }
    // }}}
    // {{{ refreshSubhashes
    /**
     * recalculates the merkle style subhash of every node of the
     * document; a subhash covers the stored type, name and value of the
     * node plus the subhashes of all its children, so comparing one hash
     * answers whether a whole subtree changed. subhashes do not include
     * node ids, equal subtrees always have equal hashes.
     *
     * nodes that already carry the hash they would be recalculated to
     * are not touched; has to run inside a transaction (called by
     * endTransaction before the commit).
     */
    protected function refreshSubhashes()
    {
        if (is_null($this->subhashUpdater)) {
            $this->subhashUpdater = new Helpers\SubhashUpdater($this->pdo, $this->tableXml, $this->docId, self::SORTKEY_PAD, self::SAVE_BATCH_SIZE);
        }

        $this->subhashUpdater->refresh();
    }
    // }}}
    // {{{ clearCache
    /**
     * clears the node-cache
     *
     * the whole document namespace is dropped, not only the caches of
     * the changed nodes: every cached subdocument embeds the document's
     * db:lastchange attribute in its root element, so any change to the
     * document makes the cached subdocuments of all its nodes stale
     */
    public function clearCache()
    {
        if (!is_null($this->docId)) {
            $this->cache->delete(Helpers\CacheKey::forDocument($this->tableDocs, $this->docId));
        }
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
