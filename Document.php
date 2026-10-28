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

class Document
{
    // {{{ constants
    /**
     * number of decimal digits used for the position part of the recursive
     * CTE sortkey; limits the tree depth the recursive queries can order
     * to strlen-limit / SORTKEY_PAD levels
     */
    public const SORTKEY_PAD = 20;

    /**
     * distance between the sparse positions of sibling nodes; inserting a
     * node between two neighbours takes the midpoint of their positions,
     * the child list is rebalanced once the space between two neighbours
     * drops below POS_MIN_GAP
     */
    public const POS_GAP = 65536;
    public const POS_MIN_GAP = 16;

    /**
     * savePatch() falls back to a full save() and saveNodePatch() to a
     * full saveNode() when more than this ratio of the nodes of the
     * patched subtree changed; below that limit patching writes fewer
     * rows than deleting and reinserting the tree would
     */
    public const PATCH_MAX_CHANGE_RATIO = 0.4;

    /**
     * maximum number of nodes written with a single statement;
     * saveNodesByType() inserts nodes in batches of this size (the auto
     * incremented ids of such a batch are read back with one select over
     * the unique sibling index), refreshSubhashes() and
     * rebalancePositions() write their rows in batches of this size
     */
    public const SAVE_BATCH_SIZE = 200;
    // }}}

    // {{{ variables
    protected $pdo;
    protected $cache;
    protected $db_ns;

    protected $table_prefix;
    protected $table_docs;
    protected $table_xml;
    protected $table_nodetypes;

    protected $xmlDb;
    protected $doc_id;

    protected $id_attribute = 'id';
    protected $id_data_attribute = 'dataid';

    protected $free_element_ids = [];
    protected $doctypeHandlers = [];

    protected $entities = '';
    protected $namespace_string = '';
    protected $namespaces = [];
    protected $lastchange = '';
    protected $lastchangeUid = '';

    private $childNodeQuery = null;
    private $insertNodeQuery = null;

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
     * @param $doc_id
     */
    public function __construct($xmlDb, $doc_id)
    {
        $this->pdo = $xmlDb->pdo;
        $this->cache = $xmlDb->cache;

        $this->db_ns = new XmlNs('db', 'http://cms.depagecms.net/ns/database');

        $this->xmlDb = $xmlDb;

        $this->table_prefix = $xmlDb->table_prefix;
        $this->table_docs = $xmlDb->table_docs;
        $this->table_xml = $xmlDb->table_xml;
        $this->table_nodetypes = $xmlDb->table_nodetypes;

        $this->doc_id = $doc_id;
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
        return $this->doc_id;
    }
    // }}}
    // {{{ getXml
    /**
     * getXml
     *
     * @return string
     */
    public function getXml($add_id_attribute = true)
    {
        $root_id = $this->getDocInfo()->rootid;

        if (is_null($root_id)) {
            throw new Exceptions\XmlDbException('Trying to get contents of empty document.');
        } else {
            $xml = $this->getSubdocByNodeId($root_id, $add_id_attribute);
        }

        return $xml;
    }
    // }}}
    // {{{ getDocInfo
    /**
     * gets info about a document by doc_id
     *
     * @return $info (array)
     */
    public function getDocInfo()
    {
        $query = $this->pdo->prepare(
            "SELECT
                docs.id AS id,
                docs.name AS name,
                docs.rootid AS rootid,
                docs.type AS type,
                docs.ns AS namespaces,
                docs.lastchange AS lastchange,
                docs.lastchange_uid AS lastchangeUid
            FROM {$this->table_docs} AS docs
            WHERE docs.id = :doc_id
            LIMIT 1",
        );
        $query->execute([
            'doc_id' => $this->doc_id,
        ]);

        $info = $query->fetchObject();

        if ($info) {
            $info->lastchange = new \DateTime($info->lastchange);
            $info->lastrelease = $this->getHistory()->getLatestVersion()->lastsaved ?? false;
        }

        return $info;
    }
    // }}}
    // {{{ getDoctypeHandler
    /**
     * @param $doc_id
     *
     * @return mixed
     */
    public function getDoctypeHandler()
    {
        if (!isset($this->doctypeHandlers[$this->doc_id])) {
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
                if (!is_a($className, XmlDoctypes\DoctypeInterface::class, true)) {
                    throw new Exceptions\XmlDbException('Doctype handler must implement DoctypeInterface');
                }

                $handler = new $className($this->xmlDb, $this);
            }

            if ($handler instanceof XmlDoctypes\DoctypeInterface) {
                $this->doctypeHandlers[$this->doc_id] = $handler;
            } else {
                throw new Exceptions\XmlDbException('Doctype handler must implement DoctypeInterface');
            }
        }

        return $this->doctypeHandlers[$this->doc_id];
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
    public function getNamespacesAndEntities()
    {
        $query = $this->pdo->prepare(
            "SELECT docs.entities AS entities, docs.ns AS namespaces
            FROM {$this->table_docs} AS docs
            WHERE docs.id = :doc_id",
        );
        $query->execute([
            'doc_id' => $this->doc_id,
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
        return new DocumentHistory($this->pdo, $this->table_prefix, $this);
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
     * @return    $db_id (int)
     */
    public function getNodeId($node)
    {
        return $node->nodeType == XML_ELEMENT_NODE
            ? $node->getAttributeNS($this->db_ns->uri, $this->id_attribute)
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
     * @return    $db_id (int)
     */
    public function getNodeDataId($node)
    {
        return $node->nodeType == XML_ELEMENT_NODE
            ? $db_id = $node->getAttributeNS($this->db_ns->uri, $this->id_data_attribute)
            : null;
    }
    // }}}

    // {{{ getNodeAttributeById
    protected function getNodeAttributeById($id, $attribute)
    {
        $query = $this->pdo->prepare(
            "SELECT xml.$attribute AS $attribute
            FROM {$this->table_xml} AS xml
            WHERE xml.id = :id AND xml.id_doc = :doc_id",
        );
        $query->execute([
            'id' => $id,
            'doc_id' => $this->doc_id,
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
     * @return $node_name (string) name of node, false if node doesn't exist.
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
     * @return $parent_id (id) db-id of parent node, false, if node doesn't exist.
     */
    public function getParentIdById($id)
    {
        return $this->getNodeAttributeById($id, 'id_parent');
    }
    // }}}
    // {{{ getPosById
    /**
     * gets node position in its parents childlist by node db-id.
     *
     * @param $id (int) node id
     *
     * @return $pos (int) position in node parents childlist
     */
    public function getPosById($id)
    {
        return $this->getNodeAttributeById($id, 'pos');
    }
    // }}}
    // {{{ getIndexById
    /**
     * gets the index of a node in the child list of its parent node
     * (number of siblings before it). in contrast to getPosById this is
     * the "dense" position, independent of the sparse pos values.
     *
     * @param $id (int) node db-id
     *
     * @return $index (int) index in parents childlist, false if node doesn't exist.
     */
    public function getIndexById($id)
    {
        $parent_id = $this->getParentIdById($id);

        if ($parent_id === false) {
            return false;
        }

        $pos = $this->getPosById($id);

        $query = $this->pdo->prepare(
            "SELECT COUNT(*) AS sibling_index
            FROM {$this->table_xml} AS xml
            WHERE xml.id_parent = :parent_id AND xml.id_doc = :doc_id AND xml.pos < :pos",
        );
        $query->execute([
            'parent_id' => $parent_id,
            'pos' => $pos,
            'doc_id' => $this->doc_id,
        ]);
        $result = $query->fetchObject();

        return (int) $result->sibling_index;
    }
    // }}}
    // {{{ getSiblingPositions
    /**
     * gets id and pos of all children of a parent node in document order
     *
     * @param $parent_id (int) db-id of parent node
     *
     * @return (array) list of objects with id and pos
     */
    protected function getSiblingPositions($parent_id)
    {
        $query = $this->pdo->prepare(
            "SELECT xml.id, xml.pos
            FROM {$this->table_xml} AS xml
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
    protected function positionForIndex($parent_id, $index, $exclude_id = null)
    {
        $siblings = $this->getSiblingPositions($parent_id);

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
            $this->rebalancePositions($parent_id);

            return $this->positionForIndex($parent_id, $index, $exclude_id);
        }

        return intdiv($prev_pos + $next_pos, 2);
    }
    // }}}
    // {{{ rebalancePositions
    /**
     * renumbers the children of a parent node to have an even distance of
     * self::POS_GAP between their positions; called when the sparse
     * positions of a child list are exhausted
     *
     * @param $parent_id (int) db-id of parent node
     */
    protected function rebalancePositions($parent_id, $order = null)
    {
        $siblings = $this->getSiblingPositions($parent_id);

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
            // reorder into the given child order (used by savePatch)
            $ids = array_map('intval', $order);

            if (count($ids) != count($current) || array_diff($ids, $current) || array_diff($current, $ids)) {
                throw new Exceptions\XmlDbException("Can not reorder the children of node {$parent_id}, the node list does not match.");
            }
        }

        // the unique sibling index is checked row by row, so the positions
        // have to be moved out of the way first: adding a big offset in
        // descending order cannot collide with a sibling that has not
        // been updated yet, and the offset is big enough to keep the
        // scratch positions clear of the final positions that are
        // assigned in the update below
        $this->pdo->exec(
            "UPDATE {$this->table_xml}
            SET pos = pos + 1125899906842624
            WHERE id_doc = " . (int) $this->doc_id . " AND id_parent = " . (int) $parent_id . "
            ORDER BY pos DESC"
        );

        // every sibling gets a position of its own, so writing the final
        // positions in batches cannot collide with the unique sibling
        // index either
        $positions = new Helpers\CaseUpdate($this->pdo, $this->table_xml, self::SAVE_BATCH_SIZE);
        foreach ($ids as $index => $id) {
            $positions->add($id, ($index + 1) * self::POS_GAP);
        }
        $positions->write('pos', ['id_doc' => $this->doc_id]);
    }
    // }}}

    // {{{ getNodeIdsByXpath
    /**
     * gets node_ids by xpath
     *
     * @attention this supports only a small subset of xpath-queries. so recheck source before using.
     *
     * @param $this->doc_id (int) id of document
     * @param $xpath (string) xpath to target node
     *
     * @return $nodeids (array) array of found node ids
     *
     * @todo implement full xpath specifications
     */
    public function getNodeIdsByXpath($xpath)
    {
        $identifier = "{$this->table_docs}_d{$this->doc_id}/xpath_" . sha1($xpath);
        $fetched_ids = $this->cache->get($identifier);

        if ($fetched_ids === false) {
            $fetched_ids = $this->xmlDb->getNodeIdsByXpath($xpath, $this->doc_id);

            $this->cache->set($identifier, $fetched_ids);
        }
        return $fetched_ids;
    }
    // }}}
    // {{{ getSubdocByNodeId
    /**
     * gets an xml-document-object from specific db-id
     *
     * @param $id (int) db-id of node to get
     * @param $add_id_attribute (bool) true, if you want to add the db-id
     *        attributes to xml-definition, false to remove them.
     */
    public function getSubdocByNodeId($id, $add_id_attribute = true)
    {
        $identifier = "{$this->table_docs}_d{$this->doc_id}/{$id}.xml";
        $xmlDoc = new \Depage\Xml\Document();

        if ($xmlStr = $this->cache->get($identifier)) {
            // read from cache
            $xmlDoc->loadXML($xmlStr);
        } else {
            // read from database
            $this->beginTransactionNonAltering();

            $query = null;
            try {
                $query = $this->pdo->prepare(
                    "SELECT
                        docs.entities AS entities,
                        docs.ns AS namespaces,
                        docs.lastchange AS lastchange,
                        docs.lastchange_uid AS lastchangeUid
                    FROM {$this->table_docs} AS docs
                    WHERE docs.id = :doc_id",
                );
                $query->execute([
                    'doc_id' => $this->doc_id,
                ]);
                $result = $query->fetchObject();

                $this->entities = $result->entities;
                $this->namespace_string = $result->namespaces;
                $this->namespaces = $this->extractNamespaces($this->namespace_string);
                $this->namespaces[$this->db_ns->ns] = $this->db_ns;
                $this->lastchange = $result->lastchange;
                $this->lastchangeUid = $result->lastchangeUid;

                $pad = self::SORTKEY_PAD;
                $query = $this->pdo->prepare(
                    "WITH RECURSIVE tree (id, id_parent, lvl, sortkey) AS
                        (
                        SELECT id, id_parent, 0, CAST('' AS CHAR(5000))
                            FROM {$this->table_xml} AS xml
                            WHERE id = :id AND id_doc = :doc_id
                        UNION ALL
                        SELECT x.id, x.id_parent, lvl + 1, CONCAT(sortkey, LPAD(IFNULL(x.pos + 1, 0), {$pad}, '0'))
                            FROM {$this->table_xml} AS x INNER JOIN tree AS t
                            ON x.id_parent = t.id
                        )
                        SELECT lvl, xml.* FROM tree JOIN {$this->table_xml} AS xml ON tree.id = xml.id ORDER BY sortkey;",
                    \Depage\Db\Pdo::unbuffered(),
                );
                $query->execute([
                    'doc_id' => $this->doc_id,
                    'id' => $id,
                ]);
                $row = $query->fetchObject();

                //get ROOT-NODE
                if (!($row && $row->type == 'ELEMENT_NODE')) {
                    throw new Exceptions\XmlDbException('This node is no ELEMENT_NODE or node does not exist' . " {$this->doc_id}/{$id}");
                }

                $this->addChildnodesByQuery($query, $row, $xmlDoc);
            } catch (\Throwable $e) {
                // the tree query may be an unbuffered result, it has to be
                // closed before any other statement can run on the connection
                if (isset($query)) {
                    $query->closeCursor();
                }

                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();

            $dth = $this->getDoctypeHandler();
            if ($dth->testDocument($xmlDoc)) { // test whether the document was altered
                $this->saveNode($xmlDoc);
            }

            // add xml to xml-cache
            if (is_object($xmlDoc) && $xmlDoc->documentElement != null) {
                $this->cache->set($identifier, $xmlDoc->saveXML());
            }
        }

        if (is_a($xmlDoc, 'DOMDocument') && $xmlDoc->documentElement != null && !$add_id_attribute) {
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
     * @param    $add_id_attribute (bool) whether to add db:id attribute or not
     *
     * @return    $doc (domxmlobject)
     */
    public function getSubdocByXpath($xpath, $add_id_attribute = true)
    {
        $subdoc = false;
        $ids = $this->getNodeIdsByXpath($xpath);

        if (isset($ids[0])) {
            $subdoc = $this->getSubdocByNodeId($ids[0], $add_id_attribute);
        }

        return $subdoc;
    }
    // }}}

    // {{{ getAttribute
    /**
     * gets attribute of node
     *
     * @param    $node_id (int) db-id of node
     * @param    $attr_name (string) name of attribute
     *
     * @return    $val (string) value
     */
    public function getAttribute($node_id, $attr_name)
    {
        $attributes = $this->getAttributes($node_id);

        return (isset($attributes[$attr_name])) ? $attributes[$attr_name] : false;
    }
    // }}}
    // {{{ getAttributes
    /**
     * gets all attributes of a node by id
     *
     * @param    $node_id (int) db-id of node
     *
     * @return    $attributes (array) array of attributes
     */
    public function getAttributes($node_id)
    {
        $attributes = [];

        $query = $this->pdo->prepare(
            "SELECT xml.value
            FROM {$this->table_xml} AS xml
            WHERE xml.id = :node_id AND xml.type='ELEMENT_NODE' AND xml.id_doc = :doc_id",
        );
        $query->execute([
            'node_id' => $node_id,
            'doc_id' => $this->doc_id,
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
            if ($match[0] != '') {
                $attributes[$match[0]] = htmlspecialchars_decode($match[1]);
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
        $doc_info = $this->getNamespacesAndEntities($this->doc_id);
        $xml = "<$name {$doc_info->namespaces}";
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
        self::removeNodeAttr($node, $this->db_ns, $this->id_attribute);
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

        $this->beginTransactionAltering();

        try {
            if ($info) {
                $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0;");

                try {
                    $query = $this->pdo->prepare(
                        "DELETE FROM {$this->table_xml}
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
        } catch (\Throwable $e) {
            $this->rollBackTransaction();

            throw $e;
        }

        $this->endTransaction();

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
        $this->beginTransactionAltering();

        try {
            $doc_info = $this->clearDoc();

            $namespaces = $this->getNamespaceString($xml);

            // @TODO get document and entities or set html_entities as standard as long as php does not inherit the entites() function
            $doc_info->rootid = $this->saveNodePrivate($xml);
            $query = $this->pdo->prepare(
                "UPDATE {$this->table_docs}
                SET
                    rootid = :rootid,
                    ns = :ns,
                    entities=''
                WHERE id = :doc_id",
            );

            $query->execute([
                'rootid' => $doc_info->rootid,
                'ns' => $namespaces,
                'doc_id' => $doc_info->id,
            ]);
        } catch (\Throwable $e) {
            $this->rollBackTransaction();

            throw $e;
        }

        $this->endTransaction();

        return $doc_info->id;
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
        $doc_info = $this->getDocInfo();
        $plan = null;

        if ($doc_info) {
            $plan = $this->planPatch($xml, $doc_info);
        }

        if (is_null($plan)) {
            $this->lastPatchStats = ['fallback' => true];

            return $this->save($xml);
        }

        $this->beginTransactionAltering();

        try {
            $query = $this->pdo->prepare(
                "UPDATE {$this->table_docs}
                SET
                    ns = :ns,
                    entities = ''
                WHERE id = :doc_id",
            );
            $query->execute([
                'ns' => $this->getNamespaceString($xml),
                'doc_id' => $this->doc_id,
            ]);

            $this->applyPatch($plan);

            $this->updateLastChange();
        } catch (\Throwable $e) {
            $this->rollBackTransaction();

            throw $e;
        }

        $this->endTransaction();

        $this->lastPatchStats = $plan['stats'] + ['fallback' => false];

        return $doc_info->id;
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
    protected function planPatch(\DomDocument $xml, $doc_info)
    {
        $root = $xml->documentElement;
        $root_id = is_null($root) ? '' : $this->getNodeId($root);

        if (empty($root_id) || (int) $root_id !== (int) $doc_info->rootid) {
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
     * $check_root rejects the plan (instead of renaming the row in place)
     * when the root node is not compatible with the row its id points
     * to, i.e. was replaced by a node of another type or name;
     * saveNodePatch() uses this to fall back to a full saveNode()
     */
    protected function planNodePatch($node, $check_root = false)
    {
        // run the subtree through the same pipeline save() uses, this
        // also applies the doctype whitespace handling
        $node_array = [];
        $this->getNodeArrayForSaving($node_array, $node);

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
            FROM {$this->table_xml} AS xml
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
            && !$this->nodesCompatible($this->getNodeTypeData($node_array[0]['node']), $old_rows[$root_id])
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
            $content[$index] = $this->getNodeTypeData($node_array[$index]['node']);
            $content[$index][2] = \Normalizer::normalize($content[$index][2]);

            $child_hashes = [];
            foreach ($new_children[$index] ?? [] as $child_index) {
                $child_hashes[] = $hashes[$child_index];
            }

            $hashes[$index] = $this->getNodeHash($content[$index][0], $content[$index][1], $content[$index][2], $child_hashes);
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

        // structural changes: when more than PATCH_MAX_CHANGE_RATIO of
        // the nodes of the subtree have to be inserted or deleted, a
        // full save is cheaper and safer than patching; updated nodes
        // only need one row write each and do not count into this
        $changed = count($plan['inserts']) + count($plan['deletes']);

        if ($changed > self::PATCH_MAX_CHANGE_RATIO * max($old_subtree, count($node_array))) {
            return null;
        }

        return $plan;
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
        if (!empty($plan['deletes'])) {
            $delete = $this->pdo->prepare(
                "DELETE FROM {$this->table_xml}
                WHERE id_doc = :doc_id AND id = :id",
            );

            foreach ($plan['deletes'] as $node_id) {
                $delete->execute([
                    'doc_id' => $this->doc_id,
                    'id' => $node_id,
                ]);
            }
        }

        if (!empty($plan['updates'])) {
            $update = $this->pdo->prepare(
                "UPDATE {$this->table_xml}
                SET name = :name, value = :value
                WHERE id_doc = :doc_id AND id = :id",
            );

            foreach ($plan['updates'] as $node) {
                $update->execute([
                    'name' => $node['name'],
                    'value' => $node['value'],
                    'doc_id' => $this->doc_id,
                    'id' => $node['id'],
                ]);
            }
        }

        // new subtrees are appended to the child list of their parent,
        // the reordering below puts them in place
        $inserted_ids = [];
        foreach ($plan['inserts'] as $index => $insert) {
            $inserted_ids[$index] = $this->saveNodeIn($insert['node'], $insert['parent_id'], -1, true);
        }

        foreach ($plan['renumber'] as $parent_id => $layout) {
            $ordered_ids = [];
            foreach ($layout as $slot) {
                $ordered_ids[] = isset($slot['id']) ? $slot['id'] : $inserted_ids[$slot['insert']];
            }

            // skip the reordering when the children are already in the
            // right order (the appends did not change it)
            $current = $this->getSiblingPositions($parent_id);
            $reorder = count($current) != count($ordered_ids);
            foreach ($current as $pos => $sibling) {
                if (!$reorder && (int) $sibling->id != $ordered_ids[$pos]) {
                    $reorder = true;
                }
            }

            if ($reorder) {
                $this->rebalancePositions($parent_id, $ordered_ids);
            }
        }
    }
    // }}}
    // {{{ getNodeTypeData
    /**
     * returns the (type, name, value) tupel a DOM node is saved as, in
     * exactly the same way saveNodeToDb stores it; needed to calculate
     * the hashes of nodes that are not in the database (yet)
     */
    protected function getNodeTypeData($node)
    {
        if ($node->nodeType == XML_ELEMENT_NODE) {
            if ($node->prefix != '') {
                $node_name = $node->prefix . ':' . $node->localName;
            } else {
                $node_name = $node->localName;
            }

            $attributes = [];
            foreach ($node->attributes as $attrib) {
                $attrib_ns = ($attrib->prefix == '') ? '' : $attrib->prefix . ':';

                $attributes[$attrib_ns . $attrib->localName] = $attrib->value;
            }

            return ['ELEMENT_NODE', $node_name, $this->getAttributeString($attributes)];
        } elseif ($node->nodeType == XML_TEXT_NODE) {
            return ['TEXT_NODE', null, $node->textContent];
        } elseif ($node->nodeType == XML_COMMENT_NODE) {
            return ['COMMENT_NODE', null, $node->textContent];
        } elseif ($node->nodeType == XML_PI_NODE) {
            return ['PI_NODE', $node->target, $node->textContent];
        } elseif ($node->nodeType == XML_ENTITY_REF_NODE) {
            return ['ENTITY_REF_NODE', null, $node->nodeName];
        }

        throw new Exceptions\XmlDbException('Unknown DOM node type: "' . $node->nodeType . '".');
    }
    // }}}
    // {{{ nodesCompatible
    /**
     * whether a node of the new document can take the place of the
     * stored node without changing its type or name (nodes are matched by
     * position when they do not carry a database id)
     */
    protected function nodesCompatible(array $content, $row)
    {
        return $content[0] == $row->type
            && (string) ($content[1] ?? '') === (string) ($row->name ?? '');
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
     * @param $node_id
     *
     * @return bool
     */
    public function deleteNode($node_id)
    {
        $success = false;

        $dth = $this->getDoctypeHandler();

        if ($dth->isAllowedDelete($node_id)) {
            $this->beginTransactionAltering();

            try {
                $success = $this->deleteNodePrivate($node_id);

                $this->updateLastChange();
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
        }

        return $success;
    }
    // }}}
    // {{{ saveNode
    public function saveNode($node)
    {
        $this->beginTransactionAltering();

        try {
            $result = $this->saveNodePrivate($node);
        } catch (\Throwable $e) {
            $this->rollBackTransaction();

            throw $e;
        }

        $this->endTransaction();

        return $result;
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

        $this->beginTransactionAltering();

        try {
            $this->applyPatch($plan);

            $this->updateLastChange();
        } catch (\Throwable $e) {
            $this->rollBackTransaction();

            throw $e;
        }

        $this->endTransaction();

        $this->lastPatchStats = $plan['stats'] + ['fallback' => false];

        return $plan['root_id'];
    }
    // }}}

    // {{{ addNode
    /**
     * @param $node
     * @param $target_id
     * @param $target_pos
     *
     * @return bool
     */
    public function addNode(\DomNode $node, $target_id, $target_pos = -1, $extras = [])
    {
        $success = false;
        $dth = $this->getDoctypeHandler();

        if ($dth->isAllowedAdd($node, $target_id)) {
            $dth->onAddNode($node, $target_id, $target_pos, $extras);

            $this->beginTransactionAltering();

            try {
                $success = $this->saveNodeIn($node, $target_id, $target_pos, true);
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
        }

        return $success;
    }
    // }}}
    // {{{ addNodeByName
    /**
     * @param $name
     * @param $target_id
     * @param $target_pos
     * @return bool
     */
    public function addNodeByName($name, $target_id, $target_pos, $extras = [])
    {
        $success = false;
        $dth = $this->getDoctypeHandler();
        $newNodes = $dth->getNewNodeFor($name);

        if (!$newNodes) {
            return false;
        }

        for ($i = $newNodes->length - 1; $i >= 0; $i--) {
            $node = $newNodes->item($i);
            if ($node->nodeType == \XML_ELEMENT_NODE) {
                $success = $this->addNode($node, $target_id, $target_pos, $extras);
            }
        }

        return $success;
    }
    // }}}

    // {{{ replaceNode
    /**
     * replaces a node in database
     *
     * @param    $node (domxmlnode) node to save
     * @param    $id_to_replace (int) db-id of node to be replaced
     * @param    $this->doc_id (int) document db-id
     *
     * @return    $changed_ids (array) list of db-ids that has been changed
     */
    public function replaceNode($node, $id_to_replace)
    {
        $this->beginTransactionAltering();

        try {
            $target_id = $this->getParentIdById($id_to_replace);
            $target_pos = $this->getIndexById($id_to_replace);

            $this->deleteNodePrivate($id_to_replace);

            $changed_ids = [];
            $changed_ids[] = $this->saveNodeIn($node, $target_id, $target_pos, true);
            $changed_ids[] = $target_id;
        } catch (\Throwable $e) {
            $this->rollBackTransaction();

            throw $e;
        }

        $this->endTransaction();

        return $changed_ids;
    }
    // }}}
    // {{{ duplicateNode
    /**
     * duplicates node in database, and inserts it in the next position
     *
     * @TODO behaviour should differ according to tree type - don't usually want to copy all sub nodes
     *
     * @param $node_id (int) db-id of node
     *
     * @return $success (bool)
     */
    public function duplicateNode($node_id, $recursive = false)
    {
        $success = false;

        // get parent and position for new node
        $target_id = $this->getParentIdById($node_id);
        $target_pos = $this->getIndexById($node_id) + 1;
        $dth = $this->getDoctypeHandler();

        if ($dth->isAllowedMove($node_id, $target_id)) {
            $xmlDoc = $this->getSubdocByNodeId($node_id, false);
            $root_node = $xmlDoc;

            $this->clearCache();
            $this->beginTransactionAltering();

            try {
                $copy_id = $this->saveNodeIn($root_node, $target_id, $target_pos, $recursive);
                $success = $copy_id;
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
            $dth->onCopyNode($node_id, $copy_id);
        }

        return $success;
    }
    // }}}

    // {{{ moveNode
    public function moveNode($node_id, $target_id, $target_pos)
    {
        $parent_id = false;
        $dth = $this->getDoctypeHandler();

        if (
            $node_id !== $target_id
            && $dth->isAllowedMove($node_id, $target_id)
        ) {
            $this->beginTransactionAltering();

            try {
                $parent_id = $this->moveNodePrivate($node_id, $target_id, $target_pos);
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
            $dth->onMoveNode($node_id, $parent_id);
        }

        return $parent_id;
    }
    // }}}
    // {{{ moveNodeIn
    /**
     * moves node to another node (append child)
     *
     * @param    $node_id (int) db-id of node
     * @param    $target_id (int) db-id of target node
     */
    public function moveNodeIn($node_id, $target_id)
    {
        $parent_id = false;
        $dth = $this->getDoctypeHandler();

        if (
            $node_id !== $target_id
            && $dth->isAllowedMove($node_id, $target_id)
        ) {
            $this->beginTransactionAltering();

            try {
                $position = $this->getTargetPos($target_id);
                $parent_id = $this->moveNodePrivate($node_id, $target_id, $position);
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
            $dth->onMoveNode($node_id, $parent_id);
        }

        return $parent_id;
    }
    // }}}
    // {{{ moveNodeBefore
    /**
     * moves node before another node (insert before)
     *
     * @param    $node_id (int) db-id of node
     * @param    $target_id (int) db-id of target node
     */
    public function moveNodeBefore($node_id, $target_id)
    {
        $parent_id = false;
        $dth = $this->getDoctypeHandler();

        $target_parent_id = $this->getParentIdById($target_id);
        if (
            $node_id !== $target_id
            && $dth->isAllowedMove($node_id, $target_parent_id)
        ) {
            $this->beginTransactionAltering();

            try {
                $target_pos = $this->getIndexById($target_id);
                $parent_id = $this->moveNodePrivate($node_id, $target_parent_id, $target_pos);
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
            $dth->onMoveNode($node_id, $parent_id);
        }

        return $parent_id;
    }
    // }}}
    // {{{ moveNodeAfter
    /**
     * moves node after another node
     *
     * @param    $node_id (int) db-id of node
     * @param    $target_id (int) db-id of target node
     */
    public function moveNodeAfter($node_id, $target_id)
    {
        $parent_id = false;
        $dth = $this->getDoctypeHandler();

        $target_parent_id = $this->getParentIdById($target_id);
        if (
            $node_id !== $target_id
            && $dth->isAllowedMove($node_id, $target_parent_id)
        ) {
            $this->beginTransactionAltering();

            try {
                $target_pos = $this->getIndexById($target_id) + 1;
                $parent_id = $this->moveNodePrivate($node_id, $target_parent_id, $target_pos);
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
            $dth->onMoveNode($node_id, $parent_id);
        }

        return $parent_id;
    }
    // }}}

    // {{{ copyNode
    public function copyNode($node_id, $target_id, $target_pos)
    {
        $copy_id = false;
        $dth = $this->getDoctypeHandler();

        if ($dth->isAllowedCopy($node_id, $target_id)) {
            $this->beginTransactionAltering();

            try {
                $copy_id = $this->copyNodePrivate($node_id, $target_id, $target_pos);
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
            $dth->onCopyNode($node_id, $copy_id);
        }

        return $copy_id;
    }
    // }}}
    // {{{ copyNodeIn
    /**
     * copy node to another node
     *
     * @param    $node_id (int) db-id of node
     * @param    $target_id (int) db-id of target node
     */
    public function copyNodeIn($node_id, $target_id)
    {
        $copy_id = false;
        $dth = $this->getDoctypeHandler();

        if ($dth->isAllowedCopy($node_id, $target_id)) {
            $this->beginTransactionAltering();

            try {
                $position = $this->getTargetPos($target_id);
                $copy_id = $this->copyNodePrivate($node_id, $target_id, $position);
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
            $dth->onCopyNode($node_id, $copy_id);
        }

        return $copy_id;
    }
    // }}}
    // {{{  copyNodeBefore
    /**
     * copy node before another node
     *
     * @param    $node_id (int) db-id of node
     * @param    $target_id (int) db-id of target node
     */
    public function copyNodeBefore($node_id, $target_id)
    {
        $copy_id = false;
        $dth = $this->getDoctypeHandler();

        $target_parent_id = $this->getParentIdById($target_id);
        if ($dth->isAllowedCopy($node_id, $target_parent_id)) {
            $this->beginTransactionAltering();

            try {
                $copy_id = $this->copyNodeWithOffset($node_id, $target_id);
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
            $dth->onCopyNode($node_id, $copy_id);
        }

        return $copy_id;
    }
    // }}}
    // {{{ copyNodeAfter
    /**
     * copy node after another node
     *
     * @param    $node_id (int) db-id of node
     * @param    $target_id (int) db-id of target node
     */
    public function copyNodeAfter($node_id, $target_id)
    {
        $copy_id = false;
        $dth = $this->getDoctypeHandler();

        $target_parent_id = $this->getParentIdById($target_id);
        if ($dth->isAllowedCopy($node_id, $target_parent_id)) {
            $this->beginTransactionAltering();

            try {
                $copy_id = $this->copyNodeWithOffset($node_id, $target_id, 1);
            } catch (\Throwable $e) {
                $this->rollBackTransaction();

                throw $e;
            }

            $this->endTransaction();
            $dth->onCopyNode($node_id, $copy_id);
        }

        return $copy_id;
    }
    // }}}

    // {{{ deleteNodePrivate
    /**
     * unlinks and deletes a specific node from database.
     * re-indexes the positions of the remaining elements.
     *
     * @param     $node_id (int) db-id of node to delete
     *
     * @return    $deleted_ids (array) id of parent node
     */
    protected function deleteNodePrivate($node_id)
    {
        // get parent (needed by the doctype handler and as return value)
        $target_id = $this->getParentIdById($node_id);

        $dth = $this->getDoctypeHandler();

        if ($dth->onDeleteNode($node_id, $target_id)) {
            // delete the node
            $query = $this->pdo->prepare(
                "DELETE FROM {$this->table_xml}
                WHERE id_doc = :doc_id AND id = :node_id",
            );
            try {
                $query->execute([
                    'doc_id' => $this->doc_id,
                    'node_id' => $node_id,
                ]);
            } catch (\PDOException $e) {
                $pad = self::SORTKEY_PAD;
                $childquery = $this->pdo->prepare(
                    "WITH RECURSIVE tree (id, id_parent, lvl, sortkey) AS
                        (
                        SELECT id, id_parent, 0, CAST('' AS CHAR(5000))
                            FROM {$this->table_xml} AS xml
                            WHERE id = :id AND id_doc = :doc_id
                        UNION ALL
                        SELECT x.id, x.id_parent, lvl + 1, CONCAT(sortkey, LPAD(IFNULL(x.pos + 1, 0), {$pad}, '0'))
                            FROM {$this->table_xml} AS x INNER JOIN tree AS t
                            ON x.id_parent = t.id
                        )
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
    // {{{ moveNodePrivate
    /**
     * moves node in database
     *
     * // TODO prevent moving a node to its children
     *
     * @protected
     *
     * @param    $node_id (int) db-id of node
     * @param    $target_id (int) db-id of target node
     * @param    $target_pos (int) position to move to
     *
     * @return   (int) id of new parent node
     */
    protected function moveNodePrivate($node_id, $target_id, $target_pos)
    {
        $result = false;
        $node_parent_id = $this->getParentIdById($node_id);
        $node_index = $this->getIndexById($node_id);

        if ($target_id == $node_parent_id && $target_pos > $node_index) {
            $target_pos--;
        }

        if ($target_id != $node_parent_id || $target_pos != $node_index) {
            // with sparse positions the node only has to be reattached
            // between its new neighbours, all other nodes keep their
            // positions
            $pos = $this->positionForIndex($target_id, $target_pos, $node_id);

            $query = $this->pdo->prepare(
                "UPDATE {$this->table_xml}
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

            $this->updateLastChange();
            $result = $node_parent_id;
        }

        return $result;
    }
    // }}}
    // {{{ copyNodePrivate
    /**
     * copy node in database
     *
     * @public
     *
     * @param    $node_id (int) db-id of node
     * @param    $target_id (int) db-id of target node
     * @param    $target_pos (int) pos to copy to
     */
    protected function copyNodePrivate($node_id, $target_id, $target_pos)
    {
        $xmlDoc = $this->getSubdocByNodeId($node_id, false);
        $root_node = $xmlDoc;
        $save_id = $this->saveNodeIn($root_node, $target_id, $target_pos, true);

        return $save_id;
    }
    // }}}
    // {{{ copyNodeWithOffset
    /**
     * copy node after another node
     *
     * @param    $node_id (int) db-id of node
     * @param    $target_id (int) db-id of target node
     * @param    $target_pos_offset (int) offset of target position
     */
    protected function copyNodeWithOffset($node_id, $target_id, $target_pos_offset = 0)
    {
        $target_parent_id = $this->getParentIdById($target_id);
        $target_pos = $this->getIndexById($target_id) + $target_pos_offset;
        $copy_id = $this->copyNodePrivate($node_id, $target_parent_id, $target_pos);

        return $copy_id;
    }
    // }}}

    // {{{ setAttribute
    /**
     * sets attribute of node
     *
     * @param    $node_id (int) db-id of node to set attribute
     * @param    $attr_name (string) name of attribute
     * @param    $attr_value (string) new value of attribute
     */
    public function setAttribute($node_id, $attr_name, $attr_value)
    {
        $success = false;

        $this->beginTransactionAltering();

        try {
            $attributes = $this->getAttributes($node_id);

            if (
                (
                    isset($attributes[$attr_name])
                    && $attributes[$attr_name] !== htmlspecialchars($attr_value)
                )
                || !isset($attributes[$attr_name])
            ) {
                $dth = $this->getDoctypeHandler();

                $dth->onSetAttribute($node_id, $attr_name, $attributes[$attr_name] ?? null, $attr_value);

                $attributes[$attr_name] = $attr_value;

                $success = $this->saveAttributes($node_id, $attributes);
            }
        } catch (\Throwable $e) {
            $this->rollBackTransaction();

            throw $e;
        }

        $this->endTransaction();

        return $success;
    }
    // }}}
    // {{{ removeAttribute
    /**
     * removes an attribute of a node
     *
     * @param    $node_id (int) db-id of node to set attribute
     * @param    $attr_name (string) name of attribute
     */
    public function removeAttribute($node_id, $attr_name)
    {
        $success = false;

        $this->beginTransactionAltering();

        $attributes = $this->getAttributes($node_id);

        if (isset($attributes[$attr_name])) {
            unset($attributes[$attr_name]);

            $success = $this->saveAttributes($node_id, $attributes);
        }

        $this->endTransaction();

        return $success;
    }
    // }}}

    // {{{ saveAttributes
    /**
     * sets attribute of node
     *
     * @param    $node_id (int) db-id of node to set attribute
     * @param    $attributes (array) array of attribute values
     */
    protected function saveAttributes($node_id, $attributes)
    {
        $query = $this->pdo->prepare(
            "UPDATE {$this->table_xml} AS xml
            SET xml.value = :attr_str
            WHERE xml.id = :node_id AND xml.id_doc = :doc_id",
        );
        $success = $query->execute([
            'node_id' => $node_id,
            'attr_str' => $this->getAttributeString($attributes),
            'doc_id' => $this->doc_id,
        ]);

        $this->updateLastChange();

        return $success;
    }
    // }}}
    // {{{ removeNodeAttr
    /**
     * recursively remove attributes from node
     */
    public static function removeNodeAttr($node, $db_ns, $attribute)
    {
        if ($node->nodeType == XML_ELEMENT_NODE || $node->nodeType == XML_DOCUMENT_NODE) {
            [$xml, $node] = \Depage\Xml\Document::getDocAndNode($node);

            $xpath = new \DOMXPath($xml);
            $xpath->registerNamespace($db_ns->ns, $db_ns->uri);
            $xp_result = $xpath->query(".//@{$db_ns->ns}:{$attribute}", $node);

            foreach ($xp_result as $node) {
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
        self::removeNodeAttr($rootNode, $this->db_ns, 'lastchange');
        self::removeNodeAttr($rootNode, $this->db_ns, 'lastchangeUid');
        self::removeNodeAttr($rootNode, $this->db_ns, 'docid');
        self::removeNodeAttr($rootNode, $this->db_ns, 'released');

        return hash("sha256", $doc->saveXML());
    }
    // }}}
    // {{{ getAttributeString
    /**
     * gets attribute string for saving
     *
     * @param $attributes (array) array of attribute values
     */
    protected function getAttributeString($attributes)
    {
        $attr_str = '';
        $autogeneratedAttr = [
            $this->db_ns->ns . ':' . $this->id_attribute,
            $this->db_ns->ns . ':released',
            $this->db_ns->ns . ':lastchange',
            $this->db_ns->ns . ':lastchangeUid',
        ];
        ksort($attributes);
        foreach ($attributes as $name => $value) {
            if (!in_array($name, $autogeneratedAttr)) {
                $attr_str .= "$name=\"" . htmlspecialchars($value) . "\" ";
            }
        }

        return $attr_str;
    }
    // }}}

    // {{{ getFreeNodeIds
    /**
     * gets unused db-node-ids for saving nodes
     */
    protected function getFreeNodeIds($preference = [])
    {
        $lastMax = 0;

        if (!empty($preference)) {
            $ids = str_repeat('?,', count($preference) - 1) . '?';
            $query = $this->pdo->prepare("SELECT id FROM {$this->table_xml} WHERE id IN ($ids)");
            $query->execute($preference);
            $results = $query->fetchAll(\PDO::FETCH_COLUMN);

            $free = array_keys(array_flip(array_diff($preference, $results)));
        } else {
            $free = [];
        }

        $this->free_element_ids = $free;
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
                    $node->setAttributeNS($this->db_ns->uri, "{$this->db_ns->ns}:lastchange", $this->lastchange);
                    $node->setAttributeNS($this->db_ns->uri, "{$this->db_ns->ns}:lastchangeUid", $this->lastchangeUid ?? '');
                }

                //add attributes to node
                $matches = array_chunk(preg_split('/(="|"$|" )/', $row->value), 2);
                foreach ($matches as $m) {
                    $attrName = trim($m[0]);
                    $attrValue = htmlspecialchars_decode($m[1] ?? '');

                    if ($attrName != '') {
                        if ($ns = $this->getNamespace($attrName)) {
                            $node->setAttributeNS($ns->uri, $attrName, $attrValue);
                        } else {
                            $node->setAttribute($attrName, $attrValue);
                        }
                    }
                }

                //add id_attribute to node
                $node->setAttributeNS($this->db_ns->uri, "{$this->db_ns->ns}:{$this->id_attribute}", $row->id);

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
    // {{{ getTargetPos
    /**
     * gets the index a child would get when appended to the child list,
     * i.e. the number of current children
     */
    protected function getTargetPos($target_id)
    {
        $query = $this->pdo->prepare(
            "SELECT COUNT(*) AS pos
            FROM {$this->table_xml} AS xml
            WHERE xml.id_parent = :target_id AND id_doc = :doc_id",
        );
        $query->execute([
            'target_id' => $target_id,
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
        preg_match_all("/xmlns:$pName=\"$pAttr\"/", $str, $ns_elements, PREG_SET_ORDER);

        foreach ($ns_elements as $ns_element) {
            $namespaces[$ns_element[1]] = new XmlNs($ns_element[1], $ns_element[2]);
        }

        return $namespaces;
    }
    // }}}

    // {{{ saveNodePrivate
    protected function saveNodePrivate($node)
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
            $target_id = $this->getParentIdById($rootId);
            $target_pos = $this->getIndexById($rootId);

            if ($target_id === false) {
                $target_id = null;
            }
            if ($target_pos === false) {
                $target_pos = null;
            }

            // delete old node
            $this->deleteNodePrivate($rootId);
        }

        return $this->saveNodeArray($node_array, $target_id, $target_pos, true);
    }
    // }}}
    // {{{ saveNodeIn
    protected function saveNodeIn($node, $target_id, $target_pos, $inc_children)
    {
        $parent_id = $this->getParentIdById($target_id);

        // delete child nodes, if target is document
        if ($parent_id === false) {
            $this->pdo->exec('SET foreign_key_checks = 0;');

            try {
                $query = $this->pdo->prepare(
                    "DELETE
                    FROM {$this->table_xml}
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

        $position = $this->getTargetPos($target_id);

        if ($position) {
            if ($target_pos > $position || $target_pos == -1) {
                $target_pos = $position;
            }
        } else {
            $target_pos = 0;
        }

        // get all nodes in array
        $node_array = [];
        $this->getNodeArrayForSaving($node_array, $node);

        return $this->saveNodeArray($node_array, $target_id, $target_pos, $inc_children);
    }
    // }}}
    // {{{ saveNodeArray
    protected function saveNodeArray($node_array, $target_id, $target_pos, $inc_children)
    {
        $ids = [];

        foreach ($node_array as $node) {
            if ($node['id']) {
                $ids[] = $node['id'];
            }
        }

        $this->getFreeNodeIds($ids);

        foreach ($node_array as $i => $node) {
            if (!is_null($node['id'])) {
                $index = array_search($node['id'], $this->free_element_ids);

                if ($index === false) {
                    $node_array[$i]['id'] = null;
                } else {
                    array_splice($this->free_element_ids, $index, 1);
                }
            }
        }

        foreach ($node_array as $i => $node) {
            if (is_null($node['id'])) {
                $node_array[$i]['id'] = array_shift($this->free_element_ids);
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

        $this->updateLastChange();

        return $node_array[0]['id'];
    }
    // }}}
    // {{{ saveNodesByType
    protected function saveNodesByType(&$node_array, $xml_element_node)
    {
        $batch = new Helpers\NodeBatch($this->pdo, $this->table_xml, (int) $this->doc_id, self::SAVE_BATCH_SIZE);

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

                [$type, $name, $value] = $this->getNodeTypeData($node['node']);
                $value = \Normalizer::normalize($value);

                $batch->add($i, $node['id'], $parent_id, ($node['pos'] + 1) * self::POS_GAP, $name, $value, $type);

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
     * @protected
     *
     * @param    $node_array (array) list of nodes to add current node to
     * @param    $node (domxmlnode) current node
     * @param    $parent_index (int) index of parent node in created node list
     * @param    $pos (int) position of current node
     * @param    $stripwhitespace (bool) whether to strip whitespace from
     *           textnodes while saving
     */
    protected function getNodeArrayForSaving(&$node_array, $node, $parent_index = null, $pos = 0, $stripwhitespace = true)
    {
        if (!$node) {
            return;
        }
        $type = $node->nodeType;

        if ($type === XML_DOCUMENT_NODE) {
            $root_node = $node->documentElement;
            $this->getNodeArrayForSaving($node_array, $root_node, $parent_index, $pos, $stripwhitespace);
        } elseif ($type === XML_ELEMENT_NODE) {
            $id = $this->getNodeId($node);
            $node_array[] = [
                'id' => $id,
                'id_old' => $id,
                'parent_index' => $parent_index,
                'pos' => $pos,
                'node' => $node,
            ];

            $parent_index = count($node_array) - 1;
            $node_name = (($node->prefix != '') ? $node->prefix . ':' : '') . $node->localName;

            $preserveWhitespace = $this->getDoctypeHandler()->getPreserveWhitespace();

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
     * @param    $target_pos (int) position to save node at
     * @param    $target_doc (int) doc-id of target document
     * @param    $increase_pos (bool) whether to change positions in target nodes childlist
     *
     * @return    $id (int) db-id under which node has been saved
     */
    protected function saveNodeToDb($node, $id, $target_id, $target_pos, $increase_pos = false)
    {
        if (is_null($this->insertNodeQuery)) {
            $this->insertNodeQuery = $this->pdo->prepare(
                "INSERT {$this->table_xml}
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

        if ($node->nodeType == XML_ELEMENT_NODE && $target_id !== null && $increase_pos) {
            // sparse positioning: the new node gets a position
            // between its future neighbours, the other children keep
            // theirs
            $target_pos = $this->positionForIndex($target_id, $target_pos);
        }

        // same tupel getNodeTypeData calculates the subhashes from
        [$node_type, $node_name, $node_data] = $this->getNodeTypeData($node);
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

    // {{{ beginTransactionAltering
    public function beginTransactionAltering()
    {
        return $this->xmlDb->beginTransactionAltering();
    }
    // }}}
    // {{{ beginTransactionNonAltering
    public function beginTransactionNonAltering()
    {
        return $this->xmlDb->beginTransactionNonAltering();
    }
    // }}}
    // {{{ endTransaction
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
            $this->clearCache();
            $this->getDoctypeHandler()->onDocumentChange();
        }

        return $altered;
    }
    // }}}
    // {{{ rollBackTransaction
    public function rollBackTransaction()
    {
        return $this->xmlDb->rollBackTransaction();
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

        $query = $this->pdo->prepare(
            "UPDATE {$this->table_docs}
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
            $user_id = $uid;
        } elseif (!empty($this->xmlDb->options['userId'])) {
            $user_id = $this->xmlDb->options['userId'];
        } else {
            $user_id = null;
        }

        $params = [
            'doc_id' => $this->getDocId(),
            'timestamp' => date('Y-m-d H:i:s', $timestamp),
            'user_id' => $user_id,
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
        $pad = self::SORTKEY_PAD;

        $query = $this->pdo->prepare(
            "WITH RECURSIVE tree (id, id_parent, lvl, sortkey) AS
                (
                SELECT id, id_parent, 0, CAST('' AS CHAR(5000))
                    FROM {$this->table_xml} AS xml
                    WHERE id_doc = :doc_id AND id_parent IS NULL
                UNION ALL
                SELECT x.id, x.id_parent, lvl + 1, CONCAT(sortkey, LPAD(IFNULL(x.pos + 1, 0), {$pad}, '0'))
                    FROM {$this->table_xml} AS x INNER JOIN tree AS t
                    ON x.id_parent = t.id
                )
                SELECT xml.id, xml.id_parent, xml.type, xml.name, xml.value, xml.subhash
                FROM tree JOIN {$this->table_xml} AS xml ON tree.id = xml.id
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
        $subhashes = new Helpers\CaseUpdate($this->pdo, $this->table_xml, self::SAVE_BATCH_SIZE);

        while ($row = $query->fetchObject()) {
            $hash = $this->getNodeHash($row->type, $row->name, $row->value, $child_hashes[$row->id] ?? []);

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
    // {{{ getNodeHash
    /**
     * canonical hash over one node row and the hashes of its children;
     * the "v1" prefix pins the canonicalization, changing the format
     * invalidates all stored hashes on the next recalculation
     *
     * @param $type (string) node type as stored in the database
     * @param $name (string) node name as stored in the database
     * @param $value (string) node value as stored in the database
     * @param $child_hashes (array) hashes of the children in document order
     */
    protected function getNodeHash($type, $name, $value, array $child_hashes)
    {
        return hash('sha256', 'v1|' . $type . '|' . $name . '|' . $value . '|' . implode('|', $child_hashes));
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
        if (!is_null($this->doc_id)) {
            $this->cache->delete("{$this->table_docs}_d{$this->doc_id}/");
        }
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
