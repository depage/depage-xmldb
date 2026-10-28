<?php

namespace Depage\XmlDb\Tests;

/**
 * tests for the merkle style subhash column (schema 2.3.0): the subhash
 * of a node covers its stored content and the subhashes of its children,
 * it does not depend on the node id and only changes when the subtree
 * below (and with it the node itself) changes.
 */
class SubhashTest extends XmlDbTestCase
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

    // {{{ getSubhashes
    /**
     * all rows of document 3 as id => subhash
     */
    protected function getSubhashes()
    {
        $subhashes = [];

        foreach ($this->pdo->query(
            "SELECT id, subhash FROM {$this->xmlTree} WHERE id_doc = 3"
        ) as $row) {
            $subhashes[(int) $row['id']] = $row['subhash'];
        }

        return $subhashes;
    }
    // }}}
    // {{{ getAncestorIds
    protected function getAncestorIds($id)
    {
        $ancestors = [];

        while (($id = $this->doc->getParentIdById($id)) && $id !== false) {
            $ancestors[] = (int) $id;
        }

        return $ancestors;
    }
    // }}}
    // {{{ getChildIds
    protected function getChildIds($parent_id)
    {
        return array_map('intval', $this->pdo->query(
            "SELECT id FROM {$this->xmlTree} WHERE id_doc = 3 AND id_parent = {$parent_id}"
        )->fetchAll(\PDO::FETCH_COLUMN));
    }
    // }}}
    // {{{ generateDomDocument
    protected function generateDomDocument($xml)
    {
        $doc = new \Depage\Xml\Document();
        $doc->loadXML($xml);

        return $doc;
    }
    // }}}

    // {{{ testSubhashesAreBackfilledOnChange
    public function testSubhashesAreBackfilledOnChange()
    {
        $before = $this->getSubhashes();
        $this->assertNotEmpty($before);
        foreach ($before as $subhash) {
            $this->assertNull($subhash);
        }

        $this->doc->setAttribute(8, 'attr_a', 'value');

        $after = $this->getSubhashes();
        foreach ($after as $id => $subhash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $subhash, "node {$id}");
        }
    }
    // }}}
    // {{{ testIdenticalSubtreesShareSubhash
    /**
     * subhashes are independent of the node id, two equal subtrees at
     * different places have the same hash
     */
    public function testIdenticalSubtreesShareSubhash()
    {
        $this->doc->setAttribute(8, 'attr_a', 'value');

        $first = $this->doc->addNode($this->generateDomDocument('<same><child/></same>'), 5, 99);
        $this->doc->addNode($this->generateDomDocument('<same><child/></same>'), 5, 99);

        $subhashes = $this->getSubhashes();

        $siblings = $this->getChildIds(5);
        $last = $siblings[count($siblings) - 1];

        $this->assertSame($subhashes[$first], $subhashes[$last]);
        $this->assertNotSame($subhashes[$first], $subhashes[5]);
    }
    // }}}
    // {{{ testChangedHashesAreLimitedToTheSubtreeChain
    public function testChangedHashesAreLimitedToTheSubtreeChain()
    {
        $this->doc->setAttribute(8, 'attr_a', 'start');

        $before = $this->getSubhashes();

        $this->doc->setAttribute(7, 'attr_b', 'next');

        $after = $this->getSubhashes();

        // the changed node and all its ancestors have to change
        $changed = array_merge([7], $this->getAncestorIds(7));
        foreach ($changed as $id) {
            $this->assertNotSame($before[$id], $after[$id], "ancestor chain node {$id}");
        }

        // everything else keeps its hash: the subtree of node 7 stays
        // untouched as well as all nodes outside of the chain
        $chain = array_flip($changed);
        foreach ($before as $id => $subhash) {
            if (isset($chain[$id])) {
                continue;
            }
            $this->assertSame($subhash, $after[$id], "node outside of the chain {$id}");
        }
    }
    // }}}
    // {{{ testMoveKeepsSubtreeSubhash
    /**
     * moving a subtree changes the hashes of the parents but not the
     * hash of the moved node itself
     */
    public function testMoveKeepsSubtreeSubhash()
    {
        $this->doc->setAttribute(8, 'attr_a', 'start');

        $before = $this->getSubhashes();

        $this->doc->moveNode(8, 6, 99);

        $after = $this->getSubhashes();

        $this->assertSame($before[8], $after[8]);
        $this->assertNotSame($before[6], $after[6]);
        $this->assertNotSame($before[5], $after[5]);
    }
    // }}}
    // {{{ testRecalculationIsIdempotent
    /**
     * recalculating without changing anything must not write any node
     */
    public function testRecalculationIsIdempotent()
    {
        $this->doc->setAttribute(8, 'attr_a', 'start');
        $before = $this->getSubhashes();

        // a change to the lastchange timestamp marks the subhashes dirty,
        // but no node content changes, so no subhash changes
        $this->doc->beginTransactionAltering();
        $this->doc->updateLastChange(time() + 10);
        $this->doc->endTransaction();

        $after = $this->getSubhashes();
        $this->assertSame($before, $after);
    }
    // }}}
    // {{{ testSubhashesOfMoreNodesThanOneBatch
    /**
     * the recalculated hashes of a document that is bigger than one batch
     * are written with several statements; every node has to end up with
     * its own hash, and equal subtrees of different batches have to share
     * theirs, which shows hashes that were written to the wrong nodes
     */
    public function testSubhashesOfMoreNodesThanOneBatch()
    {
        $count = 2 * DocumentTestClass::SAVE_BATCH_SIZE + 5;

        $dom = new \Depage\Xml\Document();
        $dom->loadXML($this->doc->getXml(false));

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('pg', 'http://www.depagecms.net/ns/page');
        $home = $xpath->query('//pg:page[@name="Home3"]')->item(0);

        for ($i = 0; $i < $count; $i++) {
            $same = $dom->createElementNS('http://www.depagecms.net/ns/page', 'pg:same');
            $same->appendChild($dom->createTextNode('content ' . ($i % 2)));
            $home->appendChild($same);
        }

        $this->doc->save($dom);

        // the tree is far bigger than one batch and every node has a hash
        $subhashes = $this->getSubhashes();
        $this->assertGreaterThan(2 * DocumentTestClass::SAVE_BATCH_SIZE, count($subhashes));
        foreach ($subhashes as $id => $subhash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $subhash, "node {$id}");
        }

        $this->assertSame(
            $count,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM {$this->xmlTree} WHERE id_doc = 3 AND name = 'pg:same'"
            )->fetchColumn()
        );

        // the paragraphs alternate two contents, so equal subtrees all over
        // the batches have to share exactly two hashes
        $distinct = (int) $this->pdo->query(
            "SELECT COUNT(DISTINCT subhash) FROM {$this->xmlTree} WHERE id_doc = 3 AND name = 'pg:same'"
        )->fetchColumn();
        $this->assertSame(2, $distinct, 'equal subtrees of different batches must share their subhash');
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
