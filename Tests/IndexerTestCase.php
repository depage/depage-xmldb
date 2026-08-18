<?php

namespace Depage\XmlDb\Tests;

abstract class IndexerTestCase extends XmlDbTestCase
{
    protected $xmlDb;
    protected $cache;
    protected $doc;
    protected $testObject;

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
    }
    // }}}
}
