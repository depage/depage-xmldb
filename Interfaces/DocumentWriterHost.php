<?php

/**
 * @file    Interfaces/DocumentWriterHost.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2026 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb\Interfaces;

/**
 * what the write pipeline (Helpers\DocumentWriter) needs from the
 * document it writes: the document metadata and change tracking, the
 * doctype handler and the read path.
 *
 * The transaction itself stays with the document; the writer runs
 * inside whatever transaction the document opened.
 */
interface DocumentWriterHost
{
    /**
     * number of decimal digits used for the position part of the recursive
     * CTE sortkey; limits the tree depth the recursive queries can order
     * to strlen-limit / SORTKEY_PAD levels
     */
    public const SORTKEY_PAD = 20;

    /**
     * maximum number of nodes written with a single statement; the write
     * pipeline inserts nodes in batches of this size (the auto
     * incremented ids of such a batch are read back with one select over
     * the unique sibling index), the subhash refresh and the position
     * rebalancing write their rows in batches of this size
     */
    public const SAVE_BATCH_SIZE = 200;

    /**
     * marks the document as changed; has to be called after every write
     */
    public function updateLastChange($timestamp = null, $uid = null);

    /**
     * db-id of the parent of a node, false if the node does not exist
     */
    public function getParentIdById($id);

    /**
     * the doctype handler governing this document
     */
    public function getDoctypeHandler();

    /**
     * reads a node's subtree as a document (used to copy nodes)
     */
    public function getSubdocByNodeId($id, $addIdAttribute = true);
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
