# Version History {#history}

Every document can keep its versions. A version is a snapshot of the document
at a point in time, optionally published, so that a reader can be served the
version that was released, while the editor continues to work on the current
state.

The versions of a document are stored in the `dp_history` table and are
managed by the `DocumentHistory` class.

## Writing a version

```php
$history = $doc->getHistory();

// a version for the editors
$timestamp = $history->save($userId);

// a version that is published
$timestamp = $history->save($userId, true);
```

`save()` returns the timestamp of the version, which identifies it. If the
content of the document did not change since the last version, no new version
is written: the timestamp of the existing version is updated instead. The
content is compared by a hash of the document, so the `db:` attributes do not
make a difference.

Before the snapshot is written, the doctype handler gets the chance to change
it, see [doctype handlers](Doctypes.md).

## Listing the versions

```php
$versions = $history->getVersions();

foreach ($versions as $timestamp => $version) {
    $version->firstsaved;   // DateTime of the first save of this version
    $version->lastsaved;    // DateTime of the last save of this version
    $version->userId;       // id of the user who saved it
    $version->published;    // true if the version is published
    $version->hash;         // hash of the content of the version
}
```

The versions are indexed by the timestamp of their last save, the newest one
first. The list can be filtered and limited:

```php
$history->getVersions(true);          // only the published versions
$history->getVersions(false);         // only the unpublished versions
$history->getVersions(null, 10);      // at most ten versions
```

The latest published version is available as an object:

```php
$version = $history->getLatestVersion();
```

## Reading a version

```php
// the version of a timestamp
$xml = $history->getXml($timestamp);

// the version that was published last
$xml = $history->getLastPublishedXml();

// without the db:id attributes
$xml = $history->getXml($timestamp, false);
```

`getXml()` returns the newest version that was saved at or before the given
timestamp. The root element of a version carries two additional attributes:
`db:docid` (the id of the document) and `db:lastchange` (the time of the
version).

## Restoring and deleting

```php
// write an old version back into the document
$xml = $history->restore($timestamp);

// remove a version
$history->delete($timestamp);
```

`restore()` loads the version and saves it as the current content of the
document. It does not write a new version of the history, so call
`$history->save()` afterwards if the restored state should be released.

## The state of a document

```php
$doc->isPublished();   // the document has at least one published version
$doc->isReleased();    // the document has not been changed since the last release
```

The time of the last release is part of the document info:

```php
$info = $doc->getDocInfo();

$info->lastrelease;   // DateTime of the last published version
```

## Reading the published versions

`XmlDbHistory` is a read only view on the published versions of all
documents. It is meant for the part of an application that shows the released
content and must never see the current state of the documents.

```php
$history = new \Depage\XmlDb\XmlDbHistory('dp_', $pdo, $cache);

$history->docExists('pages');       // id of the document or false
$xml = $history->getDocXml('pages');
$xml = $history->getDocXmlXpath('pages', '/dpg:pages/pg:page');
```

Without a timestamp the last published version is returned. Set the timestamp
to read a specific version:

```php
$history->timestamp = $timestamp;
$xml = $history->getDocXml('pages');
```

`getDocXmlXpath()` evaluates the query with the DOM XPath engine and returns
the first matching node.

See also the [getting started](Introduction.md) and the
[storage internals](Storage.md).
