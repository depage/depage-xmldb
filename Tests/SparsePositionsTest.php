<?php

namespace Depage\XmlDb\Tests;

/**
 * tests for the sparse (gap based) sibling positions introduced with
 * schema version 2.2.0: inserts and moves must not renumber their
 * siblings anymore, and positions must stay unique.
 */
class SparsePositionsTest extends XmlDbTestCase
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

    // {{{ getChildPositions
    /**
     * all rows of document 3 as id => pos
     */
    protected function getChildPositions()
    {
        $positions = [];

        foreach ($this->pdo->query(
            "SELECT id, pos FROM {$this->xmlTree} WHERE id_doc = 3"
        ) as $row) {
            $positions[(int) $row['id']] = (int) $row['pos'];
        }

        return $positions;
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

    // {{{ testMoveNodeDoesNotRenumberSiblings
    /**
     * moving a node must only touch the moved node; the dense
     * implementation renumbered the whole source and target child list.
     * (rebalancing may still renumber a child list when the gaps between
     * the neighbours of the insertion index are exhausted, so this test
     * moves to an append position, which never needs new room)
     */
    public function testMoveNodeDoesNotRenumberSiblings()
    {
        $before = $this->getChildPositions();

        // append node 8 (second child of node 5) behind the last child of
        // node 6, an index beyond the end of the child list clamps to the
        // end
        $this->doc->moveNode(8, 6, 99);

        $after = $this->getChildPositions();

        $this->assertSame(6, (int) $this->doc->getParentIdById(8));

        foreach ($before as $id => $pos) {
            if ($id == 8) {
                continue;
            }
            $this->assertSame($pos, $after[$id], "position of node {$id} should not change on move");
        }

        // and the moved node sits behind all other children of its new parent
        $last = $this->pdo->query(
            "SELECT id FROM {$this->xmlTree}
            WHERE id_doc = 3 AND id_parent = 6 ORDER BY pos DESC LIMIT 1"
        )->fetch(\PDO::FETCH_COLUMN);
        $this->assertSame(8, (int) $last);
    }
    // }}}
    // {{{ testDeleteNodeDoesNotRenumberSiblings
    public function testDeleteNodeDoesNotRenumberSiblings()
    {
        // node 6 (P3.1) is the first child of node 5, node 8 (P3.2) the
        // second; deleting the first used to shift node 8 down
        $before = $this->getChildPositions();

        $this->doc->deleteNode(6);

        $after = $this->getChildPositions();

        $this->assertFalse(isset($after[6]));

        foreach ($before as $id => $pos) {
            if ($id == 6 || !isset($after[$id])) {
                // deleted node and its subtree
                continue;
            }
            $this->assertSame($pos, $after[$id], "position of node {$id} should not change on delete");
        }
    }
    // }}}
    // {{{ testRepeatedInsertAtSameIndexStaysOrderedAndUnique
    /**
     * inserting always at the same index halves the gap to the next
     * sibling until the child list has to be rebalanced; order has to
     * stay correct and positions unique all the way through
     */
    public function testRepeatedInsertAtSameIndexStaysOrderedAndUnique()
    {
        for ($i = 1; $i <= 40; $i++) {
            $dom = $this->generateDomDocument("<node{$i}/>");
            $this->doc->addNode($dom, 6, 0);
        }

        // every node went in before the previously inserted one
        $names = $this->pdo->query(
            "SELECT name FROM {$this->xmlTree}
            WHERE id_doc = 3 AND id_parent = 6 AND type = 'ELEMENT_NODE' AND name LIKE 'node%'
            ORDER BY pos ASC"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $expected = [];
        for ($i = 40; $i >= 1; $i--) {
            $expected[] = "node{$i}";
        }
        $this->assertSame($expected, $names);

        // no two siblings share a position
        $counts = $this->pdo->query(
            "SELECT COUNT(*), COUNT(DISTINCT pos) FROM {$this->xmlTree}
            WHERE id_doc = 3 AND id_parent = 6"
        )->fetch(\PDO::FETCH_NUM);
        $this->assertSame($counts[0], $counts[1]);
    }
    // }}}
    // {{{ testRepeatedMovesStayOrderedAndUnique
    /**
     * shuttling a node between the ends of two parents (appends never run
     * out of gap space) must leave the positions of all other nodes
     * untouched and unique
     */
    public function testRepeatedMovesStayOrderedAndUnique()
    {
        // node 8 (child of 5) shuttles between the ends of node 5 and
        // node 6
        $posBefore = $this->getChildPositions();

        for ($i = 0; $i < 20; $i++) {
            $this->doc->moveNode(8, $i % 2 == 0 ? 6 : 5, 99);
        }

        $posAfter = $this->getChildPositions();

        foreach ($posBefore as $id => $pos) {
            if ($id != 8) {
                $this->assertSame($pos, $posAfter[$id], "position of node {$id} should not change on move");
            }
        }

        foreach ([5, 6] as $parent) {
            $counts = $this->pdo->query(
                "SELECT COUNT(*), COUNT(DISTINCT pos) FROM {$this->xmlTree}
                WHERE id_doc = 3 AND id_parent = {$parent}"
            )->fetch(\PDO::FETCH_NUM);
            $this->assertSame($counts[0], $counts[1], "positions of children of node {$parent} should be unique");
        }
    }
    // }}}
    // {{{ testUniqueSiblingIndexExists
    /**
     * the database rejects two siblings at the same position
     */
    public function testUniqueSiblingIndexExists()
    {
        $child = $this->pdo->query(
            "SELECT id_doc, id_parent, pos FROM {$this->xmlTree}
            WHERE id_doc = 3 AND id_parent = 6 AND pos IS NOT NULL LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($child);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('Duplicate entry');

        $this->pdo->exec(
            "INSERT INTO {$this->xmlTree} (id, id_doc, id_parent, pos, name, value, type)
            VALUES (999998, {$child['id_doc']}, {$child['id_parent']}, {$child['pos']}, 'probe', '', 'ELEMENT_NODE')"
        );
    }
    // }}}
    // {{{ testIndexByIdMatchesChildOrder
    public function testIndexByIdMatchesChildOrder()
    {
        $children = $this->pdo->query(
            "SELECT id FROM {$this->xmlTree}
            WHERE id_doc = 3 AND id_parent = 5 ORDER BY pos ASC"
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($children as $index => $id) {
            $this->assertSame($index, $this->doc->getIndexById($id));
        }
    }
    // }}}
    // {{{ addDenseSiblings
    /**
     * adds $count empty elements below node 6 with positions right behind
     * each other, so close together that inserting between two of them has
     * to rebalance the child list
     *
     * @returns (array) ids of the added elements in the order of the child
     *          list
     */
    protected function addDenseSiblings($count)
    {
        $max_pos = (int) $this->pdo->query(
            "SELECT MAX(pos) FROM {$this->xmlTree} WHERE id_doc = 3 AND id_parent = 6"
        )->fetchColumn();

        $ids = [];

        $query = $this->pdo->prepare(
            "INSERT INTO {$this->xmlTree} (id_doc, id_parent, pos, name, value, type)
            VALUES (3, 6, :pos, :name, '', 'ELEMENT_NODE')"
        );

        for ($i = 0; $i < $count; $i++) {
            $query->execute([
                'pos' => $max_pos + $i + 1,
                'name' => 'dense' . $i,
            ]);
            $ids[] = (int) $this->pdo->lastInsertId();
        }

        return $ids;
    }
    // }}}
    // {{{ getPositionsOfChildren
    /**
     * the child list of node 6 as [id, pos] pairs, ordered by position
     */
    protected function getPositionsOfChildren()
    {
        return $this->pdo->query(
            "SELECT id, pos FROM {$this->xmlTree}
            WHERE id_doc = 3 AND id_parent = 6 ORDER BY pos ASC"
        )->fetchAll(\PDO::FETCH_NUM);
    }
    // }}}
    // {{{ testRebalanceOfMoreSiblingsThanOneBatch
    /**
     * rebalancing writes the new positions in batches; a child list that
     * is longer than one batch has to end up with an even distance between
     * all of its siblings, not only between the first batch of them
     */
    public function testRebalanceOfMoreSiblingsThanOneBatch()
    {
        $count = DocumentTestClass::SAVE_BATCH_SIZE + 25;
        $this->addDenseSiblings($count);

        $this->doc->rebalancePositions(6);

        $children = $this->getPositionsOfChildren();
        $this->assertCount($count + 2, $children, 'the two nodes of the dataset besides the added ones');

        foreach ($children as $index => [$id, $pos]) {
            $this->assertSame(
                ($index + 1) * DocumentTestClass::POS_GAP,
                (int) $pos,
                "child {$id} of batch " . (int) floor($index / DocumentTestClass::SAVE_BATCH_SIZE) . ' was not renumbered'
            );
        }
    }
    // }}}
    // {{{ testRebalanceKeepsTheChildOrder
    /**
     * rebalancing numbers the child list without reordering it
     */
    public function testRebalanceKeepsTheChildOrder()
    {
        $this->addDenseSiblings(5);

        $before = array_map(fn($child) => (int) $child[0], $this->getPositionsOfChildren());

        $this->doc->rebalancePositions(6);

        $after = array_map(fn($child) => (int) $child[0], $this->getPositionsOfChildren());

        $this->assertSame($before, $after);
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
