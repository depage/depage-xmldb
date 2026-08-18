<?php

namespace Depage\XmlDb;

/**
  * @file    IndexerResult.php
  *
  * The IndexerResult class holds a single value resolved by the Indexer from an
  * xpath query. It carries the document id, the node id, the language and the
  * resolved value. The value is either the attribute value when the xpath targets
  * an attribute, or the text content (innerText) of the node when it targets an
  * element.
  *
  * copyright (c) 2026 Frank Hellenkamp [jonas@depage.net]
  * author    Frank Hellenkamp
  */
class IndexerResult
{
    // {{{ properties
    public readonly int $docId;
    public readonly int $nodeId;
    public readonly ?string $lang;
    public readonly string $value;
    // }}}

    // {{{ __construct
    /**
     * @param   int      $docId   id of the owning document
     * @param   int      $nodeId  database id of the resolved node
     * @param   string   $lang    language of the node, null when not set
     * @param   string   $value   resolved attribute value or node text content
     */
    public function __construct(int $docId, int $nodeId, ?string $lang, string $value)
    {
        $this->docId  = $docId;
        $this->nodeId = $nodeId;
        $this->lang   = $lang;
        $this->value  = $value;
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
