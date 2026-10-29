# Getting Started {#introduction}

depage-xmldb stores XML documents in a relational database and gives you an
object oriented API to read, query and modify them.

This page walks through the setup and through the everyday operations. The
[XPath dialect](Xpath.md), the [doctype handlers](Doctypes.md) and the
[version history](History.md) are described on their own pages.

## Installation

```sh
composer require depage/xmldb
```

The library needs PHP 8.3 or newer and MySQL 8.0 or MariaDB 10.2 or newer.
It builds on three sibling libraries, which are installed as dependencies:

| Library | Purpose |
|---|---|
| [depage/db](https://github.com/depage/depage-db) | PDO wrapper and schema migration |
| [depage/xml](https://github.com/depage/depage-xml) | serializable `DOMDocument` subclass |
| [depage/cache](https://github.com/depage/depage-cache) | cache with interchangeable backends |

## Setting up the XmlDb instance

```php
use Depage\Cache\Cache;
use Depage\Db\Pdo;
use Depage\XmlDb\XmlDb;

$pdo = new Pdo('mysql:host=localhost;dbname=depage', 'user', 'password', ['prefix' => 'dp_']);
$cache = Cache::factory('xmldb', ['disposition' => 'file', 'cachepath' => '/var/cache/depage']);

$xmlDb = new XmlDb('dp_', $pdo, $cache);
```

The first argument of the constructor is the table prefix. The library uses
three tables, which are derived from it:

| Table | Content |
|---|---|
| `dp_xmldocs` | one row per document: name, doctype, root node, namespaces |
| `dp_xmltree` | the nodes of all documents |
| `dp_history` | the versions of the documents |

The prefix of the `Pdo` object and the table prefix of the `XmlDb` object are
independent of each other: the `Pdo` prefix is used for the tables of other
libraries (for example `_auth_user`), the `XmlDb` prefix for its own tables.

The optional fourth argument contains settings of the whole instance:

```php
$xmlDb = new XmlDb('dp_', $pdo, $cache, ['userId' => $currentUserId]);
```

`userId` is written into the `lastchange_uid` column of a document whenever it
is changed, and is the default for `Document::updateLastChange()`.

### Creating and updating the schema

```php
$xmlDb->updateSchema();
```

`updateSchema()` reads the SQL files in the `Sql` directory and applies the
statements that are not yet applied, in the order of their version numbers. It
is idempotent, so it can be called on every deployment.

## Documents

A document is addressed by its name or by its id:

```php
$doc = $xmlDb->createDoc(\Depage\XmlDb\XmlDoctypes\Base::class, 'pages');
$doc = $xmlDb->getDoc('pages');
$doc = $xmlDb->getDoc(42);

$xmlDb->docExists('pages');      // id of the document or false
$xmlDb->getDocuments();          // all documents, indexed by their name
$xmlDb->getDocuments('pages');   // only the documents with this name
```

The first argument of `createDoc()` is the class name of the doctype handler
that is responsible for the document. It is stored in the `type` column of the
document and instantiated on demand, see [doctype handlers](Doctypes.md). If
no name is given, a unique one is generated.

```php
$copy = $xmlDb->duplicateDoc('pages', 'pages-copy');
$xmlDb->removeDoc('pages');
```

## Reading documents

```php
// the whole document as a DOMDocument
$xml = $doc->getXml();

// without the db:id attributes
$xml = $doc->getXml(false);

// a single node with its subtree
$xml = $doc->getSubdocByNodeId(42);

// the first node matching an xpath
$xml = $doc->getSubdocByXpath('/dpg:pages/pg:page[@name="home"]');

// the ids of all matching nodes
$nodeIds = $doc->getNodeIdsByXpath('/dpg:pages/pg:page');
```

The same queries are available on the `XmlDb` object, where they search in all
documents or in one document:

```php
$xml = $xmlDb->getDocXml('pages');
$xml = $xmlDb->getDocXmlXpath('pages', '/dpg:pages/pg:page');
$nodeIds = $xmlDb->getNodeIdsByXpath('/dpg:pages/pg:page');
$nodeIds = $xmlDb->getNodeIdsByXpath('/dpg:pages/pg:page', 42);   // one document
```

`getSubdocByXpath()` returns the **first** matching node only. Use
`getNodeIdsByXpath()` to get all of them.

The information about a document itself is available as an object:

```php
$info = $doc->getDocInfo();

$info->id;              // database id
$info->name;            // name of the document
$info->rootid;          // db:id of the root node
$info->type;            // class name of the doctype handler
$info->namespaces;      // namespaces used in the document
$info->lastchange;      // DateTime of the last change
$info->lastchangeUid;   // id of the user who changed the document last
$info->lastrelease;     // DateTime of the last published version
```

## The db: namespace

The library stores the id of a node in the document itself, using the
`db:` namespace (`http://cms.depagecms.net/ns/database`):

```xml
<dpg:pages xmlns:db="http://cms.depagecms.net/ns/database"
           db:id="17" db:lastchange="2026-10-29 12:00:00" db:lastchangeUid="3">
  <pg:page db:id="18" name="home"/>
</dpg:pages>
```

| Attribute | Meaning |
|---|---|
| `db:id` | the database id of the node, the only way to address it |
| `db:lastchange` | time of the last change of the document |
| `db:lastchangeUid` | id of the user who changed the document last |
| `db:dataid` | an optional id of your own, it is never interpreted by the library |

The `db:id` attributes are added when a document is read and can be removed
again with `getXml(false)` and `getSubdocByNodeId($id, false)`. They are also
the mechanism that makes incremental saves work: a node that carries its
`db:id` is recognized as the node it was before.

## Writing documents

### Saving a whole document

```php
$dom = new \Depage\Xml\Document();
$dom->loadXML('<dpg:pages xmlns:dpg="http://www.depagecms.net/ns/depage"/>');

$doc->save($dom);
```

`save()` deletes all nodes of the document and writes it again. The node ids
of the old document are lost, unless the new document carries them.

`savePatch()` does the same, but keeps the parts of the tree that did not
change:

```php
$doc->savePatch($dom);

print_r($doc->getLastPatchStats());
// ['identical' => 12, 'updates' => 1, 'deletes' => 0, 'inserts' => 1, ...]
```

The document that is passed to `savePatch()` should carry the `db:id`
attributes of the current document, for example the result of `getXml()`. Nodes
without a `db:id` are matched against the remaining children of their parent by
position. When the document can not be patched — the root node was replaced,
the document carries no ids or too much has changed — a full `save()` is done
instead. `getLastPatchStats()` tells you which of the two happened
(`'fallback' => true`).

### Saving a subtree

```php
$doc->saveNode($node);        // rewrites the subtree of $node
$doc->saveNodePatch($node);   // writes only the changed parts of the subtree
```

### Modifying single nodes

All of these methods work with the `db:id` of a node:

```php
$doc->addNode($domNode, $targetId);                    // add a node below $targetId
$doc->addNodeByName('pg:page', $targetId, -1);        // create a node by name
$doc->replaceNode($domNode, $idToReplace);             // replace a node
$doc->deleteNode($id);
$doc->duplicateNode($id);                             // copy a node, without children

$doc->moveNode($id, $targetId, $targetPos);
$doc->moveNodeIn($id, $targetId);                     // move into $targetId
$doc->moveNodeBefore($id, $targetId);
$doc->moveNodeAfter($id, $targetId);

$doc->copyNode($id, $targetId, $targetPos);
$doc->copyNodeIn($id, $targetId);
$doc->copyNodeBefore($id, $targetId);
$doc->copyNodeAfter($id, $targetId);

$doc->setAttribute($id, 'name', 'home');
$doc->removeAttribute($id, 'name');
```

The target position is the index the node gets in the list of the children of
its new parent. `-1` appends the node at the end.

Reading single values of a node:

```php
$doc->getAttribute($id, 'name');      // value of one attribute or false
$doc->getAttributes($id);             // all attributes as an array
$doc->getNodeNameById($id);           // name of the node
$doc->getParentIdById($id);           // db:id of the parent node
$doc->getPosById($id);                // position of the node in its parent
$doc->getIndexById($id);              // index of the node in the children of its parent
```

## Transactions

Every change is written inside a transaction. You can open your own to group
several changes, and to roll them back if something goes wrong:

```php
$doc->beginTransactionAltering();

try {
    $doc->addNode($node, $targetId);
    $doc->setAttribute($id, 'name', 'home');
    $doc->endTransaction();
} catch (\Throwable $e) {
    $doc->rollBackTransaction();
    throw $e;
}
```

Transactions can be nested. The outermost level starts the database
transaction, the inner levels use savepoints, so a rollback of an inner level
keeps the changes of the outer one.

`beginTransactionNonAltering()` opens a transaction that only reads. It is
used internally for reading a document, so that the data of a document is
consistent while it is read.

`endTransaction()` returns `true` when the outermost transaction has changed
the document. Only then the cache of the document is dropped and the
`onDocumentChange()` hook of the doctype handler is called.

## Indexing values

The `Indexer` resolves an xpath query to the values of the matched nodes. It
is meant for search indexes and for reading values without building a DOM.

```php
$indexer = new \Depage\XmlDb\Indexer($xmlDb);

// the values of an attribute
$results = $indexer->query('/dpg:pages/pg:page/@name');

// the text content of the elements
$results = $indexer->query('/dpg:pages/pg:page');
```

Every result is an `IndexerResult` object:

```php
foreach ($results as $result) {
    $result->docId;    // id of the document the node belongs to
    $result->nodeId;   // db:id of the node
    $result->lang;     // value of the lang attribute, if the node has one
    $result->value;    // value of the attribute or the text content of the node
}
```

A node without the targeted attribute yields no result at all, a node with an
empty attribute yields an empty string. The number of queries does not depend
on the number of results.

## Where to go next

- [XPath reference](Xpath.md) — the supported XPath dialect and its limits
- [Doctype handlers](Doctypes.md) — structure rules and permissions
- [Version history](History.md) — versions, publishing and restoring
- [Storage internals](Storage.md) — how the documents are stored
- [Developer guide](Developer.md) — running the tests, building the docs
