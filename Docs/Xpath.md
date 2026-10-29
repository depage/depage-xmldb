# XPath Reference {#xpath}

depage-xmldb understands a small dialect of XPath. Most queries are
translated into SQL and answered by the database directly. Queries that the
SQL translation cannot express are evaluated with the DOM XPath engine of PHP
instead.

The dialect is implemented by `XpathParser` and `XpathElement`, the queries
are answered by `XmlDb::getNodeIdsByXpath()` and `Document::getNodeIdsByXpath()`.

## Path syntax

A path is a list of steps, separated by `/`:

```
/dpg:pages/pg:page/pg:folder
```

| Part | Meaning |
|---|---|
| `/` | the path starts at the root node of the document |
| `//` | the descendant axis: all nodes below the current one |
| `ns:name` | a node with the given namespace prefix and name |
| `name` | a node without a namespace |
| `*` | any name |
| `*:name` | any namespace, the given name (XPath 2.0 syntax) |
| `pg:*` | the given namespace, any name |
| `*g:f*` | wildcards in the namespace and in the name |
| `[...]` | a predicate, see below |

A namespace prefix is matched literally, it is not resolved to its URI before
the query is executed.

## Predicates

### Attribute presence

```xpath
/dpg:pages/pg:page/pg:folder/pg:page[@multilang]
```

Matches all nodes that have the attribute, no matter which value it has.

### Attribute value

```xpath
/dpg:pages/pg:page/pg:folder/pg:page[@name = 'P5.1.2']
```

The value may be written in single or in double quotes. Only the `=` operator
is evaluated by the database, all other operators need the DOM XPath fallback.

### Combining predicates

Two predicates can be combined with `and` or `or`:

```xpath
/dpg:pages/pg:page[@file_type = 'html' and @multilang = 'true']
/dpg:pages/pg:page[@file_type = 'html' or @multilang = 'true']
```

The operators are case insensitive. Any other word is rejected with an
`XmlDbException` ("Invalid XPath syntax").

### Positions

```xpath
/dpg:pages/pg:page[2]
/dpg:pages/pg:page/pg:folder/*[position() = 2]
/dpg:pages/pg:page/pg:folder/*[position() != 3]
/dpg:pages/pg:page/pg:folder/*[position() < 4]
/dpg:pages/pg:page/pg:folder/*[position() > 4]
/dpg:pages/pg:page/pg:folder/*[position() <= 4]
/dpg:pages/pg:page/pg:folder/*[position() >= 4]
//pg:folder[last()]
```

Positions are always evaluated by the DOM XPath fallback, they are never
translated into SQL.

### The db:id attribute

The id of a node is stored in the `db:id` attribute of the node, so it can be
used in a predicate as well:

```xpath
//*[@db:id = '16']
```

In the SQL path the value is compared with the id column of the node directly,
which is much faster than a comparison of the serialized attributes.

## Operators

| Operator | Meaning | Evaluated by |
|---|---|---|
| `=` | attribute is equal to the value | SQL |
| `!=` | attribute is not equal to the value | DOM XPath |
| `<` `<=` `>` `>=` | comparison of the attribute value | DOM XPath |
| `and` `or` | combination of two conditions | SQL |
| `position()`, `last()` | position of the node | DOM XPath |

An unknown operator raises an `XmlDbException` ("Invalid XPath operator").

## The SQL path and the DOM fallback

A query is answered by the database when all of the following is true:

- the path uses `/` and not `//`
- no position predicate is used
- every attribute predicate uses `=` or tests for the presence of the attribute
- the condition can be parsed at all

In that case the path is translated into a join over the node table, one
joined table per level of the path, and the result is read from the database.
Only the ids of the matching nodes are transferred.

In every other case the query is answered by the DOM XPath engine: the
document is loaded into a `DOMDocument` and the query is executed there. The
ids are read back from the `db:id` attributes of the matched nodes.

The fallback is transparent, the result is the same. It is, however, much
slower, because the whole document has to be loaded. Prefer queries that can
be answered by the database, especially for large documents.

## Limits

The dialect is a subset of XPath 1.0. The following is **not** supported:

- functions like `text()`, `contains()`, `starts-with()` or `name()`
- axes other than the child axis and the descendant axis (`..`, `ancestor::`,
  `following::`)
- unions (`|`)
- nested predicates (`//*[@a[@b]]`)
- arithmetic and string functions

Namespace wildcards (`*:name`, `*g:f*`) are an XPath 2.0 syntax. They work in
the SQL path, but they cannot be verified with the DOM XPath engine of PHP,
which only knows XPath 1.0.

## Examples

```xpath
/dpg:pages/pg:page
/dpg:pages/pg:page/pg:folder
/dpg:pages/pg:page/pg:folder/pg:page[@name = 'P5.1.2']
/dpg:pages/pg:page/pg:folder/*[position() = 2]
/dpg:pages/pg:page[@file_type = 'html' and @multilang = 'true']
//pg:page
//pg:page[@multilang]
//*[@db:id = '16']
/dpg:pages/pg:page/pg:folder/pg:*
/dpg:pages/pg:page/pg:folder/*:page
```

## Using the results

```php
// the ids of all matching nodes
$nodeIds = $doc->getNodeIdsByXpath('/dpg:pages/pg:page');

// the first matching node as a document
$xml = $doc->getSubdocByXpath('/dpg:pages/pg:page');

// the attributes of all matching nodes
foreach ($nodeIds as $nodeId) {
    $attributes = $doc->getAttributes($nodeId);
}
```

The values of the matched nodes can also be resolved directly, without
building a DOM, see the [Indexer](Introduction.md).
