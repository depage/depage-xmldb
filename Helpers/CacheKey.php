<?php

/**
 * @file    Helpers/CacheKey.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2026 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb\Helpers;

/**
 * @internal building the keys of the node-cache; not part of the public
 * api of this library
 */
class CacheKey
{
    // {{{ forDocument
    /**
     * cache namespace of a document; all cached entries of a document
     * (its subdocuments and xpath results) live below this prefix, so
     * deleting the prefix drops all of them
     */
    public static function forDocument(string $tableDocs, $docId): string
    {
        return "{$tableDocs}_d{$docId}/";
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
