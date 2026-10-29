<?php

namespace Depage\XmlDb\Interfaces;

/**
 * Interface of a read only accessor for xml documents
 *
 * It is implemented by XmlDb, which reads the current state of the documents,
 * and by XmlDbHistory, which reads the published versions of the documents.
 */
interface XmlGetter
{
    /**
     * gets the id of a document and checks whether it exists
     *
     * @param   mixed  $doc_id_or_name  id or name of the document
     *
     * @return  int|false  id of the document or false if it does not exist
     */
    public function docExists($doc_id_or_name);
    /**
     * gets a document as a DOMDocument
     *
     * @param   mixed  $doc_id_or_name     id or name of the document
     * @param   bool   $add_id_attribute   whether to add the db:id attributes
     *
     * @return  \DOMDocument|false  the document or false if it does not exist
     */
    public function getDocXml($doc_id_or_name, $add_id_attribute = true);
    /**
     * gets the first node of a document that matches an xpath query
     *
     * @param   mixed   $doc_id_or_name    id or name of the document
     * @param   string  $xpath             xpath to target node
     * @param   bool    $add_id_attribute  whether to add the db:id attributes
     *
     * @return  \DOMDocument|false  the node or false if nothing matches
     */
    public function getDocXmlXpath($doc_id_or_name, $xpath, $add_id_attribute = true);
}
