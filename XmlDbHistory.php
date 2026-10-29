<?php

/**
 * @file    modules/XmlDb/XmlDbHistory.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2014 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb;

class XmlDbHistory implements Interfaces\XmlGetter
{
    // {{{ variables
    protected $pdo;
    protected $xmlDb;
    protected $dbNs;
    protected $tableHistory;

    protected $docIds = [];

    protected $tablePrefix = 'dp_';
    protected $tableDocs;

    protected $options;

    public $timestamp = null;
    // }}}

    // {{{ constructor
    /**
     * creates an instance of the read only view on the published versions
     *
     * @param $tablePrefix (string) prefix of the tables of the library
     * @param $pdo (Depage\Db\Pdo) database connection
     * @param $cache (Depage\Cache\Cache) cache instance
     * @param $options (array) options of the instance
     */
    public function __construct($tablePrefix, $pdo, $cache, $options = [])
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_NATURAL);

        $this->xmlDb = new XmlDb($tablePrefix, $pdo, $cache, $options);
        $this->dbNs = new XmlNs('db', 'http://cms.depagecms.net/ns/database');

        $this->tablePrefix = $tablePrefix;
        $this->tableDocs = $tablePrefix . '_xmldocs';
        $this->tableHistory = $tablePrefix . '_history';
    }
    // }}}

    // {{{ docExists
    /**
     * gets the doc-id of a xml-document by name or id and checks if the
     * document has at least one version in the history
     *
     * @param $docIdOrName (mixed) id or name of the document
     *
     * @return int|false id of the document or false when the document does
     *         not exist or has no version
     */
    public function docExists($docIdOrName)
    {
        $result = false;

        $id = $this->xmlDb->docExists($docIdOrName);

        $query = $this->pdo->prepare("
            SELECT doc_id
            FROM {$this->tableHistory}
            WHERE doc_id = :id
            LIMIT 1
        ");

        $query->execute([
            'id' => $id,
        ]);

        if ($query->fetch()) {
            $result = $id;
        }

        return $result;
    }
    // }}}
    // {{{ getDocInfo()
    /**
     * gets the info of a document
     *
     * @param $docIdOrName (mixed) id or name of the document
     *
     * @return object|false the info of the document or false if it does not
     *         exist
     **/
    public function getDocInfo($docIdOrName)
    {
        return $this->xmlDb->getDocInfo($docIdOrName);
    }
    // }}}
    // {{{ getDocXml
    /**
     * gets a published version of a document as a DOMDocument
     *
     * if the property "timestamp" is set, the version of that timestamp is
     * returned, otherwise the version that was published last
     *
     * @param $docIdOrName (mixed) id or name of the document
     * @param $addIdAttribute (bool) whether to add the db:id attributes
     *
     * @return bool|\DOMDocument
     */
    public function getDocXml($docIdOrName, $addIdAttribute = true)
    {
        $xml = false;

        if ($docId = $this->docExists($docIdOrName)) {
            $doc = new Document($this, $docId);
            $history = $doc->getHistory();

            if (!is_null($this->timestamp)) {
                $xml = $history->getXml($this->timestamp, $addIdAttribute);
            } else {
                $xml = $history->getLastPublishedXml($addIdAttribute);
            }
        }

        return $xml;
    }
    // }}}
    // {{{ getDocXmlXpath
    /**
     * gets the first node of a published version of a document that matches
     * an xpath query
     *
     * @param $docIdOrName (mixed) id or name of the document
     * @param $xpath (string) xpath to target node
     * @param $addIdAttribute (bool) whether to add the db:id attributes
     *
     * @return bool|\DOMDocument
     */
    public function getDocXmlXpath($docIdOrName, $xpath, $addIdAttribute = true)
    {
        $xml = false;

        if ($docId = $this->docExists($docIdOrName)) {
            $xmlFull = $this->getDocXml($docIdOrName, $addIdAttribute);
            if ($xmlFull === false) {
                throw new \Exception("Document could not be read from history");
            }

            $domXpath = new \DomXpath($xmlFull);
            $list = $domXpath->query($xpath);

            if ($list->length > 0) {
                $xml = new \Depage\Xml\Document();
                $xml->appendChild($xml->importNode($list->item(0), true));

                return $xml;
            }
        }

        return $xml;
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
    // {{{ __sleep()
    /**
     * allows Depage\Db\Pdo-object to be serialized
     */
    public function __sleep()
    {
        return [
            'pdo',
            'xmlDb',
            'dbNs',
            'tablePrefix',
            'tableDocs',
            'tableHistory',
            'options',
        ];
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
