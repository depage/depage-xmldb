<?php

namespace Depage\XmlDb\Tests;

/**
 * tests for the incremental save (savePatch): unchanged subtrees have to
 * be detected through their subhashes and must not be written, only
 * edited/added/removed nodes may touch the database and the result has to
 * match what a full save() of the same document produces.
 *
 * document 3 of the dataset:
 *
 * 4 dpg:pages
 * └─5 pg:page Home3
 *    ├─6 pg:page P3.1
 *    │  ├─30 TEXT "bla bla blub "
 *    │  └─7 pg:page P3.1.2
 *    └─8 pg:page P3.2
 */
class SavePatchTest extends XmlDbTestCase
{
    // {{{ variables
    protected $xmlDb;
    protected $doc;
    protected $cache;
    protected $dbPrefix;
    protected $xmlTree;
    // }}}
    // {{{ setUp
    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = \Depage\Cache\Cache::factory('xmlDb', ['disposition' => 'uncached']);

        $this->dbPrefix = $this->pdo->prefix . '_proj_test';
        $this->xmlTree = $this->dbPrefix . '_xmltree';

        $this->xmlDb = new XmlDbTestClass($this->dbPrefix, $this->pdo, $this->cache, [
            'root',
            'child',
        ]);

        $this->doc = new DocumentTestClass($this->xmlDb, 3);
    }
    // }}}

    // {{{ getCurrentDom
    /**
     * the current document as dom, with the database ids set (like the
     * cms passes it back)
     */
    protected function getCurrentDom()
    {
        $dom = new \Depage\Xml\Document();
        $dom->loadXML($this->doc->getXml(true));

        return $dom;
    }
    // }}}
    // {{{ getNodeRows
    /**
     * all rows of document 3 as id => row
     */
    protected function getNodeRows()
    {
        $rows = [];

        foreach ($this->pdo->query(
            "SELECT id, id_parent, pos, type, name, value, subhash FROM {$this->xmlTree} WHERE id_doc = 3 ORDER BY id"
        ) as $row) {
            $rows[(int) $row['id']] = [
                'id_parent' => $row['id_parent'],
                'pos' => $row['pos'],
                'type' => $row['type'],
                'name' => $row['name'],
                'value' => $row['value'],
                'subhash' => $row['subhash'],
            ];
        }

        return $rows;
    }
    // }}}
    // {{{ findPage
    protected function findPage($dom, $name)
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('pg', 'http://www.depagecms.net/ns/page');

        $pages = $xpath->query('//pg:page[@name="' . $name . '"]');

        return $pages->length > 0 ? $pages->item(0) : null;
    }
    // }}}
    // {{{ getElementRowIds
    /**
     * ids of the element rows of the document in the database
     */
    protected function getElementRowIds($rows = null)
    {
        $rows = is_null($rows) ? $this->getNodeRows() : $rows;

        $ids = [];
        foreach ($rows as $id => $row) {
            if ($row['type'] == 'ELEMENT_NODE') {
                $ids[] = (int) $id;
            }
        }
        sort($ids);

        return $ids;
    }
    // }}}
    // {{{ getXmlElementIds
    /**
     * db:id attributes the current document carries; they have to point to
     * the rows the elements are stored in
     */
    protected function getXmlElementIds()
    {
        $dom = $this->getCurrentDom();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('db', 'http://cms.depagecms.net/ns/database');

        $ids = [];
        foreach ($xpath->query('//@db:id') as $attribute) {
            $ids[] = (int) $attribute->nodeValue;
        }
        sort($ids);

        return $ids;
    }
    // }}}
    // {{{ childIdsOf
    /**
     * ids of the children of a node in the order they are stored in the
     * database
     */
    protected function childIdsOf($parent_id)
    {
        return array_map(
            'intval',
            $this->pdo->query(
                "SELECT id FROM {$this->xmlTree} WHERE id_doc = 3 AND id_parent = " . (int) $parent_id . " ORDER BY pos ASC"
            )->fetchAll(\PDO::FETCH_COLUMN)
        );
    }
    // }}}

    // {{{ testPatchUnchangedDocument
    /**
     * saving a document that did not change must not write any node; the
     * first patch run backfills the subhashes, on the second run the
     * root subtree hash has to prune the whole document
     */
    public function testPatchUnchangedDocument()
    {
        // the dataset fixtures store their attribute strings in the
        // older format, the first patch run normalizes them and
        // backfills the subhashes
        $this->doc->savePatch($this->getCurrentDom());

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);

        // all nodes of the document have to carry a subhash now
        $rows = $this->getNodeRows();
        $this->assertCount(6, $rows);
        foreach ($rows as $id => $row) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['subhash'], "node {$id}");
        }

        $before = $rows;

        $this->doc->savePatch($this->getCurrentDom());

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        // the hash of the root subtree matches, the whole document is
        // skipped without comparing single nodes
        $this->assertSame(1, $stats['identical']);
        $this->assertSame(0, $stats['updates']);
        $this->assertSame(0, $stats['deletes']);
        $this->assertSame(0, $stats['inserts']);
        $this->assertSame(0, $stats['renumber']);

        $this->assertSame($before, $this->getNodeRows());
    }
    // }}}
    // {{{ testPatchContentEdit
    /**
     * editing the attribute of one node has to update exactly the row of
     * that node; the ancestors keep their content (only their subhashes
     * are recalculated), sibling subtrees are untouched
     */
    public function testPatchContentEdit()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $before = $this->getNodeRows();

        $dom = $this->getCurrentDom();
        $page = $this->findPage($dom, 'P3.2');
        $this->assertNotNull($page);
        $page->setAttribute('name', 'P3.2 edited');

        $this->doc->savePatch($dom);

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        $this->assertSame(1, $stats['updates']);
        $this->assertSame(0, $stats['deletes']);
        $this->assertSame(0, $stats['inserts']);

        $after = $this->getNodeRows();

        $this->assertStringContainsString('P3.2 edited', $after[8]['value']);
        $this->assertNotSame($before[8]['subhash'], $after[8]['subhash'], 'subhash has to be recalculated');

        // the ancestors of the edited node only change their subhash
        foreach ([5, 4] as $id) {
            $this->assertNotSame($before[$id]['subhash'], $after[$id]['subhash']);
            $this->assertSame($before[$id]['pos'], $after[$id]['pos']);
        }

        // everything outside of the edited subtree keeps its row
        foreach ([6, 7, 30] as $id) {
            $this->assertSame($before[$id], $after[$id], "node {$id} should be untouched");
        }

        // and the document reads back as expected
        $xml = $this->doc->getXml(false);
        $this->assertStringContainsString('P3.2 edited', $xml);
    }
    // }}}
    // {{{ testPatchInsertAndDelete
    /**
     * a node added to the front of a child list and a removed subtree
     * have to be applied without touching the other nodes; the result
     * must be equal to what a full save() of the same document writes
     */
    public function testPatchInsertAndDelete()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $dom = $this->getCurrentDom();

        $page = $this->findPage($dom, 'P3.2');
        $this->assertNotNull($page);
        $page->parentNode->removeChild($page);

        $home = $this->findPage($dom, 'Home3');
        $new = $dom->createElementNS('http://www.depagecms.net/ns/page', 'pg:subpage');
        $new->setAttribute('name', 'inserted');
        $home->insertBefore($new, $home->firstChild);

        $this->doc->savePatch($dom);

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        $this->assertSame(1, $stats['inserts']);
        $this->assertSame(1, $stats['deletes']);
        $this->assertSame(0, $stats['updates']);

        $rows = $this->getNodeRows();

        // the deleted node is gone, everything below the untouched
        // subtree stayed
        $this->assertFalse(isset($rows[8]));
        $this->assertArrayHasKey(6, $rows);
        $this->assertArrayHasKey(7, $rows);
        $this->assertArrayHasKey(30, $rows);

        // the new node went in front of the children of node 5
        $children = $this->pdo->query(
            "SELECT id FROM {$this->xmlTree} WHERE id_doc = 3 AND id_parent = 5 ORDER BY pos ASC"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(2, $children);
        $this->assertSame(6, (int) $children[1]);

        $new_id = (int) $children[0];
        $this->assertNotContains($new_id, [4, 5, 6, 7, 8, 30]);
        $this->assertSame('pg:subpage', $rows[$new_id]['name']);

        // patching the same document again changes nothing
        $this->doc->savePatch($this->getCurrentDom());
        $stats = $this->doc->getLastPatchStats();
        $this->assertSame(0, $stats['updates']);
        $this->assertSame(0, $stats['deletes']);
        $this->assertSame(0, $stats['inserts']);

        // a full save of the same document results in the same xml
        $patched = $this->doc->getXml(false);
        $this->doc->save($this->getCurrentDom());
        $this->assertXmlStringEqualsXmlStringIgnoreLastchange($patched, $this->doc->getXml(false));
    }
    // }}}
    // {{{ testPatchPositionalReuseOfIdlessNode
    /**
     * an element without db:id that is compatible with a leftover child
     * of its parent (type and name match) takes that child's place
     * instead of deleting and inserting
     */
    public function testPatchPositionalReuseOfIdlessNode()
    {
        $this->doc->savePatch($this->getCurrentDom());
        $before = $this->getNodeRows();

        $dom = $this->getCurrentDom();
        $page = $this->findPage($dom, 'P3.2');
        $this->assertNotNull($page);
        // without the database id the element is a "new" pg:page, but it
        // is compatible with the leftover child 8
        $page->removeAttributeNS('http://cms.depagecms.net/ns/database', 'id');
        $page->setAttribute('name', 'P3.2 renamed');

        $this->doc->savePatch($dom);

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        $this->assertSame(0, $stats['inserts']);
        $this->assertSame(0, $stats['deletes']);
        $this->assertSame(1, $stats['updates']);

        $after = $this->getNodeRows();

        // node 8 was reused for the edited element
        $this->assertSame($before[8]['id_parent'], $after[8]['id_parent']);
        $this->assertStringContainsString('name="P3.2 renamed"', $after[8]['value']);
    }
    // }}}
    // {{{ testPatchFallsBackOnReplacedRoot
    public function testPatchFallsBackOnReplacedRoot()
    {
        $dom = $this->getCurrentDom();
        $dom->documentElement->setAttributeNS('http://cms.depagecms.net/ns/database', 'db:id', '424242');

        $this->doc->savePatch($dom);

        $stats = $this->doc->getLastPatchStats();
        $this->assertTrue($stats['fallback']);

        // the document was saved completely
        $rows = $this->getNodeRows();
        $this->assertArrayHasKey(424242, $rows);
        $this->assertFalse(isset($rows[4]));
    }
    // }}}
    // {{{ testPatchFallsBackOnMassiveStructuralChange
    /**
     * when most of the document has to be deleted (here: everything
     * below the first page except the page itself) a full save() is
     * cheaper than patching and gets done instead
     */
    public function testPatchFallsBackOnMassiveStructuralChange()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $dom = $this->getCurrentDom();

        $page = $this->findPage($dom, 'P3.2');
        $page->parentNode->removeChild($page);

        $page = $this->findPage($dom, 'P3.1.2');
        $page->parentNode->removeChild($page);

        $p31 = $this->findPage($dom, 'P3.1');
        $p31->removeChild($p31->firstChild);

        $this->doc->savePatch($dom);

        $stats = $this->doc->getLastPatchStats();
        $this->assertTrue($stats['fallback']);

        // the fallback saved the reduced document
        $rows = $this->getNodeRows();
        $this->assertCount(3, $rows);
        $this->assertSame('dpg:pages', $rows[4]['name']);
        $this->assertSame('pg:page', $rows[5]['name']);
        $this->assertSame('pg:page', $rows[6]['name']);
    }
    // }}}

    // {{{ testPatchKeepsNodeIds
    /**
     * patching must reuse the rows of the nodes that stayed in the
     * document, so their database ids (and with them the db:id attributes
     * the cms works with) do not change; only added nodes may get new ids
     * and only the id of a removed node disappears
     */
    public function testPatchKeepsNodeIds()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $before = $this->getNodeRows();
        $this->assertSame([4, 5, 6, 7, 8], $this->getElementRowIds($before));
        $this->assertSame($this->getElementRowIds($before), $this->getXmlElementIds());

        $dom = $this->getCurrentDom();

        $page = $this->findPage($dom, 'P3.2');
        $page->setAttribute('name', 'P3.2 edited');

        $page = $this->findPage($dom, 'P3.1.2');
        $page->parentNode->removeChild($page);

        $home = $this->findPage($dom, 'Home3');
        $new = $dom->createElementNS('http://www.depagecms.net/ns/page', 'pg:subpage');
        $new->setAttribute('name', 'inserted');
        $home->insertBefore($new, $home->firstChild);

        $this->doc->savePatch($dom);

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);

        $after = $this->getNodeRows();

        // the id of the removed node is gone, the surviving nodes kept
        // theirs (including the text node of the edited subtree)
        $this->assertFalse(isset($after[7]), 'the removed node must not exist anymore');
        $this->assertSame(
            [4, 5, 6, 8],
            array_values(array_intersect($this->getElementRowIds($before), $this->getElementRowIds($after)))
        );

        // the added node is the only one with a new id
        $newIds = array_diff(array_keys($after), array_keys($before));
        $this->assertCount(1, $newIds);
        $this->assertSame('pg:subpage', $after[reset($newIds)]['name']);

        // and the ids still point to the pages they did before
        $this->assertStringContainsString('P3.2 edited', $after[8]['value']);
        $this->assertSame($before[6]['value'], $after[6]['value']);

        // and the db:id attributes of the document point to the stored rows
        $this->assertSame($this->getElementRowIds($after), $this->getXmlElementIds());
    }
    // }}}
    // {{{ testPatchKeepsNodeIdsOnReorder
    /**
     * reordering a child list only writes new positions, the nodes
     * themselves keep their rows and ids
     */
    public function testPatchKeepsNodeIdsOnReorder()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $before = $this->getNodeRows();
        $this->assertSame([6, 8], $this->childIdsOf(5));

        $dom = $this->getCurrentDom();
        $page = $this->findPage($dom, 'P3.2');
        $this->assertNotNull($page);
        $page->parentNode->insertBefore($page, $page->parentNode->firstChild);

        $this->doc->savePatch($dom);

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        $this->assertSame(0, $stats['inserts']);
        $this->assertSame(0, $stats['deletes']);
        $this->assertSame(0, $stats['updates']);

        $after = $this->getNodeRows();

        // same nodes, same ids, only the order of the children changed
        $this->assertSame(array_keys($before), array_keys($after));
        $this->assertSame([8, 6], $this->childIdsOf(5));
        $this->assertSame($this->getElementRowIds($before), $this->getElementRowIds($after));
        $this->assertSame($this->getElementRowIds($after), $this->getXmlElementIds());

        foreach ($after as $id => $row) {
            $this->assertSame($before[$id]['id_parent'], $row['id_parent'], "node {$id}");
            $this->assertSame($before[$id]['value'], $row['value'], "node {$id} keeps its content under its id");
        }
    }
    // }}}
    // {{{ testPatchKeepsNodeIdsOfMovedSubtree
    /**
     * moving a subtree to another parent removes the old rows and inserts
     * the subtree again; the elements have to come back with the ids they
     * had before, the nodes below the moved node must not be forced to new
     * ids by the patch
     */
    public function testPatchKeepsNodeIdsOfMovedSubtree()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $dom = $this->getCurrentDom();
        $page = $this->findPage($dom, 'P3.1');
        $this->assertNotNull($page);
        $dom->documentElement->appendChild($page);

        $this->doc->savePatch($dom);

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);

        $after = $this->getNodeRows();

        // the moved page (6) and the page below it (7) kept their ids
        $this->assertSame([4, 5, 6, 7, 8], $this->getElementRowIds($after));
        $this->assertStringContainsString('name="P3.1"', $after[6]['value']);
        $this->assertStringContainsString('name="P3.1.2"', $after[7]['value']);
        $this->assertSame('4', (string) $after[6]['id_parent']);
        $this->assertSame('6', (string) $after[7]['id_parent']);
        $this->assertSame([5, 6], $this->childIdsOf(4));
        $this->assertSame([8], $this->childIdsOf(5));

        $this->assertSame($this->getElementRowIds($after), $this->getXmlElementIds());
    }
    // }}}
    // {{{ testPatchDoesNotReuseForeignNodeIds
    /**
     * an element that carries the db:id of a node of another document must
     * not take over that row; it gets a new id of its own and the other
     * document keeps its node
     */
    public function testPatchDoesNotReuseForeignNodeIds()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $getOtherRows = function () {
            return $this->pdo->query(
                "SELECT id, id_doc, id_parent, pos, name, value FROM {$this->xmlTree} WHERE id_doc = 4 ORDER BY id"
            )->fetchAll(\PDO::FETCH_ASSOC);
        };
        $otherBefore = $getOtherRows();

        $dom = $this->getCurrentDom();
        $home = $this->findPage($dom, 'Home3');

        $new = $dom->createElementNS('http://www.depagecms.net/ns/page', 'pg:subpage');
        $new->setAttribute('name', 'claims the id of another document');
        $new->setAttributeNS('http://cms.depagecms.net/ns/database', 'db:id', '10');
        $home->appendChild($new);

        $this->doc->savePatch($dom);

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        $this->assertSame(1, $stats['inserts']);

        $rows = $this->getNodeRows();
        $this->assertCount(7, $rows);
        $this->assertFalse(isset($rows[10]), 'the node of document 4 must not become part of document 3');

        // the element got an id of its own, which is what the stored
        // document shows
        $this->assertNotContains(10, $this->getXmlElementIds());
        $this->assertSame($this->getElementRowIds(), $this->getXmlElementIds());

        $xml = $this->doc->getXml(false);
        $this->assertStringContainsString('claims the id of another document', $xml);

        // and document 4 was not touched
        $this->assertSame($otherBefore, $getOtherRows());
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
