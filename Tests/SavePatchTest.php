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
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
