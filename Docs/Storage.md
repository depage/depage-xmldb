# Storage Internals {#storage}

This page describes how a document is stored in the database. It is not needed
to use the library, but it helps to understand the behaviour of the write
path, of the queries and of the incremental saves.

## The tables

The library uses three tables, all of them derived from the table prefix of the
`XmlDb` object.

### `dp_xmldocs`

One row per document.

| Column | Content |
|---|---|
| `id` | id of the document |
| `name` | unique name of the document |
| `type` | class name of the doctype handler |
| `ns` | the namespaces that are used in the document |
| `entities` | the entities of the document |
| `rootid` | id of the root node |
| `lastchange` | time of the last change |
| `lastchange_uid` | id of the user who changed the document last |

### `dp_xmltree`

The nodes of all documents. Every row is one node.

| Column | Content |
|---|---|
| `id` | id of the node |
| `id_doc` | id of the document the node belongs to |
| `id_parent` | id of the parent node, `NULL` for the root node |
| `pos` | position of the node in the list of the children of its parent |
| `name` | name of the node, for example `pg:page` |
| `value` | the value of the node: the attributes of an element node, the text of a text node |
| `type` | `ELEMENT_NODE`, `TEXT_NODE`, `CDATA_SECTION_NODE`, `PI_NODE`, `COMMENT_NODE`, `ENTITY_REF_NODE`, `WAIT_FOR_REPLACE` or `DELETED` |
| `subhash` | merkle hash of the subtree of the node, `NULL` if it was not calculated yet |

The attributes of an element node are stored in its `value` column, as a
string of `name="value"` pairs, sorted by name:

```
file_type="html" name="home"
```

The attributes that the library generates itself (`db:id`, `db:released`,
`db:lastchange` and `db:lastchangeUid`) are not part of that string.

### `dp_history`

The versions of the documents: the document id, the hash of the content, the
XML of the version, the times of the first and the last save, the id of the
user who saved it and the published flag.

There is a fourth table, `dp_xmldeltaupdates`, which is left over from an
earlier version of the library. It is not written any more.

## Reading a tree

The nodes of a document are read with a recursive common table expression that
walks the tree in document order. The order is built from the positions of the
nodes: every level adds the position of the node, padded to
`Document::SORTKEY_PAD` (20) digits, to the sortkey of its parent.

```sql
WITH RECURSIVE tree (root_id, id, id_parent, lvl, sortkey) AS
(
    SELECT x.id, x.id, x.id_parent, 0, CAST('' AS CHAR(5000))
        FROM dp_xmltree AS x
        WHERE id = :id AND id_doc = :doc_id
    UNION ALL
    SELECT tree.root_id, x.id, x.id_parent, tree.lvl + 1,
            CONCAT(tree.sortkey, LPAD(IFNULL(x.pos + 1, 0), 20, '0'))
        FROM dp_xmltree AS x INNER JOIN tree
        ON x.id_parent = tree.id
)
```

The query is built by `Helpers\TreeSql`, so that the sortkey convention lives
in one place.

## Sparse positions

The position of a node in the child list of its parent is stored in the `pos`
column. The positions are **sparse**: the children of a node are spaced
`Document::POS_GAP` (65536) apart, and a node that is inserted between two
neighbours takes the midpoint of their positions.

```text
0            65536            131072
page         folder           page
```

Inserting a node in the middle of such a list therefore does not touch any
other row:

```text
0            32768            65536            131072
page         new page         folder           page
```

Only when the space between two neighbours is exhausted — the distance drops
below `Document::POS_MIN_GAP` (16) — the child list is rebalanced and all its
positions are written again.

A unique index on `(id_doc, id_parent, pos)` makes sure that two siblings
never share a position.

## Merkle hashes

The `subhash` column holds a hash over the content of a node and the hashes
of all its children:

```
sha256('v1|' + type + '|' + name + '|' + value + '|' + hashes of the children)
```

The hash is calculated over the values as they are stored in the database, so
that the hash of a stored node and the hash of a node that is about to be
written are the same. The values are normalized to NFC before they are stored
and before they are hashed.

The hash does **not** contain the id of the node, so two equal subtrees always
have the same hash, no matter where they are in the document.

The hashes are recalculated when a document is changed, inside of the
transaction that changes it, and only for the nodes whose hash really changed.

## Incremental saves

`savePatch()` and `saveNodePatch()` use the hashes to find the parts of the
document that did not change:

1. The new document is flattened into a list of nodes.
2. The stored tree of the document is read.
3. The hashes of the new tree are calculated, bottom up.
4. The new nodes are matched with the stored nodes, first by their `db:id`,
   then by their position among the remaining children of their parent.
5. A subtree whose hash equals the stored hash is skipped completely, without
   looking at its content.
6. The nodes that differ are updated, the new ones are inserted and the
   removed ones are deleted.

The plan is rejected — and a full save is done instead — when

- the root node was replaced,
- the document carries no database ids at all,
- more than `Document::PATCH_MAX_CHANGE_RATIO` (40 %) of the nodes changed.

The planning is done by `Patch\PatchPlanner`, the writing by
`Patch\PatchApplier`. Both of them are internal, the public interface is
`Document::savePatch()` and `Document::saveNodePatch()`.

`Document::getLastPatchStats()` returns the number of nodes that were skipped,
updated, inserted or deleted by the last call.

## Batched writes

Rows are not written one by one:

- `Helpers\NodeBatch` collects the new nodes and writes them with multi-row
  `INSERT` statements. The ids of the new rows are read back with
  `INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` and the
  unique index on `(id_doc, id_parent, pos)`.
- `Helpers\CaseUpdate` collects the changed values of a column and writes them
  with a single `UPDATE ... SET column = CASE id WHEN ? THEN ? ... END WHERE
  id IN (...)` statement.

The size of the batches is `Document::SAVE_BATCH_SIZE` (200) rows.

## The cache

The cache of the library has one namespace per document:

```
{dp_xmldocs}_d{doc_id}/
```

Below it the subdocuments and the results of the queries are cached. Because
the cached subdocuments contain the `db:lastchange` attribute of the document,
the whole namespace is dropped when the document changes, not only the entries
of the changed nodes.

The namespace is built by `Helpers\CacheKey`.

## Transactions

The transaction state is kept by the `XmlDb` object, the documents share it:

- the outermost level starts a database transaction,
- every inner level creates a savepoint (`xmldb_sp_<level>`),
- the outermost level commits, an inner level releases its savepoint,
- `rollBackTransaction()` rolls back to the savepoint of the innermost level
  and keeps the outer levels intact,
- `rollBack()` resets the state completely and rolls the whole transaction
  back.

The hashes of the document are recalculated before the outermost transaction is
committed, so that the recalculation is part of the same transaction.

## Identifiers

Table and column names cannot be bound as parameters of a query, so they are
checked before they are used: `Helpers\IdentifierChecker` rejects everything
that is not a plain identifier with an `XmlDbException`.

## Schema updates

The schema is defined by the SQL files in the `Sql` directory. They are read
by `Depage\Db\Schema`, which applies the statements of a file in the order of
their version numbers:

```
Sql/01_xmldocs.sql
Sql/02_xmltree.sql
Sql/03_xmldeltaupdates.sql
Sql/04_history.sql
```

`XmlDb::updateSchema()` loads all of them and updates the database. It is
idempotent and can be called on every deployment.

See also the [getting started](Introduction.md) and the
[doctype handlers](Doctypes.md).
