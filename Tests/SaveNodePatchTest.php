<?php

namespace Depage\XmlDb\Tests;

/**
 * tests for the incremental subtree save (saveNodePatch): like savePatch it
 * has to skip the unchanged parts of the tree through their subhashes, but
 * it may only ever touch the subtree below the node it is given; the rest of
 * the document has to keep its rows and the result must match what a full
 * saveNode() of the same subtree produces.
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
class SaveNodePatchTest extends XmlDbTestCase
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

    // {{{ testPatchUnchangedSubtreeBackfillsAndSkips
    /**
     * patching a subtree has to backfill the subhashes of the whole
     * document (the first run normalizes the old attribute strings), on
     * the second run the subtree hash of the root node has to prune
     * everything below it without writing a single node
     */
    public function testPatchUnchangedSubtreeBackfillsAndSkips()
    {
        $dom = $this->getCurrentDom();
        $p31 = $this->findPage($dom, 'P3.1');
        $this->assertNotNull($p31);

        $this->assertSame(6, $this->doc->saveNodePatch($p31));

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        // the counts cover the patched subtree (6, 30, 7), not the document
        $this->assertSame(3, $stats['old_nodes']);
        $this->assertSame(3, $stats['new_nodes']);

        // all nodes of the document have to carry a subhash now
        $rows = $this->getNodeRows();
        $this->assertCount(6, $rows);
        foreach ($rows as $id => $row) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['subhash'], "node {$id}");
        }

        $before = $rows;

        $dom = $this->getCurrentDom();
        $this->assertSame(6, $this->doc->saveNodePatch($this->findPage($dom, 'P3.1')));

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        // the hash of the subtree root matches, the whole subtree is
        // skipped without comparing single nodes
        $this->assertSame(1, $stats['identical']);
        $this->assertSame(0, $stats['updates']);
        $this->assertSame(0, $stats['deletes']);
        $this->assertSame(0, $stats['inserts']);
        $this->assertSame(0, $stats['renumber']);

        // not a single row of the document was touched
        $this->assertSame($before, $this->getNodeRows());
    }
    // }}}
    // {{{ testPatchSubtreeContentEdit
    /**
     * editing one node deep inside the subtree may update exactly that
     * row; the ancestors up to the document root only get new subhashes,
     * the nodes outside of the path to the edit (and outside the subtree)
     * keep their rows untouched
     */
    public function testPatchSubtreeContentEdit()
    {
        // patch the whole document once so every node has a subhash
        $this->doc->savePatch($this->getCurrentDom());

        $before = $this->getNodeRows();

        $dom = $this->getCurrentDom();
        $page = $this->findPage($dom, 'P3.1.2');
        $this->assertNotNull($page);
        $page->setAttribute('name', 'P3.1.2 edited');

        $p31 = $this->findPage($dom, 'P3.1');
        $this->assertSame(6, $this->doc->saveNodePatch($p31));

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        $this->assertSame(1, $stats['updates']);
        $this->assertSame(0, $stats['deletes']);
        $this->assertSame(0, $stats['inserts']);
        $this->assertSame(0, $stats['renumber']);
        // the unchanged text node below P3.1 was skipped by its hash
        $this->assertSame(1, $stats['identical']);

        $after = $this->getNodeRows();

        $this->assertStringContainsString('P3.1.2 edited', $after[7]['value']);
        $this->assertNotSame($before[7]['subhash'], $after[7]['subhash'], 'subhash has to be recalculated');

        // the ancestors of the edited node (up to the document root) only
        // change their subhash
        foreach ([6, 5, 4] as $id) {
            $this->assertNotSame($before[$id]['subhash'], $after[$id]['subhash']);
            $this->assertSame($before[$id]['pos'], $after[$id]['pos']);
            $this->assertSame($before[$id]['value'], $after[$id]['value']);
        }

        // everything outside the path to the edit keeps its row
        foreach ([30, 8] as $id) {
            $this->assertSame($before[$id], $after[$id], "node {$id} should be untouched");
        }

        // and the document reads back as expected
        $xml = $this->doc->getXml(false);
        $this->assertStringContainsString('P3.1.2 edited', $xml);

        // patching the same subtree again has nothing left to do
        $dom = $this->getCurrentDom();
        $this->doc->saveNodePatch($this->findPage($dom, 'P3.1'));

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        $this->assertSame(0, $stats['updates']);
        $this->assertSame(0, $stats['deletes']);
        $this->assertSame(0, $stats['inserts']);
    }
    // }}}
    // {{{ testPatchSubtreeInsertAndDelete
    /**
     * removing the text node and adding a page below P3.1, patched
     * through its ancestor Home3, has to be applied without touching the
     * nodes outside of the path to the changes; the result must be equal
     * to what a full saveNode() writes
     */
    public function testPatchSubtreeInsertAndDelete()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $before = $this->getNodeRows();

        $dom = $this->getCurrentDom();

        $p31 = $this->findPage($dom, 'P3.1');
        $this->assertNotNull($p31);
        $p31->removeChild($p31->firstChild);

        $new = $dom->createElementNS('http://www.depagecms.net/ns/page', 'pg:subpage');
        $new->setAttribute('name', 'inserted into subtree');
        $p31->appendChild($new);

        // patching P3.1 itself would have to rewrite 2 of its 3 nodes and
        // fall back; through the Home3 subtree the change stays under
        // PATCH_MAX_CHANGE_RATIO
        $home = $this->findPage($dom, 'Home3');
        $this->assertNotNull($home);
        $this->assertSame(5, $this->doc->saveNodePatch($home));

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        $this->assertSame(1, $stats['inserts']);
        $this->assertSame(1, $stats['deletes']);
        $this->assertSame(0, $stats['updates']);

        $rows = $this->getNodeRows();

        // the deleted text node is gone, the sibling page kept its row
        $this->assertFalse(isset($rows[30]));
        $this->assertTrue(isset($rows[7]));
        $this->assertSame($before[7]['value'], $rows[7]['value']);

        // the new page went in behind the children of node 6
        $children = $this->childIdsOf(6);
        $this->assertCount(2, $children);
        $this->assertSame(7, $children[0]);

        $new_id = $children[1];
        $this->assertNotContains($new_id, [4, 5, 6, 7, 8, 30]);
        $this->assertSame('pg:subpage', $rows[$new_id]['name']);

        // the untouched sibling subtree kept its row (node 8 was skipped
        // by its subhash), the ancestors only changed their subhash
        $this->assertSame($before[8], $rows[8]);
        foreach ([6, 5] as $id) {
            $this->assertSame($before[$id]['id_parent'], $rows[$id]['id_parent']);
            $this->assertSame($before[$id]['pos'], $rows[$id]['pos']);
            $this->assertSame($before[$id]['value'], $rows[$id]['value']);
        }

        // the document root outside the patched subtree keeps its row,
        // only its subhash follows the change below it
        $this->assertSame($before[4]['id_parent'], $rows[4]['id_parent']);
        $this->assertSame($before[4]['pos'], $rows[4]['pos']);
        $this->assertSame($before[4]['value'], $rows[4]['value']);
        $this->assertNotSame($before[4]['subhash'], $rows[4]['subhash']);

        // a full saveNode of the same subtree results in the same xml
        $patched = $this->doc->getXml(false);

        $dom = $this->getCurrentDom();
        $this->doc->saveNode($this->findPage($dom, 'Home3'));

        $this->assertXmlStringEqualsXmlStringIgnoreLastchange($patched, $this->doc->getXml(false));
    }
    // }}}
    // {{{ testPatchSubtreeFallsBackOnIdlessRoot
    /**
     * a subtree whose root element carries no db:id can not be matched
     * against the stored tree; the call has to fall back to a full
     * saveNode() and must not damage the document
     */
    public function testPatchSubtreeFallsBackOnIdlessRoot()
    {
        $this->doc->savePatch($this->getCurrentDom());
        $beforeXml = $this->doc->getXml(false);
        $before = $this->getNodeRows();

        $dom = $this->getCurrentDom();
        $p31 = $this->findPage($dom, 'P3.1');
        $this->assertNotNull($p31);
        $p31->removeAttributeNS('http://cms.depagecms.net/ns/database', 'id');

        $this->doc->saveNodePatch($p31);

        $stats = $this->doc->getLastPatchStats();
        $this->assertTrue($stats['fallback']);

        // the nodes of the stored document were not damaged, it still
        // reads back as before
        $this->assertXmlStringEqualsXmlStringIgnoreLastchange($beforeXml, $this->doc->getXml(false));

        $after = $this->getNodeRows();
        foreach ($before as $id => $row) {
            $this->assertSame($row, $after[$id], "node {$id}");
        }
    }
    // }}}
    // {{{ testPatchSubtreeFallsBackOnReplacedRoot
    /**
     * an element carrying the db:id of a node of another name replaced
     * that node instead of changing it; the patch would rename the stored
     * node in place, so a full saveNode() has to be done instead
     */
    public function testPatchSubtreeFallsBackOnReplacedRoot()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $dom = $this->getCurrentDom();
        $p31 = $this->findPage($dom, 'P3.1');
        $this->assertNotNull($p31);

        // an element of another name claiming the db:id of the stored
        // page; the attributes are copied explicitly, cloning them with
        // setAttributeNode(cloneNode()) drops the namespace prefix on
        // older php dom versions
        $section = $dom->createElementNS('http://www.depagecms.net/ns/page', 'pg:section');
        $section->setAttribute('name', $p31->getAttribute('name'));
        $section->setAttributeNS('http://cms.depagecms.net/ns/database', 'db:id', (string) $p31->getAttributeNS('http://cms.depagecms.net/ns/database', 'id'));
        while ($p31->firstChild) {
            $section->appendChild($p31->firstChild);
        }
        $p31->parentNode->replaceChild($section, $p31);

        $this->assertSame(6, $this->doc->saveNodePatch($section));

        $stats = $this->doc->getLastPatchStats();
        $this->assertTrue($stats['fallback']);

        // the subtree was rewritten with the replaced root
        $xml = $this->doc->getXml(false);
        $this->assertStringContainsString('pg:section', $xml);
        $this->assertStringContainsString('P3.1.2', $xml);

        $rows = $this->getNodeRows();
        $this->assertStringContainsString('name="P3.1"', $rows[6]['value']);
        $this->assertSame('pg:section', $rows[6]['name']);
    }
    // }}}
    // {{{ testPatchSubtreeFallsBackOnMassiveStructuralChange
    /**
     * when most of the nodes of the subtree have to be deleted (here:
     * both of its children) a full saveNode() is cheaper than patching
     * and gets done instead
     */
    public function testPatchSubtreeFallsBackOnMassiveStructuralChange()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $dom = $this->getCurrentDom();

        $p31 = $this->findPage($dom, 'P3.1');
        $this->assertNotNull($p31);
        $p31->removeChild($p31->firstChild);

        $p312 = $this->findPage($dom, 'P3.1.2');
        $this->assertNotNull($p312);
        $p31->removeChild($p312);

        $this->assertSame(6, $this->doc->saveNodePatch($p31));

        $stats = $this->doc->getLastPatchStats();
        $this->assertTrue($stats['fallback']);

        // the fallback saved the reduced subtree, the rest of the document
        // is untouched
        $rows = $this->getNodeRows();
        $this->assertFalse(isset($rows[7]));
        $this->assertFalse(isset($rows[30]));
        $this->assertSame([4, 5, 6, 8], $this->getElementRowIds($rows));
        $this->assertStringContainsString('name="P3.1"', $rows[6]['value']);
    }
    // }}}
    // {{{ testPatchSubtreeOnDocumentNode
    /**
     * passing the whole document to saveNodePatch patches it like
     * savePatch() does (the document node is descended to its root
     * element, which carries the id of the stored document root)
     */
    public function testPatchSubtreeOnDocumentNode()
    {
        $this->doc->savePatch($this->getCurrentDom());

        $before = $this->getNodeRows();

        $this->assertSame(4, $this->doc->saveNodePatch($this->getCurrentDom()));

        $stats = $this->doc->getLastPatchStats();
        $this->assertFalse($stats['fallback']);
        $this->assertSame(1, $stats['identical']);
        $this->assertSame(0, $stats['updates']);
        $this->assertSame(0, $stats['deletes']);
        $this->assertSame(0, $stats['inserts']);
        // for a patch starting at the document root the subtree is the
        // whole document
        $this->assertSame(6, $stats['old_nodes']);

        $this->assertSame($before, $this->getNodeRows());
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
