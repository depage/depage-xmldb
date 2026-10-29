# Developer Guide {#developer-guide}

This page describes the layout of the repository and the tools that are used
while working on the library.

## Layout of the repository

| Path | Content |
|---|---|
| `Document.php` | the document object: read, write, query and change a document |
| `XmlDb.php` | the collection: create documents, query in all documents, transactions |
| `XmlDbHistory.php` | read only view on the published versions of the documents |
| `DocumentHistory.php` | the versions of one document |
| `Indexer.php`, `IndexerResult.php` | resolve an xpath query to the values of the matched nodes |
| `XpathParser.php`, `XpathElement.php` | the parser of the XPath dialect |
| `XmlNs.php` | a namespace prefix and its URI |
| `Exceptions/` | `XmlDbException`, the only exception of the library |
| `Interfaces/` | the public interfaces: `DoctypeInterface`, `DocumentWriterHost`, `XmlGetter` |
| `XmlDoctypes/` | the default doctype handler `Base` |
| `Helpers/` | internal collaborators: positions, write pipeline, batching, hashing, cache keys |
| `Patch/` | internal planner and applier of the incremental saves |
| `Sql/` | the schema of the tables |
| `Tests/` | the PHPUnit test suite |
| `Docs/` | the Doxygen configuration and the documentation pages |

Everything in `Helpers/` and `Patch/` is marked as `@internal` and is not part
of the public API of the library. The public API consists of the classes in
the root directory, of `Interfaces/` and of `XmlDoctypes/`.

## Requirements

- PHP >= 8.3 with the extensions `dom`, `pdo` and `pdo_mysql`
- MySQL >= 8.0 or MariaDB >= 10.2 for the test suite
- [Composer](https://getcomposer.org)

```sh
composer install
```

## Tests

The test suite needs a MySQL or MariaDB server. Its connection is configured
in `Tests/PhpUnit.xml`:

```xml
<var name="DB_DSN" value="mysql:host=host.docker.internal;dbname=test_db"/>
<var name="DB_USER" value="test_db_user"/>
<var name="DB_PASSWD" value="test_db_password"/>
```

```sh
make test
```

The tests drop and recreate their tables, so they must not be run against a
database with data in it. `Tests/Bootstrap.php` uses a lock in the database,
so that two test runs do not delete each other's tables.

The tests are grouped by the class they belong to:

| Test file | Content |
|---|---|
| `XmlDbTest.php` | the collection: documents, creating, duplicating, removing |
| `DocumentTest.php` | saving, reading and changing the nodes of a document |
| `DocumentTransactionTest.php` | the same, inside of a transaction |
| `DocumentHistoryTest.php`, `XmlDbHistoryTest.php` | the versions of the documents |
| `XpathParserTest.php`, `XpathTestCase.php`, `XpathXmlDbTest.php`, `XpathDocumentTest.php` | the XPath dialect, against the parser and against the database |
| `IndexerTest.php` | resolving the values of the matched nodes |
| `SavePatchTest.php`, `SaveNodePatchTest.php`, `PatchPlannerTest.php` | the incremental saves |
| `SubhashTest.php` | the merkle hashes |
| `SparsePositionsTest.php` | the positions of the nodes |
| `NodeBatchTest.php`, `CaseUpdateTest.php`, `WriteBatchTest.php` | the batched writes |
| `DoctypeHandlerTest.php`, `DoctypeHandlerBaseTest.php` | the doctype handlers |
| `TransactionRollbackTest.php` | the transactions and their rollback |

## Code style

The code style is checked with [PHP-CS-Fixer](https://cs.symfony.com/), the
configuration is in `.php-cs-fixer.dist.php`:

```sh
make cs                        # check the code style
vendor/bin/php-cs-fixer fix   # apply the fixes
```

The compatibility with the supported PHP versions is checked with
[PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) and
[PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility), the
configuration is in `.phpcs.xml.dist`:

```sh
make compat
```

## Documentation

The API documentation is generated with [Doxygen](https://www.doxygen.nl/):

```sh
make doc
```

The target clones the [depage-docu](https://github.com/depage/depage-docu)
theme into `Docs/depage-docu` and runs `doxygen` with `Docs/Doxyfile`. The
result is written to `Docs/html`.

The configuration is split in two parts:

- `Docs/depage-docu/Doxyfile` is the configuration of the theme. It is not part
  of this repository, it is cloned by the `make doc` target.
- `Docs/Doxyfile` is the configuration of this project. It includes the
  configuration of the theme and overrides the name of the project, the
  output directory and the url of the sitemap.

The navigation of the generated documentation is defined in
`Docs/DoxygenLayout.xml`. The pages in `Docs/*.md` are added to the navigation
with their label:

```xml
<tab type="user" url="@ref introduction" visible="yes" title="Getting Started"/>
```

The label of a page is the id of its first heading:

```markdown
# Getting Started {#page-label}
```

The `README.md` in the root of the repository is the main page of the
documentation, it is used by `USE_MDFILE_AS_MAINPAGE`.

## Releasing

The version number of a release is taken from the git tags:

```sh
git tag v2.4.0
git push --tags
```

The `make doc` target writes the version into the documentation as
`PROJECT_NUMBER`.
