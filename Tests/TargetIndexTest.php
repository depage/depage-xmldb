<?php

namespace Depage\XmlDb\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * tests for the target indexes of the methods that place a node in the
 * child list of another node (addNode, addNodeByName, saveNodeIn, moveNode,
 * copyNode, duplicateNode, replaceNode)
 *
 * a targetIndex is the 0-based index the node gets in the child list of
 * its new parent, -1 appends it and an index behind the last child is
 * clamped to the end.
 *
 * document 5 of the dataset is used for this, node 17 of it has seven
 * element children (ids 18, 19, 22, 23, 24, 25, 26) and no text nodes
 * between them, so the index a targetIndex refers to is the plain number
 * of the child in the child list.
 */
class TargetIndexTest extends XmlDbTestCase
{
    // {{{ variables
    protected $xmlDb;
    protected $doc;
    protected $cache;
    protected $dbPrefix;
    protected $xmlTree;

    /**
     * node 17 of document 5, the node the tests add to
     */
    protected const PARENT_ID = 17;

    /**
     * the children of that node in document order
     */
    protected const CHILDREN = [18, 19, 22, 23, 24, 25, 26];
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

        $this->doc = new DocumentTestClass($this->xmlDb, 5);
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

    // {{{ getChildIds
    /**
     * the db-ids of the children of a node in document order
     */
    protected function getChildIds($parentId = self::PARENT_ID): array
    {
        $query = $this->pdo->prepare(
            "SELECT id FROM {$this->xmlTree}
            WHERE id_doc = 5 AND id_parent = ?
            ORDER BY pos ASC, id ASC"
        );
        $query->execute([$parentId]);

        return array_map('intval', $query->fetchAll(\PDO::FETCH_COLUMN));
    }
    // }}}

    // {{{ getNamesOfChildren
    /**
     * the name attribute of every child of a node in document order
     */
    protected function getNamesOfChildren($parentId = self::PARENT_ID): array
    {
        $query = $this->pdo->prepare(
            "SELECT value FROM {$this->xmlTree}
            WHERE id_doc = 5 AND id_parent = ?
            ORDER BY pos ASC, id ASC"
        );
        $query->execute([$parentId]);

        $names = [];
        foreach ($query->fetchAll(\PDO::FETCH_COLUMN) as $value) {
            preg_match('/name="([^"]*)"/', (string) $value, $matches);
            $names[] = $matches[1];
        }

        return $names;
    }
    // }}}

    // {{{ markId
    /**
     * the child list of a node with one id replaced by a label, to make the
     * ids a test handed out readable in the expectation
     */
    protected function markId(array $children, $id, string $label): array
    {
        return array_map(
            fn($childId) => (int) $childId === (int) $id ? $label : (int) $childId,
            $children
        );
    }
    // }}}

    // {{{ getPosValue
    /**
     * the value of the pos column a node is stored with; the library only
     * exposes the index of a node in its child list (getIndexById), which
     * is what a targetIndex refers to, so the sparse value is read here
     */
    protected function getPosValue($id): int
    {
        $query = $this->pdo->prepare(
            "SELECT pos FROM {$this->xmlTree} WHERE id_doc = 5 AND id = ?"
        );
        $query->execute([$id]);

        return (int) $query->fetchColumn();
    }
    // }}}

    // {{{ addPage
    /**
     * adds an element node below node 17 and returns its db-id
     */
    protected function addPage(string $name, $targetIndex)
    {
        $dom = $this->generateDomDocument("<page name=\"$name\"/>");

        return $this->doc->addNode($dom->documentElement, self::PARENT_ID, $targetIndex);
    }
    // }}}

    // {{{ setMultiNodeDoctypeHandler
    /**
     * sets up a doctype handler that creates three nodes for the name
     * "threePages"
     */
    protected function setMultiNodeDoctypeHandler(): void
    {
        $dth = new DoctypeHandlerTestClass($this->xmlDb, $this->doc);

        $nodeInfo = new \stdClass();
        $nodeInfo->xmlTemplateData = '<page name="first"/><page name="second"/><page name="third"/>';
        $dth->availableNodes['threePages'] = $nodeInfo;

        $this->doc->setDoctypeHandler($dth);
    }
    // }}}

    // {{{ provideTargetIndex
    /**
     * target indexes to place a new node in the child list of node 17 with
     * the index the node has to end up at, run on the child list of the
     * dataset and on a rebalanced (sparse) one
     */
    public static function provideTargetIndex()
    {
        $positions = [
            'append (-1)' => [-1, 7],
            'front (0)' => [0, 0],
            'second (1)' => [1, 1],
            'middle (4)' => [4, 4],
            'last child (6)' => [6, 6],
            'behind the last child (7)' => [7, 7],
            'beyond the end (99)' => [99, 7],
            'append as string ("-1")' => ['-1', 7],
            'index as string ("4")' => ['4', 4],
        ];

        $cases = [];
        foreach ([false, true] as $rebalanced) {
            foreach ($positions as $name => [$targetIndex, $expected]) {
                $cases[$name . ($rebalanced ? ', sparse list' : ', dense list')] = [
                    $targetIndex,
                    $expected,
                    $rebalanced,
                ];
            }
        }

        return $cases;
    }
    // }}}

    // {{{ testAddNodeInsertsAtTheTargetIndex
    /**
     * adding a node puts it at the index the targetIndex names and
     * leaves the order of the other children alone
     */
    #[DataProvider('provideTargetIndex')]
    public function testAddNodeInsertsAtTheTargetIndex($targetIndex, $expectedIndex, $rebalanced)
    {
        if ($rebalanced) {
            // the dataset stores its positions dense, as an old version of
            // the library wrote them
            $this->doc->rebalancePositions(self::PARENT_ID);
        }

        $id = $this->addPage('new', $targetIndex);

        $this->assertIsNumeric($id, 'the node was not added');

        $children = $this->getChildIds();

        $this->assertCount(8, $children);
        $this->assertSame($expectedIndex, $this->doc->getIndexById($id));
        $this->assertSame($expectedIndex, array_search((int) $id, $children));

        // the siblings keep their relative order
        $this->assertSame(
            self::CHILDREN,
            array_values(array_diff($children, [(int) $id]))
        );
    }
    // }}}

    // {{{ testAddNodeAppendsToAnEmptyChildList
    public function testAddNodeAppendsToAnEmptyChildList()
    {
        // node 25 (P5.1.6) has no children
        $dom = $this->generateDomDocument('<page name="first"/>');

        $id = $this->doc->addNode($dom->documentElement, 25, -1);

        $this->assertSame(0, $this->doc->getIndexById($id));
        $this->assertSame([(int) $id], $this->getChildIds(25));
    }
    // }}}

    // {{{ testAddNodeByNameKeepsTheOrderOfTheNewNodes
    /**
     * a doctype handler may hand out more than one node; they have to keep
     * the order the handler returns them in, whether they are inserted in
     * the middle of the child list or appended to it
     */
    #[DataProvider('provideTargetIndex')]
    public function testAddNodeByNameKeepsTheOrderOfTheNewNodes($targetIndex, $expectedIndex, $rebalanced)
    {
        $this->setMultiNodeDoctypeHandler();

        if ($rebalanced) {
            $this->doc->rebalancePositions(self::PARENT_ID);
        }

        $success = $this->doc->addNodeByName('threePages', self::PARENT_ID, $targetIndex);

        $this->assertIsNumeric($success, 'the nodes were not added');

        $names = $this->getNamesOfChildren();

        $this->assertCount(10, $names);
        $this->assertSame(
            ['first', 'second', 'third'],
            array_slice($names, $expectedIndex, 3),
            "the three nodes are not behind each other at index $expectedIndex: " . implode(',', $names)
        );
    }
    // }}}

    // {{{ testAddNodeByNameAddsNothingWhenANodeIsNotAllowed
    public function testAddNodeByNameAddsNothingWhenANodeIsNotAllowed()
    {
        $this->setMultiNodeDoctypeHandler();
        $this->doc->getDoctypeHandler()->isAllowedAdd = false;

        $this->assertFalse(
            $this->doc->addNodeByName('threePages', self::PARENT_ID, 4)
        );
        $this->assertSame(self::CHILDREN, $this->getChildIds());
    }
    // }}}

    // {{{ testSaveNodeInInsertsAtTheTargetIndex
    /**
     * saveNodeIn places a subtree with children at the index the target
     * position names
     */
    public function testSaveNodeInInsertsAtTheTargetIndex()
    {
        $this->doc->rebalancePositions(self::PARENT_ID);

        $dom = $this->generateDomDocument('<page name="sub"><page name="child"/></page>');

        $id = $this->doc->saveNodeIn($dom->documentElement, self::PARENT_ID, 4, true);

        $this->assertSame(4, $this->doc->getIndexById($id));
        $this->assertSame(
            [18, 19, 22, 23, '{sub}', 24, 25, 26],
            $this->markId($this->getChildIds(), $id, '{sub}')
        );
    }
    // }}}

    // {{{ testSaveNodeInInsertsATextNodeAtTheTargetIndex
    /**
     * a node that is not an element is stored with a sparse position too:
     * storing it with the plain index of the target would sort it
     * in front of the siblings that were placed with a gap between them
     */
    public function testSaveNodeInInsertsATextNodeAtTheTargetIndex()
    {
        $this->doc->rebalancePositions(self::PARENT_ID);

        $dom = $this->generateDomDocument('<root>a text node</root>');

        $id = $this->doc->saveNodeIn($dom->documentElement->firstChild, self::PARENT_ID, 4, true);

        $this->assertSame(4, $this->doc->getIndexById($id));
    }
    // }}}

    // {{{ provideMove
    /**
     * the node to move (by its index in the child list of node 17) and the
     * target index to move it to, with the index it has to end up at
     */
    public static function provideMove()
    {
        $cases = [];

        foreach ([0, 1, 3, 5, 6] as $from) {
            foreach ([-1, 0, 1, 2, 4, 5, 6, 7, 99] as $to) {
                // of the seven children six remain behind once the moved
                // node is out of the list, that is the last index there is
                $expected = ($to < 0) ? 6 : min($to, 6);

                $cases["from $from to $to"] = [$from, $to, $expected];
            }
        }

        return $cases;
    }
    // }}}

    // {{{ testMoveNodeInsertsAtTheTargetIndex
    /**
     * moving a node leaves it at the index the targetIndex names,
     * whether it moves towards the front or the back of its own child list
     * or into another one
     */
    #[DataProvider('provideMove')]
    public function testMoveNodeInsertsAtTheTargetIndex($from, $targetIndex, $expectedIndex)
    {
        $nodeId = self::CHILDREN[$from];

        $this->assertSame($from, $this->doc->getIndexById($nodeId));

        $this->doc->moveNode($nodeId, self::PARENT_ID, $targetIndex);

        $this->assertSame($expectedIndex, $this->doc->getIndexById($nodeId));
        $this->assertCount(7, $this->getChildIds());
    }
    // }}}

    // {{{ testMoveNodeAppendsToAnotherParent
    /**
     * -1 appends the node to the child list of another node too
     */
    public function testMoveNodeAppendsToAnotherParent()
    {
        // the root node of document 5 has one child
        $this->assertSame([16], $this->getChildIds(15));

        $this->doc->moveNode(18, 15, -1);

        $this->assertSame(1, $this->doc->getIndexById(18));
        $this->assertSame([16, 18], $this->getChildIds(15));
    }
    // }}}

    // {{{ testMoveNodeToItsOwnIndexDoesNotMoveTheNode
    public function testMoveNodeToItsOwnIndexDoesNotMoveTheNode()
    {
        $children = $this->getChildIds();
        $positions = [];
        foreach ($children as $id) {
            $positions[$id] = $this->getPosValue($id);
        }

        $this->assertFalse($this->doc->moveNode(22, self::PARENT_ID, 2));

        $this->assertSame($children, $this->getChildIds());
        foreach ($children as $id) {
            $this->assertSame(
                $positions[$id],
                $this->getPosValue($id),
                "the position of node {$id} changed"
            );
        }
    }
    // }}}

    // {{{ provideMoveAroundSibling
    /**
     * moveNodeBefore and moveNodeAfter with the child list they have to
     * result in
     */
    public static function provideMoveAroundSibling()
    {
        return [
            'the first node before a node behind it' => [
                'moveNodeBefore', 18, 24, [19, 22, 23, 18, 24, 25, 26],
            ],
            'the last node before a node in front of it' => [
                'moveNodeBefore', 26, 19, [18, 26, 19, 22, 23, 24, 25],
            ],
            'a node before the node that is behind it anyway' => [
                'moveNodeBefore', 22, 23, [18, 19, 22, 23, 24, 25, 26],
            ],
            'the first node after a node behind it' => [
                'moveNodeAfter', 18, 24, [19, 22, 23, 24, 18, 25, 26],
            ],
            'the last node after a node in front of it' => [
                'moveNodeAfter', 26, 19, [18, 19, 26, 22, 23, 24, 25],
            ],
            'a node after the node before it' => [
                'moveNodeAfter', 22, 19, [18, 19, 22, 23, 24, 25, 26],
            ],
            'a node after the last node' => [
                'moveNodeAfter', 18, 26, [19, 22, 23, 24, 25, 26, 18],
            ],
        ];
    }
    // }}}

    // {{{ testMoveNodeAroundSibling
    /**
     * moving in front of and behind a sibling does not depend on the
     * direction the node moves in
     */
    #[DataProvider('provideMoveAroundSibling')]
    public function testMoveNodeAroundSibling($method, $nodeId, $siblingId, $expected)
    {
        $this->doc->$method($nodeId, $siblingId);

        $this->assertSame($expected, $this->getChildIds());
    }
    // }}}

    // {{{ provideCopy
    /**
     * copyNode with the child list it has to result in; the node that is
     * copied stays where it is, so the index of the copy is not shifted by
     * it
     */
    public static function provideCopy()
    {
        return [
            'copy to the front' => [0, ['{copy}', 18, 19, 22, 23, 24, 25, 26]],
            'copy into the middle' => [3, [18, 19, 22, '{copy}', 23, 24, 25, 26]],
            'copy behind the last child' => [7, [18, 19, 22, 23, 24, 25, 26, '{copy}']],
            'copy with -1' => [-1, [18, 19, 22, 23, 24, 25, 26, '{copy}']],
            'copy beyond the end' => [99, [18, 19, 22, 23, 24, 25, 26, '{copy}']],
        ];
    }
    // }}}

    // {{{ testCopyNodeInsertsAtTheTargetIndex
    /**
     */
    #[DataProvider('provideCopy')]
    public function testCopyNodeInsertsAtTheTargetIndex($targetIndex, $expected)
    {
        $copyId = $this->doc->copyNode(18, self::PARENT_ID, $targetIndex);

        $this->assertIsNumeric($copyId, 'the node was not copied');

        $this->assertSame(
            $expected,
            $this->markId($this->getChildIds(), $copyId, '{copy}')
        );
    }
    // }}}

    // {{{ testDuplicateNodeGoesBehindTheOriginal
    public function testDuplicateNodeGoesBehindTheOriginal()
    {
        $copyId = $this->doc->duplicateNode(23);

        $this->assertIsNumeric($copyId, 'the node was not duplicated');
        $this->assertSame(4, $this->doc->getIndexById($copyId));
        $this->assertSame(
            [18, 19, 22, 23, '{copy}', 24, 25, 26],
            $this->markId($this->getChildIds(), $copyId, '{copy}')
        );
    }
    // }}}

    // {{{ testReplaceNodeKeepsThePosition
    public function testReplaceNodeKeepsThePosition()
    {
        $dom = $this->generateDomDocument('<page name="replacement"/>');

        $changedIds = $this->doc->replaceNode($dom->documentElement, 23);

        $this->assertNotContains(23, $this->getChildIds());
        $this->assertSame(
            [18, 19, 22, '{new}', 24, 25, 26],
            $this->markId($this->getChildIds(), $changedIds[0], '{new}')
        );
        $this->assertSame(3, $this->doc->getIndexById($changedIds[0]));
    }
    // }}}

    // {{{ testAddNodeKeepsTheSiblingPositionsUnique
    /**
     * inserting into the same spot again and again exhausts the space between
     * two neighbours; the child list is rebalanced, but the node still lands
     * at the index it was added at and no two siblings share a position
     */
    public function testAddNodeKeepsTheSiblingPositionsUnique()
    {
        for ($i = 0; $i < 30; $i++) {
            $id = $this->addPage("new$i", 4);

            $this->assertIsNumeric($id, "insert $i was not added");
            $this->assertSame(4, $this->doc->getIndexById($id), "insert $i did not land at index 4");
        }

        $positions = $this->pdo->query(
            "SELECT pos, COUNT(*) AS num FROM {$this->xmlTree}
            WHERE id_doc = 5 AND id_parent = " . self::PARENT_ID . ' GROUP BY pos HAVING num > 1'
        )->fetchAll();

        $this->assertSame([], $positions, 'two siblings share a position');
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
