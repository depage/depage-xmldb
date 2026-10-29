<?php

namespace Depage\XmlDb\Tests;

class TransactionRollbackTest extends XmlDbTestCase
{
    // {{{ variables
    protected $xmlDb;
    protected $doc;
    protected $cache;
    // }}}
    // {{{ setUp
    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = \Depage\Cache\Cache::factory('rollbackTest', ['disposition' => 'uncached']);

        $this->xmlDb = new XmlDbTestClass($this->pdo->prefix . '_proj_test', $this->pdo, $this->cache, [
            'root',
            'child',
        ]);

        $this->doc = new DocumentTestClass($this->xmlDb, 3);
    }
    // }}}

    // {{{ testRollbackAfterFailedSaveKeepsDocumentIntact
    /**
     * a failing save (here: unsupported node type) must not leave the
     * transaction counter stuck, must undo its partial writes and must
     * not leave the connection in an open transaction.
     */
    public function testRollbackAfterFailedSaveKeepsDocumentIntact()
    {
        $before = $this->doc->getXml(false)->saveXML();

        $dom = new \Depage\Xml\Document();
        $dom->loadXML('<root><![CDATA[this node type cannot be saved]]></root>');

        try {
            $this->doc->save($dom);
            $this->fail('expected XmlDbException was not raised');
        } catch (\Depage\XmlDb\Exceptions\XmlDbException $e) {
            $this->assertStringContainsString('Unknown DOM node type', $e->getMessage());
        }

        $this->assertSame(0, $this->xmlDb->transactions);
        $this->assertFalse($this->xmlDb->alteringTransaction);

        // the real rollback of the outermost failed level ended the
        // surrounding test transaction as well, that is expected here;
        // it proves the connection-level rollback actually ran
        $this->assertFalse($this->pdo->inTransaction());

        $after = $this->doc->getXml(false)->saveXML();
        $this->assertXmlStringEqualsXmlString($before, $after);
    }
    // }}}
    // {{{ testRollbackAfterFailedSubdocRead
    /**
     * getSubdocByNodeId starts a (non altering) transaction itself, it has
     * to clean up after itself when the node does not exist.
     */
    public function testRollbackAfterFailedSubdocRead()
    {
        try {
            $this->doc->getSubdocByNodeId(1000);
            $this->fail('expected XmlDbException was not raised');
        } catch (\Depage\XmlDb\Exceptions\XmlDbException $e) {
            $this->assertStringContainsString('is no ELEMENT_NODE', $e->getMessage());
        }

        $this->assertSame(0, $this->xmlDb->transactions);

        // rolling back the failed read also ended the surrounding test
        // transaction (it was the only one open on the connection)
        $this->assertFalse($this->pdo->inTransaction());
    }
    // }}}
    // {{{ testRollBackResetsTransactionState
    public function testRollBackResetsTransactionState()
    {
        $this->xmlDb->beginTransactionAltering();
        $this->xmlDb->beginTransactionAltering();
        $this->assertSame(2, $this->xmlDb->transactions);

        $this->assertTrue($this->xmlDb->rollBack());

        $this->assertSame(0, $this->xmlDb->transactions);
        $this->assertFalse($this->xmlDb->alteringTransaction);
        $this->assertFalse($this->pdo->inTransaction());
    }
    // }}}
    // {{{ testRollBackWithoutTransactionIsNoop
    public function testRollBackWithoutTransactionIsNoop()
    {
        // an active transaction on the connection that was not started by
        // the library (here: the test wrapper) is rolled back, to recover
        // from a connection that was left dirty, but a second rollBack
        // without any transaction does nothing
        $this->assertTrue($this->pdo->inTransaction());
        $this->assertSame(0, $this->xmlDb->transactions);

        $this->assertTrue($this->xmlDb->rollBack());
        $this->assertFalse($this->pdo->inTransaction());

        $this->assertFalse($this->xmlDb->rollBack());
        $this->assertSame(0, $this->xmlDb->transactions);
    }
    // }}}
    // {{{ testSavepointRollbackKeepsOuterTransactionChanges
    /**
     * rolling back an inner transaction level must not discard the changes
     * of the outer level.
     */
    public function testSavepointRollbackKeepsOuterTransactionChanges()
    {
        $this->doc->beginTransactionAltering();

        // committed into the outer level through its own (savepoint) level
        $this->doc->setAttribute(5, 'attr_outer', 'outer');

        $this->doc->beginTransactionAltering();
        $this->doc->setAttribute(5, 'attr_inner', 'inner');

        // undo only the inner level
        $this->assertTrue($this->doc->rollBackTransaction());
        $this->assertSame(1, $this->xmlDb->transactions);

        $this->doc->endTransaction();
        $this->assertSame(0, $this->xmlDb->transactions);

        $attributes = $this->doc->getAttributes(5);
        $this->assertEquals('outer', $attributes['attr_outer'] ?? null);
        $this->assertFalse(isset($attributes['attr_inner']));

        // the wrapper transaction of the test is untouched
        $this->assertTrue($this->pdo->inTransaction());
    }
    // }}}
    // {{{ testRemoveDocClearsDocumentCacheNamespace
    public function testRemoveDocClearsDocumentCacheNamespace()
    {
        $cache = new MockCache();
        $xmlDb = new XmlDbTestClass($this->pdo->prefix . '_proj_test', $this->pdo, $cache, [
            'root',
            'child',
        ]);

        $doc = $xmlDb->createDoc(\Depage\XmlDb\XmlDoctypes\Base::class, '_rollback_test_doc');
        $docId = $doc->getDocId();

        $dom = new \Depage\Xml\Document();
        $dom->loadXML('<root><child/></root>');
        $doc->save($dom);

        $cache->deletedKeys = [];
        $this->assertTrue($xmlDb->removeDoc('_rollback_test_doc'));

        $this->assertContains("{$xmlDb->tableDocs}_d{$docId}/", $cache->deletedKeys);
    }
    // }}}
    // {{{ testDoctypeHandlerMissingClass
    public function testDoctypeHandlerMissingClass()
    {
        $this->pdo->exec("UPDATE xmldb_proj_test_xmldocs SET type='Depage\\\\XmlDb\\\\Tests\\\\NoSuchDoctypeHandler' WHERE id='3'");

        $this->expectException(\Depage\XmlDb\Exceptions\XmlDbException::class);
        $this->expectExceptionMessage('does not exist');

        $this->doc->getDoctypeHandler();
    }
    // }}}
    // {{{ testRealPdoTransactionBeginCommitRollback
    /**
     * the other tests stub begin/commitPdoTransaction for test isolation,
     * this one exercises the real pdo transaction calls on its own
     * (non-persistent) connection.
     */
    public function testRealPdoTransactionBeginCommitRollback()
    {
        $pdo = new \Depage\Db\Pdo($GLOBALS['DB_DSN'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASSWD']);
        $cache = \Depage\Cache\Cache::factory('rollbackTest', ['disposition' => 'uncached']);
        $xmlDb = new \Depage\XmlDb\XmlDb($this->pdo->prefix . '_proj_test', $pdo, $cache);

        // begin ... commit (empty transaction, no changes to keep)
        $xmlDb->beginTransactionNonAltering();
        $this->assertTrue($pdo->inTransaction());
        $xmlDb->endTransaction();
        $this->assertFalse($pdo->inTransaction());

        // begin ... rollBack
        $xmlDb->beginTransactionAltering();
        $this->assertTrue($pdo->inTransaction());
        $xmlDb->rollBack();
        $this->assertFalse($pdo->inTransaction());
        $this->assertFalse($xmlDb->alteringTransaction);
    }
    // }}}
    // {{{ testDoctypeHandlerInterfaceCheck
    public function testDoctypeHandlerInterfaceCheck()
    {
        $this->pdo->exec("UPDATE xmldb_proj_test_xmldocs SET type='Depage\\\\XmlDb\\\\Document' WHERE id='3'");

        $this->expectException(\Depage\XmlDb\Exceptions\XmlDbException::class);
        $this->expectExceptionMessage('Doctype handler must implement DoctypeInterface');

        $this->doc->getDoctypeHandler();
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
