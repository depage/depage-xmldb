<?php

namespace Depage\XmlDb\Tests;

class IndexerTest extends XmlDbTestCase
{
    protected $xmlDb;
    protected $cache;
    protected $doc;
    protected $indexer;

    // {{{ setUp
    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = \Depage\Cache\Cache::factory('xmldb', ['disposition' => 'uncached']);
        $this->xmlDb = new XmlDbTestClass($this->pdo->prefix . '_proj_test', $this->pdo, $this->cache, [
            'root',
            'child',
        ]);
        $this->doc = new DocumentTestClass($this->xmlDb, 5);

        $this->indexer = new \Depage\XmlDb\Indexer($this->xmlDb);
    }
    // }}}

    // {{{ testQueryAttribute
    public function testQueryAttribute()
    {
        // "//pg:page/@name" targets the name attribute of every pg:page in doc 5
        $results = $this->indexer->query('//pg:page/@name', 5);

        $this->assertCount(6, $results);

        $values = array_map(
            function (\Depage\XmlDb\IndexerResult $r) {
                return $r->value;
            },
            $results,
        );
        sort($values);

        $this->assertEquals(
            [
                'Home5',
                'P5.1.1',
                'P5.1.2',
                'P5.1.2.3',
                'P5.1.3',
                'P5.2',
            ],
            $values,
        );

        foreach ($results as $result) {
            $this->assertInstanceOf(\Depage\XmlDb\IndexerResult::class, $result);
            $this->assertSame(5, $result->docId);
            $this->assertNull($result->lang);
        }
    }
    // }}}

    // {{{ testQueryAttributeMissing
    public function testQueryAttributeMissing()
    {
        // the attribute does not exist on any node, so no result is produced
        $this->assertSame([], $this->indexer->query('//pg:page/@noSuchAttribute', 5));
    }
    // }}}

    // {{{ testQueryEmptyAttribute
    public function testQueryEmptyAttribute()
    {
        // an existing but empty attribute yields an empty string value, not a
        // missing result
        $results = $this->indexer->query('//dpg:pages/@name', 5);

        $this->assertCount(1, $results);
        $this->assertSame('', $results[0]->value);
        $this->assertSame(15, $results[0]->nodeId);
        $this->assertSame(5, $results[0]->docId);
    }
    // }}}

    // {{{ testQueryElementInnerText
    public function testQueryElementInnerText()
    {
        // an element target returns the text content of its descendants
        $results = $this->indexer->query("/dpg:pages/pg:page/pg:folder/pg:page[@name = 'P5.1.2']", 5);

        $this->assertCount(1, $results);

        $result = $results[0];
        $this->assertSame(19, $result->nodeId);
        $this->assertSame(5, $result->docId);
        $this->assertNull($result->lang);

        // innerText is the concatenated text of all descendant text nodes
        $expected = (string) $this->doc->getSubdocByNodeId(19, false)->textContent;
        $this->assertSame($expected, $result->value);
        $this->assertStringContainsString('bla bla', $result->value);
    }
    // }}}

    // {{{ testQueryNoResult
    public function testQueryNoResult()
    {
        $this->assertSame([], $this->indexer->query('/nonode'));
    }
    // }}}

    // {{{ testQueryResultType
    public function testQueryResultType()
    {
        // omitting docId exercises the getDocByNodeId branch
        $results = $this->indexer->query('//dpg:tpl_template_set/@name');

        $this->assertCount(1, $results);
        $this->assertInstanceOf(\Depage\XmlDb\IndexerResult::class, $results[0]);
        $this->assertSame('html', $results[0]->value);
        $this->assertSame(2, $results[0]->nodeId);
        $this->assertSame(1, $results[0]->docId);
        $this->assertNull($results[0]->lang);
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
