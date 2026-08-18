<?php

namespace Depage\XmlDb;

/**
  * @file    Indexer.php
  *
  * The Indexer class resolves xpath queries to IndexerResult objects. It is
  * used to index the database and to retrieve values from it.
  *
  * For attributes it returns the value of the attribute, for elements it returns
  * the text content (innerText) of the element. The xpath targets an attribute
  * when its last path step is an attribute reference (".../@name"), otherwise the
  * element itself is targeted. When the targeted attribute is not present on a
  * node, that node yields no result; when an element's targeted attribute is
  * empty, it yields an empty string value.
  *
  * The Indexer does not build SQL itself. It reuses the node resolution of
  * XmlDb and value resolution of Document.
  *
  * copyright (c) 2026 Frank Hellenkamp [jonas@depage.net]
  * author    Frank Hellenkamp
  */
class Indexer
{
    // {{{ variables
    protected XmlDb $xmlDb;
    // }}}

    // {{{ __construct
    /**
     * @param   XmlDb  $xmlDb  the xmlDb instance to resolve against
     */
    public function __construct(XmlDb $xmlDb)
    {
        $this->xmlDb = $xmlDb;
    }
    // }}}

    // {{{ query
    /**
     * resolves an xpath query to a list of IndexerResult objects
     *
     * @param   string  $xpath  xpath to target node or attribute
     * @param   int     $docId  optional id of document to restrict to
     *
     * @return  IndexerResult[]  array of resolved values, empty when no node
     *                          matches or the targeted attribute is absent
     */
    public function query(string $xpath, ?int $docId = null): array
    {
        [$elementXpath, $attrName] = $this->splitAttribute($xpath);

        $nodeIds = $this->xmlDb->getNodeIdsByXpath($elementXpath, $docId);
        $nodeIds = is_array($nodeIds) ? $nodeIds : [];

        $results = [];

        foreach ($nodeIds as $nodeId) {
            $doc = is_null($docId)
                ? $this->xmlDb->getDocByNodeId($nodeId)
                : $this->xmlDb->getDoc($docId);

            if ($doc === false) {
                continue;
            }

            $lang = $doc->getAttribute($nodeId, 'lang');
            $lang = ($lang === false) ? null : $lang;

            if (is_null($attrName)) {
                $subdoc = $doc->getSubdocByNodeId($nodeId, false);
                $value = $subdoc ? (string) $subdoc->textContent : '';
            } else {
                // a missing attribute yields no result at all
                $value = $doc->getAttribute($nodeId, $attrName);

                if ($value === false) {
                    continue;
                }
            }

            $results[] = new IndexerResult(
                $doc->getDocId(),
                $nodeId,
                $lang,
                $value,
            );
        }

        return $results;
    }
    // }}}

    // {{{ splitAttribute
    /**
     * detects whether the xpath targets an attribute and separates the
     * attribute reference from the element xpath.
     *
      * The xpath targets an attribute when its last path step is an attribute
      * reference (".../@name"), optionally followed by a predicate. Attribute
      * references inside a predicate ("...[@name]") are not mistaken for a
      * targeted attribute, because the trailing reference must be preceded by a
      * '/' and terminate the path.
      *
      * @param   string  $xpath  xpath to analyze
      *
      * @return  array  [string $elementXpath, string|null $attrName]
      */
    protected function splitAttribute(string $xpath): array
    {
        $attrName = null;
        $elementXpath = $xpath;

        // a trailing "/@name" reference (preceded by '/', at the end of the
        // path) targets the attribute; the remaining path is the element
        if (preg_match('/^(.+?)\/@([\w\-.:]+)(?:\[[^\]]*\])?\s*$/', $xpath, $matches)) {
            $attrName = $matches[2];
            $elementXpath = $matches[1];
        }

        return [$elementXpath, $attrName];
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
