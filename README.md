# depage-xmldb

**XML documents in a relational database.**

depage-xmldb stores XML documents in MySQL/MariaDB, lets you query them with a
small and fast XPath dialect and modify them node by node — without ever
loading the whole document into memory.

It is the XML storage layer of [depage-cms](https://www.depagecms.net), but it
works just as well as a standalone library.

## Features

- **XML documents in a relational database.** Every document is a tree of nodes
  in a `_xmltree` table, the document metadata lives in `_xmldocs`.
- **XPath queries.** A small XPath dialect is translated into SQL and answered
  directly by the database. Queries that the SQL path cannot express fall back
  to a DOM XPath evaluation.
- **Incremental saves.** `savePatch()` and `saveNodePatch()` only write the
  subtrees that really changed. Merkle hashes over the tree make it possible to
  find them without comparing every single node.
- **Stable node ids.** Every node carries a `db:id`, so a node can be
  referenced, addressed and updated without knowing its position in the
  document.
- **Doctype handlers.** Structure rules, permissions, whitespace handling and
  change hooks are implemented by a handler class of your choice.
- **Version history.** Every document keeps its versions, can publish them and
  restore older ones.
- **Transactions.** Nested transactions with savepoints, so a failed change can
  be rolled back without losing the surrounding work.
- **Batched writes.** Rows are written with multi-row `INSERT` and
  `UPDATE ... CASE` statements, independent of the size of the document.
- **Caching.** Subdocuments and query results are cached per document and the
  whole cache of a document is dropped in one go when it changes.

## Requirements

- **PHP >= 8.3** with the extensions `dom`, `pdo` and `pdo_mysql`
- **MySQL >= 8.0** or **MariaDB >= 10.2** (the library uses recursive common
  table expressions and window functions)
- the libraries [depage/db](https://github.com/depage/depage-db),
  [depage/xml](https://github.com/depage/depage-xml) and
  [depage/cache](https://github.com/depage/depage-cache)

## Installation

```sh
composer require depage/xmldb
```

## Getting started

```php
use Depage\Cache\Cache;
use Depage\Db\Pdo;
use Depage\XmlDb\XmlDb;

$pdo = new Pdo('mysql:host=localhost;dbname=depage', 'user', 'password', ['prefix' => 'dp_']);
$cache = Cache::factory('xmldb', ['disposition' => 'file', 'cachepath' => '/var/cache/depage']);

$xmlDb = new XmlDb('dp_', $pdo, $cache);
$xmlDb->updateSchema();          // create or migrate the tables

// a document is addressed by its name or by its id
$doc = $xmlDb->createDoc(\Depage\XmlDb\XmlDoctypes\Base::class, 'pages');

$dom = new \Depage\Xml\Document();
$dom->loadXML(
    '<dpg:pages xmlns:dpg="http://www.depagecms.net/ns/depage">'
    . '<pg:page name="home" xmlns:pg="http://www.depagecms.net/ns/page"/>'
    . '</dpg:pages>'
);
$doc->save($dom);

// read the document back (without the db:id attributes)
$xml = $doc->getXml(false);

// query it
$nodeIds = $doc->getNodeIdsByXpath('/dpg:pages/pg:page[@name="home"]');

// a node is addressed by its db:id
$page = $doc->getSubdocByNodeId($nodeIds[0]);
```

Writing to a document is done either as a whole or node by node:

```php
// full save: the document is rewritten
$doc->save($dom);

// incremental save: only the changed subtrees are written
$doc->savePatch($dom);
print_r($doc->getLastPatchStats());

// node by node
$doc->addNode($domNode, $targetNodeId);
$doc->moveNodeAfter($nodeId, $targetNodeId);
$doc->setAttribute($nodeId, 'name', 'home');
```

## Documentation

- [Getting started](Docs/Introduction.md) — setup, documents, reading, writing
- [XPath reference](Docs/Xpath.md) — the supported XPath dialect
- [Doctype handlers](Docs/Doctypes.md) — structure rules, permissions, hooks
- [Version history](Docs/History.md) — versions, publishing, restoring
- [Storage internals](Docs/Storage.md) — tables, positions, hashing, batching
- [Developer guide](Docs/Developer.md) — tests, code style, documentation

The API reference is generated with Doxygen and published at
<https://docs.depage.net/depage-xmldb/>.

## Development

```sh
make test       # run the PHPUnit test suite (needs a MySQL/MariaDB server)
make cs         # check the code style
make compat     # check the PHP version compatibility
make doc        # generate the API documentation
```

## License (dual)

- GPL-2.0: <https://www.gnu.org/licenses/gpl-2.0.html>
- MIT: <https://opensource.org/licenses/MIT>
