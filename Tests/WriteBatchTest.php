<?php

namespace Depage\XmlDb\Tests;

/**
 * tests for the batched node inserts of saveNodeArray/saveNodesByType:
 * documents larger than SAVE_BATCH_SIZE span multiple batches and mix
 * nodes with database ids (written with an explicit id) and nodes that
 * get their id from the auto increment; the auto incremented ids have to
 * be read back for the tree structure (id_parent of the children) and
 * for the db:id attribute of the saved dom.
 */
class WriteBatchTest extends XmlDbTestCase
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

    // {{{ buildLargeDom
    /**
     * the current document with $count paragraphs added to "Home3"; each
     * paragraph is one element with a text node and a child element, so
     * the document spans several save batches and more than one tree
     * level
     */
    protected function buildLargeDom($count, $withIds)
    {
        $dom = new \Depage\Xml\Document();
        $dom->loadXML($this->doc->getXml($withIds));

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('pg', 'http://www.depagecms.net/ns/page');
        $home = $xpath->query('//pg:page[@name="Home3"]')->item(0);

        for ($i = 0; $i < $count; $i++) {
            $paragraph = $dom->createElementNS('http://www.depagecms.net/ns/page', 'pg:paragraph');
            $paragraph->setAttribute('class', 'text-' . $i);
            $paragraph->appendChild($dom->createTextNode('paragraph number ' . $i));

            $inner = $dom->createElementNS('http://www.depagecms.net/ns/page', 'pg:inner');
            $inner->setAttribute('index', (string) $i);
            $paragraph->appendChild($inner);

            $home->appendChild($paragraph);
        }

        return $dom;
    }
    // }}}
    // {{{ getNodeRows
    protected function getNodeRows()
    {
        $rows = [];

        foreach ($this->pdo->query(
            "SELECT id, id_parent, pos, type, name, value FROM {$this->xmlTree} WHERE id_doc = 3 ORDER BY id"
        ) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }
    // }}}

    // {{{ testSaveBiggerThanBatchWithoutIds
    /**
     * saving a document that spans several batches without any database
     * ids has to write the complete tree with unique auto incremented
     * ids and read them back correctly (children point at the right
     * parents, the document reads back unchanged)
     */
    public function testSaveBiggerThanBatchWithoutIds()
    {
        $count = 3 * DocumentTestClass::SAVE_BATCH_SIZE + 5;
        $dom = $this->buildLargeDom($count, false);

        $this->doc->save($dom);

        $rows = $this->getNodeRows();
        // the original 6 nodes (4 pages and their text node) are
        // replaced completely, plus the added paragraphs with their text
        // node and inner element each
        $expectedNodes = 6 + 3 * $count;
        $this->assertCount($expectedNodes, $rows);

        // the document reads back as it was saved, wrong parent/child
        // connections would show up as a different structure here
        $this->assertXmlStringEqualsXmlStringIgnoreAllDbAttributes(
            $dom->saveXML(),
            $this->doc->getXml(false)
        );

        // every element of the dom became an ELEMENT_NODE row and back
        $elements = 0;
        foreach ($rows as $row) {
            if ($row['type'] == 'ELEMENT_NODE') {
                $elements++;
            }
        }
        $this->assertSame($elements, $dom->getElementsByTagName('*')->length);
        $this->assertGreaterThan(3 * DocumentTestClass::SAVE_BATCH_SIZE, $elements);
    }
    // }}}
    // {{{ testSaveKeepsExistingDatabaseIds
    /**
     * a second save of the same (complete) document may not hand out new
     * ids for the existing element nodes (text nodes never carry a
     * db:id, they always get fresh ones)
     */
    public function testSaveKeepsExistingDatabaseIds()
    {
        $dom = $this->buildLargeDom(2 * DocumentTestClass::SAVE_BATCH_SIZE + 1, false);
        $this->doc->save($dom);

        $elementIds = fn() => array_keys(array_filter(
            $this->getNodeRows(),
            fn($row) => $row['type'] == 'ELEMENT_NODE'
        ));

        $idsAfterFirst = $elementIds();
        $this->assertGreaterThan(2 * DocumentTestClass::SAVE_BATCH_SIZE, count($idsAfterFirst));

        $reload = new \Depage\Xml\Document();
        $reload->loadXML($this->doc->getXml(true));
        $this->doc->save($reload);

        $this->assertSame($idsAfterFirst, $elementIds());
    }
    // }}}
    // {{{ testSaveSetsDbIdAttributesOnBatchedNodes
    /**
     * the batched nodes get their database id back into the dom (they
     * need it to be matched on the next savePatch)
     */
    public function testSaveSetsDbIdAttributesOnBatchedNodes()
    {
        $dom = $this->buildLargeDom(2 * DocumentTestClass::SAVE_BATCH_SIZE + 1, false);
        $this->doc->save($dom);

        $rows = $this->getNodeRows();

        $checked = 0;
        $attrList = [];
        $attributes = (new \DOMXPath($dom))->query('//@db:id');
        foreach ($attributes as $attribute) {
            $attrList[] = [$attribute->nodeValue, $attribute->parentNode];
        }
        $this->assertGreaterThan(2 * DocumentTestClass::SAVE_BATCH_SIZE, count($attrList));

        foreach ($attrList as [$id, $element]) {
            $this->assertArrayHasKey((int) $id, $rows, "db:id {$id} refers to a node that is not in the database");
            $this->assertSame(
                str_replace($element->prefix . ':', '', $element->nodeName),
                str_replace($element->prefix . ':', '', $rows[(int) $id]['name'])
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
