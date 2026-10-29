<?php

/**
 * @file    modules/xmlDb/xmlDb.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2014 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 *
 */

namespace Depage\XmlDb;

use Depage\XmlDb\Exceptions\XmlDbException;

class XmlDb implements Interfaces\XmlGetter
{
    // {{{ variables
    public $pdo;
    protected $cache;
    protected $dbNs;

    protected $tablePrefix = 'dp_';
    protected $tableDocs;
    protected $tableXml;

    protected $docIds = [];

    protected $transactions = 0;
    protected $alteringTransaction;

    public $options;
    // }}}
    // {{{ constructor
    /**
     * creates an instance of the xmlDb
     *
     * @param $tablePrefix (string) prefix of the tables of the library
     * @param $pdo (Depage\Db\Pdo) database connection
     * @param $cache (Depage\Cache\Cache) cache instance
     * @param $options (array) options of the instance, the option "userId"
     *        is used as the id of the user that changes the documents
     */
    public function __construct($tablePrefix, $pdo, $cache, $options = [])
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_NATURAL);

        $this->cache = $cache;

        $this->options = $options;

        $this->dbNs = new XmlNs('db', 'http://cms.depagecms.net/ns/database');

        $this->tablePrefix = $tablePrefix;
        $this->tableDocs = $tablePrefix . '_xmldocs';
        $this->tableXml = $tablePrefix . '_xmltree';
    }
    // }}}

    // {{{ __get
    /**
     * Get properties (basically read-only)
     *
     * @param $property
     * @return mixed
     */
    public function __get($property)
    {
        if (property_exists($this, $property)) {
            return $this->$property;
        }
    }
    // }}}

    // {{{ docExists
    /**
     * gets the doc-id of a xml-document by name or id and checks if the document exists
     *
     * @param     $docIdOrName (mixed) id or name of the document
     *
     * @return    (int) id of the document or false when document does not exist
     */
    public function docExists($docIdOrName)
    {
        if (!isset($this->docIds[$docIdOrName])) {
            if ((int) $docIdOrName > 0) {
                $id = (int) $docIdOrName;

                // is already a doc-id
                $query = $this->pdo->prepare(
                    "SELECT docs.name AS docname
                    FROM {$this->tableDocs} AS docs
                    WHERE docs.id = :doc_id"
                );
                $query->execute([
                    'doc_id' => $id,
                ]);

                $result = $query->fetchObject();
                if ($result === false) {
                    // document does not exist
                    return false;
                }

                $name = $result->docname;
            } else {
                $name = $docIdOrName;
                $docList = $this->getDocuments($name);

                if (!isset($docList[$name])) {
                    // document does not exist
                    return false;
                }

                $id = $docList[$name]->getDocId();
            }

            $this->docIds[$id] = $id;
            $this->docIds[$name] = $id;
        }

        return $this->docIds[$docIdOrName];
    }
    // }}}

    // {{{ getDocuments
    /**
     * gets available documents in database
     *
     * @param $name (string) optional name of the documents to get
     * @param $type (string) optional class name of the doctype handler of
     *        the documents to get
     *
     * @return    $docs (array) the key is the name of the document, the value is the document db-id.
     */
    public function getDocuments($name = "", $type = "")
    {
        $docs = [];

        $namequery = '';
        $where = [];
        $queryParam = [];

        if ($name) {
            $where[] = 'name = :name';
            $queryParam['name'] = $name;
        }
        if ($type) {
            $where[] = 'type = :type';
            $queryParam['type'] = $type;
        }
        if (count($where) > 0) {
            $namequery = "WHERE " . implode(" AND ", $where);
        }


        $query = $this->pdo->prepare(
            "SELECT
                docs.name AS name,
                docs.id AS id,
                docs.rootid AS rootid,
                docs.type AS type
            FROM {$this->tableDocs} AS docs
                $namequery
            ORDER BY docs.name ASC"
        );

        $query->execute($queryParam);

        while ($doc = $query->fetchObject()) {
            $docs[$doc->name] = new Document($this, $doc->id);
        }

        return $docs;
    }
    // }}}
    // {{{ getDoc
    /**
     * Get xmlDb\document
     *
     * @param $docIdOrName (mixed) id or name of the document
     * @return bool|document
     */
    public function getDoc($docIdOrName)
    {
        $doc = false;

        if ($docId = $this->docExists($docIdOrName)) {
            $doc = new Document($this, $docId);
        }

        return $doc;
    }
    // }}}
    // {{{ getDocByNodeId
    /**
     * Get xmlDb\document
     *
     * @param $nodeId (int) db-id of a node of the document
     * @return bool|document
     */
    public function getDocByNodeId($nodeId)
    {
        $doc = false;

        $query = $this->pdo->prepare(
            "SELECT xml.id_doc AS id_doc
            FROM {$this->tableXml} AS xml
            WHERE xml.id = :nodeId"
        );

        $query->execute([
            'nodeId' => $nodeId,
        ]);
        $result = $query->fetchObject();

        if ($result && $docId = $this->docExists($result->id_doc)) {
            $doc = new Document($this, $docId);
        }

        return $doc;
    }
    // }}}
    // {{{ getDocInfo()
    /**
     * @brief getDocInfo
     *
     * @param mixed $docIdOrName
     * @return void
     **/
    public function getDocInfo($docIdOrName)
    {
        if ($doc = $this->getDoc($docIdOrName)) {
            return $doc->getDocInfo();
        }

        return false;
    }
    // }}}

    // {{{ getDocXml
    /**
     * gets a document as a DOMDocument
     *
     * @param $docIdOrName (mixed) id or name of the document
     * @param $addIdAttribute (bool) whether to add the db:id attributes
     * @return bool|\DOMDocument
     */
    public function getDocXml($docIdOrName, $addIdAttribute = true)
    {
        $xml = false;

        if ($docId = $this->docExists($docIdOrName)) {
            $doc = new Document($this, $docId);
            $xml = $doc->getXml($addIdAttribute);
        }

        return $xml;
    }
    // }}}
    // {{{ getDocXmlXpath
    /**
     * gets the first node of a document that matches an xpath query
     *
     * @param $docIdOrName (mixed) id or name of the document
     * @param $xpath (string) xpath to target node
     * @param $addIdAttribute (bool) whether to add the db:id attributes
     * @return bool|\DOMDocument
     */
    public function getDocXmlXpath($docIdOrName, $xpath, $addIdAttribute = true)
    {
        $xml = false;

        if ($docId = $this->docExists($docIdOrName)) {
            $doc = new Document($this, $docId);
            $xml = $doc->getSubdocByXpath($xpath, $addIdAttribute);
        }

        return $xml;
    }
    // }}}

    // {{{ getSubdocByXpath
    /**
     * gets document by xpath. if xpath directs to more than
     * one node, only the first node will be returned.
     *
     * @param   $xpath (string) xpath to target node
     * @param   $addIdAttribute (bool) whether to add db:id attribute or not
     *
     * @return  $doc (domxmlobject)
     */
    public function getSubdocByXpath($xpath, $addIdAttribute = true)
    {
        $subdoc = false;
        $ids = $this->getNodeIdsByXpath($xpath);

        if (isset($ids[0])) {
            $subdoc = $this->getDocByNodeId($ids[0])->getSubdocByNodeId($ids[0], $addIdAttribute);
        }

        return $subdoc;
    }
    // }}}
    // {{{ getNodeIdsByXpath
    /**
     * gets node_ids by xpath
     *
     * @attention this supports only a small subset of xpath-queries. so recheck source before using.
     *
     * @param   $this->docId (int) id of document
     * @param   $xpath (string) xpath to target node
     *
     * @return  $nodeids (array) array of found node ids
     *
     * @todo    implement full xpath specifications
     */
    public function getNodeIdsByXpath($xpath, $docId = null)
    {
        $fallback = false;
        $tableSql = [];
        $params = [];
        $condSql = [];
        $parser = new XpathParser();
        $xpathElements = $parser->parseXpathElements($xpath);
        $levels = count($xpathElements) - 1;

        foreach ($xpathElements as $level => $element) {
            if ($level == 0) {
                $tableSql[] = "SELECT l$levels.id FROM";
                if ($element->divider == '/') {
                    $condSql[] = "l0.id_parent IS NULL";
                }
            } else {
                $tableSql[] = 'INNER JOIN';
                if ($element->divider == '/' && !$fallback) {
                    $parentLevel = $level - 1;
                    $condSql[] = "l$level.id_parent = l$parentLevel.id";
                } else {
                    $fallback = true;
                }
            }

            $tableSql[] = "{$this->tableXml} AS l$level";
            $op = $parser->getConditionOperator($element->namespace, $element->name);
            $condSql[] = "l$level.name $op :name$level";
            $params["name$level"] = $parser->translateName($element->namespace, $element->name);

            $hasPosition = $parser->parsePosition($element->condition);

            if ($hasPosition) {
                $fallback = true;
            } elseif ($element->condition != '') {
                if ($attributes = $parser->parseAttributes($element->condition)) {
                    // fetch by simple attributes: "ns:name[@attr1] ..."
                    $attributeCond = '';
                    foreach ($attributes as $i => $attribute) {
                        [$name, $operator, $value, $bool] = $attribute;

                        if ($bool) {
                            $attributeCond .= $parser->cleanOperator($bool);
                        }

                        if ($name == 'db:id') {
                            $attributeOperator = $parser->cleanOperator($operator);
                            $attributeCond .= " l$level.id {$parser->cleanOperator($operator)} :attr{$level}n{$i} ";
                            $params["attr{$level}n{$i}"] = $value;
                        } elseif ($operator == '=' || $operator == '') {
                            $attributeCond .= " l$level.value REGEXP :attr{$level}n{$i} ";
                            $regExValue = (is_null($value)) ? '.*' : $value;
                            $params["attr{$level}n{$i}"] = "(^| )$name=\"$regExValue\"( |$)";
                        } else {
                            $fallback = true;
                        }
                    }

                    if (!empty($attributeCond)) {
                        $condSql[] = $attributeCond;
                    }
                } else {
                    $fallback = true;
                }
            }

            if ($level == 0 && !is_null($docId)) {
                $condSql[] = "l$level.id_doc = :docid$level";
                $params["docid$level"] = $docId;
            }
        }

        $ids = [];

        if ($xpathElements) {
            if ($fallback) {
                $tableSql[0] = "SELECT DISTINCT l$level.id_doc FROM";
            }
            $where = '';
            if (!empty($condSql)) {
                $where = ' WHERE (' . implode(') AND (', $condSql) . ')';
            }

            $sql = implode(' ', $tableSql) . "$where ORDER BY l$level.id_parent, l$level.pos";

            $query = $this->pdo->prepare($sql);
            $query->execute($params);


            foreach ($query->fetchAll() as $result) {
                $ids[] = $result[0];
            }
        } else {
            $fallback = true;

            foreach ($this->getDocuments() as $doc) {
                $ids[] = $doc->getDocId();
            }
        }

        if ($fallback) {
            if (is_null($docId)) {
                $docIds = $ids;
            } else {
                $docIds = [$docId];
            }
            $nodeIds = $this->getNodeIdsByXpathDom($xpath, $docIds);
        } else {
            $nodeIds = $ids;
        }

        return $nodeIds;
    }
    // }}}
    // {{{ getNodeIdsByXpathDom
    protected function getNodeIdsByXpathDom($xpath, $docs = [])
    {
        $ids = [];

        foreach ($docs as $doc) {
            $domXpath = new \DomXpath($this->getDoc($doc)->getXml());
            $list = $domXpath->query($xpath);
            foreach ($list as $item) {
                $ids[] = $item->attributes->getNamedItem('id')->nodeValue;
            }
        }

        return $ids;
    }
    // }}}

    // {{{ getIndexNodeData
    /**
     * loads the document id and the attributes of the given nodes with a
     * single query (used by the Indexer to resolve targeted attributes
     * and the lang attribute without a round trip per node); non element
     * nodes get an empty attribute map
     *
     * @param $nodeIds (array) database ids of the nodes
     *
     * @return array node id => [ 'doc_id' => int, 'attributes' => array ]
     */
    public function getIndexNodeData($nodeIds)
    {
        $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));

        $nodeData = [];
        if (empty($nodeIds)) {
            return $nodeData;
        }

        $query = $this->pdo->prepare(
            "SELECT xml.id, xml.id_doc, xml.type, xml.value
            FROM {$this->tableXml} AS xml
            WHERE xml.id IN (" . implode(", ", $nodeIds) . ")",
        );
        $query->execute();

        foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $row) {
            $nodeData[(int) $row->id] = [
                'doc_id' => (int) $row->id_doc,
                'attributes' => ($row->type == 'ELEMENT_NODE')
                    ? Document::parseAttributeString($row->value)
                    : [],
            ];
        }

        return $nodeData;
    }
    // }}}
    // {{{ getIndexTextContent
    /**
     * concatenates the descendant text nodes of the given nodes with a
     * single query, in document order; that is the text content
     * Document::getSubdocByNodeId assembles (processing instructions and
     * comments never contribute to textContent and entity references are
     * not emitted by getSubdocByNodeId either)
     *
     * @param $nodeIds (array) database ids of the nodes
     *
     * @return array node id => text content (nodes without text are
     *               absent from the result)
     */
    public function getIndexTextContent($nodeIds)
    {
        $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));

        $texts = [];
        if (empty($nodeIds)) {
            return $texts;
        }

        $pad = Document::SORTKEY_PAD;
        $query = $this->pdo->prepare(
            Helpers\TreeSql::descendants($this->tableXml, "id IN (" . implode(", ", $nodeIds) . ")", $pad) . "
            SELECT tree.root_id, xml.value
            FROM tree INNER JOIN {$this->tableXml} AS xml ON xml.id = tree.id
            WHERE xml.type = 'TEXT_NODE'
            ORDER BY tree.root_id ASC, tree.sortkey ASC",
        );
        $query->execute();

        foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $row) {
            $rootId = (int) $row->root_id;

            $texts[$rootId] = ($texts[$rootId] ?? '') . $row->value;
        }

        return $texts;
    }
    // }}}

    // {{{ createDoc
    /**
     * CreateDoc
     *
     * @param $doctype (string) class-name of doctype for new document
     * @param $docName (string) optional name of document
     *
     * @return Document
     *
     * @throws xmlDbException
     */
    public function createDoc($doctype = 'Depage\XmlDb\XmlDoctypes\Base', $docName = null)
    {
        if (is_null($docName)) {
            // generate generic docname based on doctype
            $docName = '_' . substr($doctype, strrpos($doctype, "\\") + 1) . '_' . sha1(uniqid(dechex(mt_rand(256, 4095))));
        }
        if (!is_string($docName) || $this->docExists($docName)) {
            throw new XmlDbException("Invalid or duplicate document name \"$docName\"");
        }

        $query = $this->pdo->prepare(
            "INSERT {$this->tableDocs} SET name = :name, type = :type, ns = '', entities = ''"
        );
        $query->execute([
            'name' => $docName,
            'type' => $doctype,
        ]);

        $docId = $this->pdo->lastInsertId();
        $document = new Document($this, $docId);

        return $document;
    }
    // }}}
    // {{{ duplicateDoc
    /**
     * @brief duplicateDoc
     *
     * @param $docNameOrId (mixed)
     * @param $newName (string) optional name for new document
     *
     * @return Document|bool
     **/
    public function duplicateDoc($docNameOrId, $newName = null)
    {
        $copy = false;
        $original = $this->getDoc($docNameOrId);

        if ($original !== false) {
            $info = $original->getDocInfo();
            $xml = $original->getXml(false);

            $copy = $this->createDoc($info->type, $newName);
            $copy->save($xml);
        }

        return $copy;
    }
    // }}}
    // {{{ removeDoc
    /**
     * @param $docIdOrName
     *
     * @return bool
     */
    public function removeDoc($docId)
    {
        $result = false;
        $docId = $this->docExists($docId);

        if ($docId !== false) {
            $query = $this->pdo->prepare(
                "DELETE
                FROM {$this->tableDocs}
                WHERE id = :doc_id"
            );
            $query->execute([
                'doc_id' => $docId,
            ]);

            $this->cache->delete(Helpers\CacheKey::forDocument($this->tableDocs, $docId));

            $this->docIds = array_filter(
                $this->docIds,
                function ($id) use ($docId) {
                    return $id != $docId;
                }
            );

            $result = true;
        }

        return $result;
    }
    // }}}

    // {{{ updateSchema
    /**
     * @brief updateSchema
     *
     * @param mixed
     *
     * @return void
     **/
    public function updateSchema()
    {
        $schema = new \Depage\Db\Schema($this->pdo);

        $pdoPrefix = $this->pdo->prefix;
        $tablePrefix = $this->tablePrefix;

        $schema->setReplace(
            function ($tableName) use ($pdoPrefix, $tablePrefix) {
                if ($tableName == '_auth_user') {
                    return $pdoPrefix . $tableName;
                } else {
                    return $tablePrefix . $tableName;
                }
            }
        );

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0;');

        // schema for xmlDb
        $schema->loadGlob(__DIR__ . '/Sql/*.sql');
        $schema->update();

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
    }
    // }}}
    // {{{ clearTables
    /**
     * Removes SQL tables
     */
    public function clearTables()
    {
        $this->pdo->query("DELETE FROM `{$this->tableDocs}`;");
        //$this->pdo->query("ALTER TABLE `{$this->tableDocs}` AUTO_INCREMENT = 1;");

        $this->docIds = [];
    }
    // }}}

    // {{{ beginTransactionAltering
    /**
     * opens a transaction that changes the documents
     *
     * the outermost level starts a database transaction, the inner levels
     * use savepoints
     *
     * @return int the level of the transaction
     */
    public function beginTransactionAltering()
    {
        $this->alteringTransaction = true;

        return $this->beginTransactionNonAltering();
    }
    // }}}
    // {{{ beginTransactionNonAltering
    /**
     * opens a transaction that only reads the documents
     *
     * the outermost level starts a database transaction, the inner levels
     * use savepoints
     *
     * @return int the level of the transaction
     */
    public function beginTransactionNonAltering()
    {
        if ($this->transactions == 0) {
            $this->beginPdoTransaction();
        } else {
            $this->createSavepoint($this->transactions);
        }
        $this->transactions++;

        return $this->transactions;
    }
    // }}}
    // {{{ endTransaction
    /**
     * closes the innermost open transaction level
     *
     * the outermost level commits the database transaction, an inner level
     * releases its savepoint
     *
     * @return bool true if the outermost transaction has changed the
     *         documents
     */
    public function endTransaction()
    {
        $altered = false;
        $this->transactions--;

        if ($this->transactions == 0) {
            $this->commitPdoTransaction();

            $altered = $this->alteringTransaction;
            $this->alteringTransaction = false;
        } else {
            $this->releaseSavepoint($this->transactions);
        }

        return $altered;
    }
    // }}}
    // {{{ rollBackTransaction
    /**
     * rolls back to the start of the innermost open transaction level,
     * keeping outer transaction levels (if any) intact. call from the
     * catch block of code that opened the transaction.
     *
     * @return bool true if a transaction level was rolled back, false if
     *         no transaction was open
     */
    public function rollBackTransaction()
    {
        if ($this->transactions == 0) {
            return false;
        }

        $this->transactions--;

        if ($this->transactions == 0) {
            $this->rollBackPdoTransaction();
            $this->alteringTransaction = false;
        } else {
            $this->rollbackToSavepoint($this->transactions);
            $this->releaseSavepoint($this->transactions);
        }

        return true;
    }
    // }}}
    // {{{ rollBack
    /**
     * resets the transaction state completely and rolls back the database
     * transaction; use to recover after a failure that left transactions
     * open.
     *
     * @return bool true if a database transaction was rolled back
     */
    public function rollBack()
    {
        $this->transactions = 0;
        $this->alteringTransaction = false;

        return $this->rollBackPdoTransaction();
    }
    // }}}

    // {{{ beginPdoTransaction
    protected function beginPdoTransaction()
    {
        $this->pdo->beginTransaction();
    }
    // }}}
    // {{{ commitPdoTransaction
    protected function commitPdoTransaction()
    {
        $this->pdo->commit();
    }
    // }}}
    // {{{ rollBackPdoTransaction
    protected function rollBackPdoTransaction()
    {
        $rolledBack = false;

        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $rolledBack = true;
        }

        return $rolledBack;
    }
    // }}}
    // {{{ createSavepoint
    protected function createSavepoint($level)
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->exec('SAVEPOINT xmldb_sp_' . (int) $level);
        }
    }
    // }}}
    // {{{ releaseSavepoint
    protected function releaseSavepoint($level)
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->exec('RELEASE SAVEPOINT xmldb_sp_' . (int) $level);
        }
    }
    // }}}
    // {{{ rollbackToSavepoint
    protected function rollbackToSavepoint($level)
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT xmldb_sp_' . (int) $level);
        }
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
