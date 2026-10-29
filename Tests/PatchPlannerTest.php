<?php

namespace Depage\XmlDb\Tests;

use Depage\XmlDb\Patch\PatchPlanner;
use Depage\XmlDb\XmlNs;

/**
 * tests for Patch\PatchPlanner, the read-only comparison between a dom
 * and the stored tree.
 *
 * The planner is exercised directly (not through savePatch): the input is
 * a flattened node array and the result a plan, so the plan shape can be
 * asserted without writing anything; that the stored rows are untouched
 * after planning is part of the contract too.
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
class PatchPlannerTest extends XmlDbTestCase
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

    // {{{ planner
    /**
     * a planner reading the same document the test document is built on
     */
    protected function planner(): PatchPlanner
    {
        return new PatchPlanner(
            $this->pdo,
            $this->xmlTree,
            3,
            new XmlNs('db', 'http://cms.depagecms.net/ns/database'),
            'id'
        );
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
    // {{{ resave
    /**
     * stores the document with the current version of the library (which
     * canonicalizes the stored values and refreshes the subhashes) and
     * hands back the dom with the ids it has now
     */
    protected function resave()
    {
        $this->doc->save($this->getCurrentDom());

        return $this->getCurrentDom();
    }
    // }}}
    // {{{ nodeArrayFor
    /**
     * run a dom through the same flattening the save and the patch code
     * share
     */
    protected function nodeArrayFor($dom): array
    {
        $node_array = [];
        $this->doc->getNodeArrayForSaving($node_array, $dom);

        return $node_array;
    }
    // }}}
    // {{{ dbIdOf
    /**
     * the db:id an element carries
     */
    protected function dbIdOf($element)
    {
        return (int) $element->getAttributeNS('http://cms.depagecms.net/ns/database', 'id');
    }
    // }}}
    // {{{ elementByName
    /**
     * the element with the given name attribute
     */
    protected function elementByName($dom, $name)
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('pg', 'http://www.depagecms.net/ns/page');

        return $xpath->query("//pg:page[@name=\"{$name}\"]")->item(0);
    }
    // }}}
    // {{{ textChildOf
    /**
     * the first text with content below an element (the stored text node
     * of the dataset, not the formatting whitespace of a serialization)
     */
    protected function textChildOf($element)
    {
        foreach ($element->childNodes as $child) {
            if ($child->nodeType == XML_TEXT_NODE && trim($child->textContent) != '') {
                return $child;
            }
        }

        $this->fail('no text node below ' . $element->nodeName);
    }
    // }}}
    // {{{ storedRows
    /**
     * a snapshot of the stored rows to compare planning runs against
     */
    protected function storedRows(): string
    {
        $rows = $this->pdo->query(
            "SELECT CONCAT(id, '|', IFNULL(id_parent, ''), '|', type, '|', IFNULL(name, ''), '|', IFNULL(value, ''))
            FROM {$this->xmlTree}
            WHERE id_doc = 3
            ORDER BY id ASC"
        )->fetchAll(\PDO::FETCH_COLUMN);

        return implode("\n", $rows);
    }
    // }}}

    // {{{ testUnchangedDocumentPlansNoChanges
    /**
     * planning a saved document that was not changed against the database
     * collects no changes; the whole tree is recognized through the
     * subhash the full save left on the root node, so the planner does
     * not even descend into the tree
     */
    public function testUnchangedDocumentPlansNoChanges()
    {
        $dom = $this->resave();

        $plan = $this->planner()->plan($this->nodeArrayFor($dom));

        $this->assertNotNull($plan);
        $this->assertEquals($this->dbIdOf($dom->documentElement), $plan['root_id']);
        $this->assertCount(0, $plan['updates']);
        $this->assertCount(0, $plan['deletes']);
        $this->assertCount(0, $plan['inserts']);
        $this->assertCount(0, $plan['renumber']);
        $this->assertGreaterThan(0, $plan['stats']['identical']);
        $this->assertEquals(6, $plan['stats']['old_nodes']);
        $this->assertEquals(6, $plan['stats']['new_nodes']);
    }
    // }}}
    // {{{ testLegacyRowsPlanAValueRefresh
    /**
     * the dataset rows were stored by an older version of the library,
     * whose attribute strings had no trailing space after the last
     * attribute; planning them against the canonical form of the dom
     * collects one value update per element row (the text row is stored
     * identically by both versions), after which the document behaves
     * like the resaved one
     */
    public function testLegacyRowsPlanAValueRefresh()
    {
        $plan = $this->planner()->plan($this->nodeArrayFor($this->getCurrentDom()));

        $this->assertNotNull($plan);

        $updated = array_column($plan['updates'], 'id');
        sort($updated);

        $this->assertEquals([4, 5, 6, 7, 8], $updated);
        $this->assertCount(0, $plan['deletes']);
        $this->assertCount(0, $plan['inserts']);

        foreach ($plan['updates'] as $update) {
            $this->assertStringEndsWith(' ', $update['value'], 'canonical attribute strings end in a space');
        }
    }
    // }}}
    // {{{ testEditTextNodePlansUpdateOfThatNode
    /**
     * an edited text node is a single row update of exactly that node,
     * its parent and its siblings are not touched
     */
    public function testEditTextNodePlansUpdateOfThatNode()
    {
        $dom = $this->resave();

        $page = $this->elementByName($dom, 'P3.1');
        $this->textChildOf($page)->nodeValue = 'bla bla changed';

        $plan = $this->planner()->plan($this->nodeArrayFor($dom));

        $this->assertNotNull($plan);
        $this->assertCount(1, $plan['updates']);
        $this->assertEquals('bla bla changed', $plan['updates'][0]['value']);
        $this->assertCount(0, $plan['deletes']);
        $this->assertCount(0, $plan['inserts']);
        $this->assertCount(0, $plan['renumber']);
    }
    // }}}
    // {{{ testNewNodePlansInsertAndRenumberOfParent
    /**
     * a node without a db:id is planned as an insert, and the child list
     * of its parent is planned to be reordered so that the new node takes
     * its place at the end
     */
    public function testNewNodePlansInsertAndRenumberOfParent()
    {
        $dom = $this->resave();

        $page = $this->elementByName($dom, 'Home3');
        $pageId = $this->dbIdOf($page);

        $children = [];
        foreach ($page->childNodes as $child) {
            if ($child->nodeType == XML_ELEMENT_NODE) {
                $children[] = ['id' => $this->dbIdOf($child)];
            }
        }
        $this->assertCount(2, $children);

        $new = $dom->createElementNS('http://www.depagecms.net/ns/page', 'pg:page');
        $new->appendChild($dom->createTextNode('new page'));
        $page->appendChild($new);

        $plan = $this->planner()->plan($this->nodeArrayFor($dom));

        $this->assertNotNull($plan);
        $this->assertCount(1, $plan['inserts']);
        $this->assertEquals($pageId, $plan['inserts'][0]['parent_id']);
        $this->assertCount(0, $plan['updates']);
        $this->assertCount(0, $plan['deletes']);

        $this->assertArrayHasKey($pageId, $plan['renumber']);
        $this->assertEquals(
            array_merge($children, [['insert' => 0]]),
            $plan['renumber'][$pageId]
        );
    }
    // }}}
    // {{{ testRemovedNodePlansDelete
    /**
     * a node that disappeared from the dom is planned as a delete of the
     * row it was stored in
     */
    public function testRemovedNodePlansDelete()
    {
        $dom = $this->resave();

        $page = $this->elementByName($dom, 'P3.2');
        $pageId = $this->dbIdOf($page);
        $page->parentNode->removeChild($page);

        $plan = $this->planner()->plan($this->nodeArrayFor($dom));

        $this->assertNotNull($plan);
        $this->assertEquals([$pageId], $plan['deletes']);
        $this->assertCount(0, $plan['inserts']);
        $this->assertCount(0, $plan['updates']);
    }
    // }}}
    // {{{ testReplacedRootIsRejectedOnlyWithCheckRoot
    /**
     * a root that keeps its db:id but is not compatible with the row that
     * id points to is only rejected when the caller asks for the root
     * check (saveNodePatch() does, to fall back to a full save)
     */
    public function testReplacedRootIsRejectedOnlyWithCheckRoot()
    {
        $dom = $this->resave();

        $root = $dom->documentElement;
        $rootId = $this->dbIdOf($root);

        // replace the root element with one of another name that carries
        // the same db:id
        $other = $dom->createElementNS('http://www.depagecms.net/ns/depage', 'dpg:other');
        $other->setAttributeNS('http://cms.depagecms.net/ns/database', 'db:id', (string) $rootId);
        while ($root->firstChild != null) {
            $other->appendChild($root->firstChild);
        }
        $dom->replaceChild($other, $root);

        $this->assertNull($this->planner()->plan($this->nodeArrayFor($dom), true));

        // without the check it is an ordinary rename of the row
        $plan = $this->planner()->plan($this->nodeArrayFor($dom), false);

        $this->assertNotNull($plan);
        $this->assertCount(1, $plan['updates']);
        $this->assertEquals($rootId, $plan['updates'][0]['id']);
        $this->assertEquals('dpg:other', $plan['updates'][0]['name']);
    }
    // }}}
    // {{{ testUnknownRootIdPlansNothing
    /**
     * a root id the database does not know can not be patched
     */
    public function testUnknownRootIdPlansNothing()
    {
        $dom = $this->getCurrentDom();

        $dom->documentElement->setAttributeNS(
            'http://cms.depagecms.net/ns/database',
            'db:id',
            '999999'
        );

        $this->assertNull($this->planner()->plan($this->nodeArrayFor($dom)));
    }
    // }}}
    // {{{ testTooManyChangesIsNotPatched
    /**
     * when more than PATCH_MAX_CHANGE_RATIO of the subtree would have to
     * be inserted or deleted the planner hands back null, so the caller
     * can do a full save instead
     */
    public function testTooManyChangesIsNotPatched()
    {
        $dom = $this->resave();

        $page = $this->elementByName($dom, 'Home3');
        foreach (iterator_to_array($page->childNodes) as $child) {
            $page->removeChild($child);
        }
        for ($i = 0; $i < 8; $i++) {
            $new = $dom->createElementNS('http://www.depagecms.net/ns/page', 'pg:page');
            $new->appendChild($dom->createTextNode("page $i"));
            $page->appendChild($new);
        }

        $this->assertNull($this->planner()->plan($this->nodeArrayFor($dom)));
    }
    // }}}
    // {{{ testPlanningDoesNotTouchTheDatabase
    /**
     * planning is read-only: even a plan with changes leaves every stored
     * row exactly as it was
     */
    public function testPlanningDoesNotTouchTheDatabase()
    {
        $dom = $this->resave();

        $this->textChildOf($this->elementByName($dom, 'P3.1'))->nodeValue = 'would be a change';

        $before = $this->storedRows();

        $this->planner()->plan($this->nodeArrayFor($dom));

        $this->assertEquals($before, $this->storedRows());
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
