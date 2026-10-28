<?php

namespace Depage\XmlDb\Tests;

use Depage\XmlDb\Exceptions\XmlDbException;
use Depage\XmlDb\Helpers\NodeBatch;

/**
 * tests for Helpers\NodeBatch, the buffered inserts of a save: the batch
 * writes the nodes with as few statements as possible and has to hand back
 * the id of every node it wrote — the preferred ones as they are and the
 * ones that got an id from the auto increment read back over the unique
 * sibling index — because the caller needs them as id_parent of the next
 * tree level and as db:id attribute of the saved dom.
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
class NodeBatchTest extends XmlDbTestCase
{
    // {{{ variables
    protected $xmlTree;
    // }}}
    // {{{ setUp
    protected function setUp(): void
    {
        parent::setUp();

        $this->xmlTree = $this->pdo->prefix . '_proj_test_xmltree';
    }
    // }}}

    // {{{ batch
    /**
     * @param $pdo connection to write with, eg. the counting connection of
     *        a test; defaults to the connection of the test
     * @param $size (int) number of nodes a single statement writes
     * @param $doc_id (int) document the batch writes to
     */
    protected function batch($pdo = null, $size = 10, $doc_id = 3)
    {
        return new NodeBatch($pdo ?? $this->pdo, $this->xmlTree, $doc_id, $size);
    }
    // }}}
    // {{{ freeId
    /**
     * drops the row of a node (and with it its children), so that its id
     * becomes a free preferred id of a batch; the ids of the dataset lay
     * far below the auto increment of the table, so they stay free for the
     * rest of the test
     */
    protected function freeId($id)
    {
        $query = $this->pdo->prepare("DELETE FROM {$this->xmlTree} WHERE id = :id");
        $query->execute(['id' => $id]);

        return $id;
    }
    // }}}
    // {{{ getRowByPosition
    /**
     * row of a child of document 3, selected by parent node and position
     */
    protected function getRowByPosition($parent_id, $pos)
    {
        $query = $this->pdo->prepare(
            "SELECT id, id_doc, id_parent, pos, value FROM {$this->xmlTree}
            WHERE id_doc = 3 AND id_parent = :parent_id AND pos = :pos"
        );
        $query->execute([
            'parent_id' => $parent_id,
            'pos' => $pos,
        ]);

        return $query->fetch(\PDO::FETCH_ASSOC);
    }
    // }}}

    // {{{ testWriteReturnsPreferredIds
    /**
     * a node that carries a free database id is written to the row of that
     * id and the batch reports exactly that id back
     */
    public function testWriteReturnsPreferredIds()
    {
        $batch = $this->batch();

        $this->assertTrue($batch->isEmpty());

        $batch->add(0, $this->freeId(8), 5, 900001, 'pg:page', 'name="written" ', 'ELEMENT_NODE');

        $this->assertFalse($batch->isEmpty());

        $ids = $batch->write();

        $this->assertSame([0 => 8], $ids);
        $this->assertTrue($batch->isEmpty(), 'the batch has to be empty after writing');

        $row = $this->getRowByPosition(5, 900001);
        $this->assertNotFalse($row);
        $this->assertSame(8, (int) $row['id']);
        $this->assertSame('name="written" ', $row['value']);
    }
    // }}}
    // {{{ testWriteKeepsRowOfUsedId
    /**
     * an id that is already taken by a node of another document is not
     * taken over, the node resolves to the row that holds the id
     */
    public function testWriteKeepsRowOfUsedId()
    {
        $before = $this->pdo->query(
            "SELECT id, id_parent, pos, value FROM {$this->xmlTree} WHERE id = 8"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($before);

        $batch = $this->batch();
        $batch->add(0, 8, 5, 900002, 'pg:page', 'name="thief" ', 'ELEMENT_NODE');

        $this->assertSame([0 => 8], $batch->write());

        $this->assertSame($before, $this->pdo->query(
            "SELECT id, id_parent, pos, value FROM {$this->xmlTree} WHERE id = 8"
        )->fetch(\PDO::FETCH_ASSOC));
        $this->assertFalse($this->getRowByPosition(5, 900002), 'no node may be written below the taken id');
    }
    // }}}
    // {{{ testWriteReadsBackAutoIncrementIds
    /**
     * the ids of the nodes without a preferred id come from the auto
     * increment and have to be found again, keyed by the key the node was
     * added under; the nodes of a batch can hang in different parent nodes
     * and the children of an auto incremented node have to be able to
     * reference its id
     */
    public function testWriteReadsBackAutoIncrementIds()
    {
        $batch = $this->batch();

        $batch->add(0, null, 5, 900011, 'pg:page', 'name="auto1" ', 'ELEMENT_NODE');
        $batch->add(1, null, 5, 900012, 'pg:page', 'name="auto2" ', 'ELEMENT_NODE');
        $batch->add(2, null, 6, 900013, 'pg:page', 'name="auto3" ', 'ELEMENT_NODE');
        $batch->add(3, $this->freeId(7), 5, 900014, 'pg:page', 'name="preferred" ', 'ELEMENT_NODE');

        $ids = $batch->write();

        $this->assertSame([0, 1, 2, 3], array_keys($ids), 'the result is keyed by the keys of add()');
        $this->assertSame(7, $ids[3]);
        $this->assertCount(4, array_unique($ids), 'every node needs an id of its own');

        // the ids have to point to the rows the nodes were written to
        $written = [
            0 => [5, 900011],
            1 => [5, 900012],
            2 => [6, 900013],
            3 => [5, 900014],
        ];
        foreach ($ids as $key => $id) {
            [$parent_id, $pos] = $written[$key];
            $row = $this->getRowByPosition($parent_id, $pos);
            $this->assertNotFalse($row, "node {$key} was not written");
            $this->assertSame($id, (int) $row['id']);
        }

        // and the children of an auto incremented node can use its id
        $batch->add(4, null, $ids[0], 900015, 'pg:page', 'name="below auto1" ', 'ELEMENT_NODE');
        $childIds = $batch->write();

        $this->assertGreaterThan(0, $childIds[4]);
        $child = $this->pdo->query(
            "SELECT id_parent, value FROM {$this->xmlTree} WHERE id = " . (int) $childIds[4]
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($ids[0], (int) $child['id_parent']);
        $this->assertSame('name="below auto1" ', $child['value']);
    }
    // }}}
    // {{{ testWriteReadsBackEqualPositionsOfDifferentParents
    /**
     * the siblings of a batch are found again by their position in the
     * child list of their own parent; nodes of different parents at the
     * same position (the tree levels a save writes are full of them) must
     * not be mixed up
     */
    public function testWriteReadsBackEqualPositionsOfDifferentParents()
    {
        $batch = $this->batch();

        $batch->add(0, null, 5, 900016, 'pg:page', 'name="child of 5" ', 'ELEMENT_NODE');
        $batch->add(1, null, 6, 900016, 'pg:page', 'name="child of 6" ', 'ELEMENT_NODE');

        $ids = $batch->write();

        $this->assertCount(2, array_unique($ids), 'both nodes need an id of their own');
        $this->assertSame($ids[0], (int) $this->getRowByPosition(5, 900016)['id']);
        $this->assertSame($ids[1], (int) $this->getRowByPosition(6, 900016)['id']);
    }
    // }}}
    // {{{ testWriteIsScopedToTheDocument
    /**
     * the ids of a batch are read back in the document the nodes were
     * written to; a node of another document below the same parent and at
     * the same position must not be mistaken for one of them. the node of
     * the other document is the first row the select finds, so a read back
     * that does not ask for the document answers with it
     */
    public function testWriteIsScopedToTheDocument()
    {
        // a node of document 3, which lay below the parent and at the
        // position the batch of document 4 writes to
        $sibling = $this->pdo->query(
            "SELECT id, id_parent, pos, value FROM {$this->xmlTree}
            WHERE id_doc = 3 AND id_parent IS NOT NULL ORDER BY id ASC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($sibling);

        $batch = $this->batch(null, 10, 4);
        $batch->add(
            0,
            null,
            (int) $sibling['id_parent'],
            (int) $sibling['pos'],
            'pg:page',
            'name="document4" ',
            'ELEMENT_NODE'
        );

        $ids = $batch->write();

        $this->assertNotEquals((int) $sibling['id'], $ids[0], 'the node of document 3 was taken for the written one');
        $this->assertSame(4, (int) $this->pdo->query(
            "SELECT id_doc FROM {$this->xmlTree} WHERE id = " . (int) $ids[0]
        )->fetchColumn());

        // the node of document 3 is still on its own row
        $this->assertSame(
            array_values($sibling),
            array_values($this->pdo->query(
                "SELECT id, id_parent, pos, value FROM {$this->xmlTree} WHERE id = " . (int) $sibling['id']
            )->fetch(\PDO::FETCH_ASSOC))
        );
    }
    // }}}
    // {{{ testWritesWithAsFewStatementsAsPossible
    /**
     * a batch is written with a single insert; only the nodes that got
     * their id from the auto increment have to be read back
     */
    public function testWritesWithAsFewStatementsAsPossible()
    {
        $pdo = new CountingPdo($this->pdo);
        $batch = $this->batch($pdo);

        $batch->add(0, $this->freeId(7), 5, 900031, 'pg:page', 'name="preferred1" ', 'ELEMENT_NODE');
        $batch->add(1, $this->freeId(8), 5, 900032, 'pg:page', 'name="preferred2" ', 'ELEMENT_NODE');
        $batch->write();

        $this->assertSame(1, $pdo->statements, 'nodes that carry their id only need the insert');

        $pdo->reset();

        $batch->add(0, null, 5, 900033, 'pg:page', 'name="auto" ', 'ELEMENT_NODE');
        $batch->add(1, $this->freeId(30), 5, 900034, 'pg:page', 'name="preferred" ', 'ELEMENT_NODE');
        $batch->write();

        $this->assertSame(2, $pdo->statements, 'a batch with auto incremented nodes needs the read back as well');
    }
    // }}}
    // {{{ testIsFullFollowsBatchSize
    /**
     * the batch reports when a single statement is full, that is where the
     * caller writes it out
     */
    public function testIsFullFollowsBatchSize()
    {
        $batch = $this->batch(null, 3);

        foreach ([0, 1, 2] as $i) {
            $this->assertFalse($batch->isFull(), "a batch with {$i} of 3 nodes is not full");
            $batch->add($i, null, 5, 900021 + $i, 'pg:page', '', 'ELEMENT_NODE');
        }

        $this->assertTrue($batch->isFull());
        $this->assertCount(3, $batch->write());
        $this->assertFalse($batch->isFull());
    }
    // }}}
    // {{{ testRejectsTableNamesThatAreNotIdentifiers
    /**
     * the table name is written into the statements of the batch, so it is
     * checked before the batch writes anything
     */
    public function testRejectsTableNamesThatAreNotIdentifiers()
    {
        $this->expectException(XmlDbException::class);

        new NodeBatch(new CountingPdo($this->pdo), $this->xmlTree . ' WHERE id_doc = 1', 3);
    }
    // }}}
    // {{{ testWritingAnEmptyBatchDoesNothing
    public function testWritingAnEmptyBatchDoesNothing()
    {
        $pdo = new CountingPdo($this->pdo);
        $batch = $this->batch($pdo);

        $this->assertSame([], $batch->write());
        $this->assertSame([], $batch->write(), 'writing twice must stay harmless');
        $this->assertSame(0, $pdo->statements, 'an empty batch must not write anything');
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
