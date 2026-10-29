<?php

/**
 * @file    Helpers/TreeSql.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2026 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb\Helpers;

/**
 * @internal building the tree queries of the read and save paths; not
 * part of the public api of this library
 *
 * Builds the recursive common table expression that walks a document
 * tree in document order, so that the sortkey convention (the sortkey
 * padded with Document::SORTKEY_PAD digits per level) and the depth
 * limit of the recursion live in one place instead of in every query
 * that walks a tree.
 */
class TreeSql
{
    // {{{ descendants
    /**
     * builds the recursive common table expression selecting a node
     * matched by $anchor and all of its descendants; the rows of the
     * result are in document order (sortkey concatenates the positions
     * of all ancestors, so sorting by sortkey yields document order)
     *
     * the columns of the CTE are: root_id (the id of the node the walk
     * started at), id, id_parent, lvl and sortkey of every node below
     * (and including) the nodes matched by $anchorWhere
     *
     * @param $table (string) name of the xmltree table
     * @param $anchor (string) condition selecting the root node(s) the
     *        descendants are collected for
     * @param $pad (int) digits of the position part of the sortkey
     *        (Document::SORTKEY_PAD)
     */
    public static function descendants(string $table, string $anchorCondition, int $pad): string
    {
        return "WITH RECURSIVE tree (root_id, id, id_parent, lvl, sortkey) AS
            (
            SELECT x.id, x.id, x.id_parent, 0, CAST('' AS CHAR(5000))
                FROM {$table} AS x
                WHERE {$anchorCondition}
            UNION ALL
            SELECT tree.root_id, x.id, x.id_parent, tree.lvl + 1, CONCAT(tree.sortkey, LPAD(IFNULL(x.pos + 1, 0), {$pad}, '0'))
                FROM {$table} AS x INNER JOIN tree
                ON x.id_parent = tree.id
            )";
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
