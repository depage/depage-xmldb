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

    // {{{ testQueryElementInnerTextMultiple
    /**
     * the batched innerText resolution has to return exactly what the
     * subdocument based resolution returned, for every matched node
     * (document 5 has nested pages, which are overlapping subtrees)
     */
    public function testQueryElementInnerTextMultiple()
    {
        $results = $this->indexer->query('//pg:page', 5);

        $this->assertGreaterThan(1, count($results));

        $byNode = [];
        foreach ($results as $result) {
            $byNode[$result->nodeId] = $result;
        }

        foreach ($byNode as $nodeId => $result) {
            $expected = (string) $this->doc->getSubdocByNodeId($nodeId, false)->textContent;

            $this->assertSame($expected, $result->value, "innerText of node {$nodeId}");
            $this->assertSame(5, $result->docId);
        }

        // the nested subtree is not just its own text but includes the
        // text of its children
        $root = $byNode[15] ?? reset($byNode);
        $this->assertStringContainsString('bla bla', $root->value);
    }
    // }}}
    // {{{ testQueryResolvesLang
    /**
     * the lang attribute of the matched node is reported independently
     * of the targeted attribute
     */
    public function testQueryResolvesLang()
    {
        $this->doc->setAttribute(19, 'lang', 'de');

        $results = $this->indexer->query('//pg:page/@name', 5);

        $lang = [];
        foreach ($results as $result) {
            $lang[$result->nodeId] = $result->lang;
        }

        $this->assertSame('de', $lang[19]);
        unset($lang[19]);
        $this->assertSame([], array_filter($lang, fn($v) => !is_null($v)));
    }
    // }}}
    // {{{ testQueryUsesConstantNumberOfStatements
    /**
     * resolving many nodes must not multiply the queries: the number of
     * executed statements is independent of the number of matches
     */
    public function testQueryUsesConstantNumberOfStatements()
    {
        $measure = function (string $xpath) {
            $status = fn() => (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(\PDO::FETCH_ASSOC)['Value'];

            $before = $status();
            $count = count($this->indexer->query($xpath, 5));
            $delta = $status() - $before;

            return [$delta, $count];
        };

        // attribute target: one node versus six
        [$fewDelta, $few] = $measure('//dpg:pages/@name');
        [$manyDelta, $many] = $measure('//pg:page/@name');

        $this->assertSame(1, $few);
        $this->assertGreaterThan(1, $many);
        $this->assertSame($fewDelta, $manyDelta, 'statement count must not depend on the number of matches');

        // element target: the text content of all subtrees is one
        // additional statement, not one per node
        [$textDelta] = $measure('//pg:page');
        $this->assertLessThanOrEqual($manyDelta + 1, $textDelta);
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
