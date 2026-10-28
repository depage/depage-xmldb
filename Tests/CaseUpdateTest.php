<?php

namespace Depage\XmlDb\Tests;

use Depage\XmlDb\Exceptions\XmlDbException;
use Depage\XmlDb\Helpers\CaseUpdate;

/**
 * tests for Helpers\CaseUpdate, the buffered updates of refreshSubhashes()
 * and rebalancePositions(): the buffer writes many rows with as few
 * statements as possible, only touches the rows it was told to and binds
 * its values as parameters, so the caller does not have to escape them.
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
class CaseUpdateTest extends XmlDbTestCase
{
    // {{{ variables
    protected $xmlTree;

    /**
     * connection the buffers of a test write through, it counts the
     * statements they need
     */
    protected $connection;
    // }}}
    // {{{ setUp
    protected function setUp(): void
    {
        parent::setUp();

        $this->xmlTree = $this->pdo->prefix . '_proj_test_xmltree';
        $this->connection = new CountingPdo($this->pdo);
    }
    // }}}

    // {{{ buffer
    /**
     * @param $size (int) number of rows a single statement writes
     */
    protected function buffer($size = 10)
    {
        $this->connection->reset();

        return new CaseUpdate($this->connection, $this->xmlTree, $size);
    }
    // }}}
    // {{{ getValues
    /**
     * the subhash of the given rows as id => subhash
     */
    protected function getValues(array $ids)
    {
        $values = [];

        foreach ($ids as $id) {
            $values[$id] = $this->pdo->query(
                "SELECT subhash FROM {$this->xmlTree} WHERE id = " . (int) $id
            )->fetchColumn();
        }

        return $values;
    }
    // }}}

    // {{{ testWritesCollectedValues
    /**
     * every row of the buffer gets the value it was collected with, the
     * other rows of the document keep theirs
     */
    public function testWritesCollectedValues()
    {
        $before = $this->getValues([4, 5, 6, 30]);

        $updates = $this->buffer();
        $updates->add(5, 'hash of 5');
        $updates->add(30, "hash' with quote");

        $this->assertFalse($updates->isEmpty());

        $updates->write('subhash', ['id_doc' => 3]);

        $after = $this->getValues([4, 5, 6, 30]);

        $this->assertTrue($updates->isEmpty(), 'the buffer has to be empty after writing');
        $this->assertSame('hash of 5', $after[5]);
        $this->assertSame("hash' with quote", $after[30], 'values are written as parameters');
        $this->assertSame($before[4], $after[4], 'a row that was not collected is not touched');
        $this->assertSame($before[6], $after[6]);
    }
    // }}}
    // {{{ testCollectingARowTwiceKeepsTheLastValue
    public function testCollectingARowTwiceKeepsTheLastValue()
    {
        $updates = $this->buffer();
        $updates->add(5, 'first');
        $updates->add(5, 'last');
        $updates->write('subhash', ['id_doc' => 3]);

        $this->assertSame('last', $this->getValues([5])[5]);
    }
    // }}}
    // {{{ testWritesInChunksOfTheConfiguredSize
    /**
     * more rows than the buffer holds are written with one statement per
     * chunk, and every row of every chunk gets its own value
     */
    public function testWritesInChunksOfTheConfiguredSize()
    {
        $ids = [4, 5, 6, 7, 8, 30];

        $updates = $this->buffer(2);
        foreach ($ids as $i => $id) {
            $updates->add($id, 'hash ' . $i);
        }
        $updates->write('subhash', ['id_doc' => 3]);

        $this->assertSame(3, $this->connection->statements, 'six rows in chunks of two need three statements');
        $this->assertSame(
            array_combine($ids, array_map(fn($i) => 'hash ' . $i, array_keys($ids))),
            $this->getValues($ids)
        );
    }
    // }}}
    // {{{ testIsFullFollowsBufferSize
    public function testIsFullFollowsBufferSize()
    {
        $updates = $this->buffer(2);

        $updates->add(5, 'hash');
        $this->assertFalse($updates->isFull(), 'a buffer with one of two rows is not full');

        $updates->add(6, 'hash');
        $this->assertTrue($updates->isFull());

        $updates->write('subhash', ['id_doc' => 3]);

        $this->assertFalse($updates->isFull(), 'the buffer has to be empty after writing');
        $this->assertSame(1, $this->connection->statements, 'the rows of a chunk are written with one statement');
    }
    // }}}
    // {{{ testConditionsLimitTheWrittenRows
    /**
     * a value collected for a row of another document is not written, the
     * conditions of the update keep the rows of the other document untouched
     */
    public function testConditionsLimitTheWrittenRows()
    {
        $foreign = $this->pdo->query(
            "SELECT id, subhash FROM {$this->xmlTree} WHERE id_doc = 4 LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($foreign);

        $updates = $this->buffer();
        $updates->add(5, 'hash of 5');
        $updates->add($foreign['id'], 'hash of another document');

        $updates->write('subhash', ['id_doc' => 3]);

        $this->assertSame('hash of 5', $this->getValues([5])[5]);
        $this->assertSame($foreign['subhash'], $this->getValues([$foreign['id']])[$foreign['id']]);
    }
    // }}}
    // {{{ testWritingAnEmptyBufferDoesNothing
    public function testWritingAnEmptyBufferDoesNothing()
    {
        $before = $this->getValues([4, 5, 6]);

        $updates = $this->buffer();

        $updates->write('subhash', ['id_doc' => 3]);
        $updates->write('subhash', ['id_doc' => 3]);

        $this->assertSame(0, $this->connection->statements, 'an empty buffer must not write anything');
        $this->assertSame($before, $this->getValues([4, 5, 6]));
    }
    // }}}
    // {{{ testRejectsColumnNamesThatAreNotIdentifiers
    /**
     * column names can not be bound as parameters, so they are checked to
     * be plain identifiers before they are written into a statement
     */
    public function testRejectsColumnNamesThatAreNotIdentifiers()
    {
        $updates = $this->buffer();
        $updates->add(5, 'hash of 5');

        $this->assertTrue($this->rejectsWrite($updates, 'subhash = subhash WHERE id > 0 OR id'));
        $this->assertNotSame('hash of 5', $this->getValues([5])[5]);
    }
    // }}}
    // {{{ testRejectsConditionNamesThatAreNotIdentifiers
    public function testRejectsConditionNamesThatAreNotIdentifiers()
    {
        $updates = $this->buffer();
        $updates->add(5, 'hash of 5');

        $this->assertTrue($this->rejectsWrite($updates, 'subhash', ['id_doc = 3 OR 1' => 1]));
        $this->assertNotSame('hash of 5', $this->getValues([5])[5]);
    }
    // }}}
    // {{{ testRejectsTableNamesThatAreNotIdentifiers
    public function testRejectsTableNamesThatAreNotIdentifiers()
    {
        $this->expectException(XmlDbException::class);

        new CaseUpdate($this->connection, $this->xmlTree . ' WHERE id_doc = 1');
    }
    // }}}
    // {{{ rejectsWrite
    /**
     * writes a buffer and reports whether the buffer rejected it instead of
     * writing to the database
     */
    protected function rejectsWrite($updates, $column, $conditions = [])
    {
        $rejected = false;

        try {
            $updates->write($column, $conditions);
        } catch (XmlDbException $e) {
            $rejected = true;
        }

        $this->assertSame(0, $this->connection->statements, 'a rejected name must not reach the database');

        return $rejected;
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
