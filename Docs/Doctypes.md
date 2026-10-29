# Doctype Handlers {#doctypes}

Every document knows the class that is responsible for it. That class — the
doctype handler — decides which nodes may be created, where they may be placed
and what happens when the document is changed.

The handler is stored in the `type` column of the document and instantiated
when the document is loaded, so it must be a class that can be found by the
autoloader.

```php
$doc = $xmlDb->createDoc(\MyApp\XmlDb\PagesDoctype::class, 'pages');
```

The default handler is `Depage\XmlDb\XmlDoctypes\Base`. It allows everything
and does not change anything, it is used for documents without any rules.

## Writing a handler

The easiest way to write a handler is to extend the base handler and to
override the parts you need:

```php
namespace MyApp\XmlDb;

use Depage\XmlDb\XmlDoctypes\Base;

class PagesDoctype extends Base
{
    // {{{ variables
    // nodes that a user may create, indexed by their name
    protected $availableNodes = [
        'pg:page' => (object) [
            'newName' => 'new page',
            'attributes' => [
                'file_type' => 'html',
            ],
        ],
    ];

    // the parents a node may have, indexed by the name of the node
    protected $validParents = [
        'pg:page' => [
            'dpg:pages',
            'pg:folder',
        ],
        'pg:folder' => [
            'dpg:pages',
            'pg:folder',
        ],
    ];

    // nodes whose whitespace is not stripped while saving
    protected $preserveWhitespace = [
        'pg:text',
    ];
    // }}}
}
```

A handler implements `Depage\XmlDb\Interfaces\DoctypeInterface`. You can also
implement the interface from scratch, but extending the base class is usually
easier.

## Structure rules

### Which parents are allowed

`$validParents` maps the name of a node to the names of the nodes it may be
placed in. The key `*` is the fallback for all nodes that are not listed
explicitly.

```php
protected $validParents = [
    '*' => ['dpg:pages'],      // everything else may only be in dpg:pages
    'pg:page' => ['dpg:pages', 'pg:folder'],
];
```

The names are the qualified names of the nodes, as they are stored in the
database, for example `pg:page`. The rules are checked by `isAllowedIn()`,
which is used by `isAllowedAdd()`, `isAllowedMove()` and `isAllowedCopy()`.

### Which nodes may be created

`$availableNodes` lists the nodes that may be created by name, with
`Document::addNodeByName()`. Every entry describes the new node:

| Property | Meaning |
|---|---|
| `newName` | value of the `name` attribute of the new node |
| `attributes` | attributes that are set on the new node |
| `xmlTemplateData` | complete XML of the new node, overrides the two properties above |

```php
$doc->addNodeByName('pg:page', $targetId, -1);
```

If the name is not in the list, `addNodeByName()` returns `false` without
touching the document.

### Whitespace

While a document is saved, the whitespace of the text nodes is stripped, so
that the formatting of the XML does not end up in the database. The nodes
listed in `$preserveWhitespace` keep their whitespace:

```php
protected $preserveWhitespace = ['pg:text'];
```

## Permissions

`getPermissions()` returns the rules of the document as an object, so that a
user interface can build the menus and the drag and drop targets from it:

```php
$permissions = $doc->getPermissions();

$permissions->validParents;    // the same as $validParents
$permissions->availableNodes;  // the same as $availableNodes
```

## Hooks

The handler is notified about every change of the document:

| Method | Called when |
|---|---|
| `onAddNode($node, $targetId, $targetPos, $extras)` | a node is added |
| `onCopyNode($nodeId, $copyId)` | a node is copied |
| `onMoveNode($nodeId, $oldParentId)` | a node is moved |
| `onDeleteNode($nodeId, $parentId)` | a node is deleted |
| `onSetAttribute($nodeId, $attrName, $oldValue, $newValue)` | an attribute is changed |
| `onDocumentChange()` | the document has been changed and committed |

```php
public function onAddNode(\DomNode $node, $target_id, $target_pos, $extras)
{
    $this->updateSearchIndex($node);

    return true;
}
```

`onDocumentChange()` is called after the outermost transaction has been
committed, not inside of it. The other hooks are called while the change is
written, inside of the transaction.

## Changing the document while it is read

A handler may rewrite a document while it is read, for example to fill in
default values or to remove nodes that are not allowed any more:

```php
public function testDocument($xml)
{
    // return true if the document was changed
    return false;
}
```

`testDocument()` is called when a document or a subtree is read. If it returns
`true`, the changed document is written back.

`testDocumentForHistory()` does the same for the snapshots that are written to
the history. It is called by `DocumentHistory::save()`:

```php
public function testDocumentForHistory($xml)
{
    return false;
}
```

## Errors

When the class of the handler does not exist or does not implement
`DoctypeInterface`, an `XmlDbException` is raised:

- "Doctype handler class \"…\" does not exist"
- "Doctype handler must implement DoctypeInterface"

See also the [getting started](Introduction.md) and the
[storage internals](Storage.md).
