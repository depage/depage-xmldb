<?php

/**
 * @file    Helpers/Positions.php
 *
 * cms xmlDb module
 *
 * copyright (c) 2002-2026 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\XmlDb\Helpers;

use Depage\XmlDb\Exceptions\XmlDbException;

/**
 * @internal management of the sparse sibling positions of a document;
 * not part of the public api of this library
 *
 * The children of a node are ordered by their pos value, which has gaps
 * (POS_GAP) between the siblings; inserting a node between two neighbours
 * takes the midpoint of their positions, so no other rows have to be
 * renumbered. A child list is rebalanced once the space between two
 * neighbours drops below POS_MIN_GAP.
 *
 * The targetIndex the callers hand in is the 0-based index a node gets in
 * the child list of its new parent, -1 appends it behind the last child.
 * normalizeIndex() and positionForIndex() are the only ones resolving it
 * (the index counts all child rows, not only the element nodes), callers
 * must not clamp target indexes on their own.
 *
 * The methods work inside whatever transaction the caller opened.
 */
class Positions
{
    /**
     * distance between the sparse positions of sibling nodes
     */
    public const POS_GAP = 65536;

    /**
     * the child list is rebalanced once the space between two neighbours
     * drops below this gap
     */
    public const POS_MIN_GAP = 16;

    /**
     * adding this offset to all positions of a child list (in descending
     * order, before assigning the final positions) moves them out of the
     * way of the unique sibling index; it is big enough to keep the
     * scratch positions clear of the final positions that are written by
     * rebalance()
     */
    public const SCRATCH_OFFSET = 1125899906842624;

    // {{{ variables
    protected $pdo;
    // }}}

    // {{{ __construct
    /**
     * @param $pdo database connection to read and write the positions with
     * @param $table (string) name of the xmltree table
     * @param $docId (int) document the nodes belong to
     * @param $batchSize (int) number of rows rebalance() writes with one
     *        statement
     */
    public function __construct(
        $pdo,
        protected string $table,
        protected $docId,
        protected int $batchSize = CaseUpdate::DEFAULT_SIZE
    ) {
        $this->pdo = $pdo;
    }
    // }}}

    // {{{ siblings
    /**
     * gets id and pos of all children of a parent node in document order
     *
     * @param $parentId (int) db-id of parent node
     *
     * @return (array) list of objects with id and pos
     */
    public function siblings($parentId): array
    {
        $query = $this->pdo->prepare(
            "SELECT xml.id, xml.pos
            FROM {$this->table} AS xml
            WHERE xml.id_parent = :parent_id AND xml.id_doc = :doc_id
            ORDER BY xml.pos ASC, xml.id ASC",
        );
        $query->execute([
            'parent_id' => $parentId,
            'doc_id' => $this->docId,
        ]);

        return $query->fetchAll(\PDO::FETCH_OBJ);
    }
    // }}}

    // {{{ siblingsWithout
    /**
     * gets the children of a parent node in document order without one of
     * them; a node that is moved inside its own parent is not part of the
     * child list the targetIndex counts
     *
     * @param $parentId (int) db-id of parent node
     * @param $excludeId (int) db-id of the child to leave out
     *
     * @return (array) list of objects with id and pos
     */
    private function siblingsWithout($parentId, $excludeId = null): array
    {
        $siblings = $this->siblings($parentId);

        if (!is_null($excludeId)) {
            foreach ($siblings as $i => $sibling) {
                if ($sibling->id == $excludeId) {
                    unset($siblings[$i]);
                    $siblings = array_values($siblings);
                    break;
                }
            }
        }

        return $siblings;
    }
    // }}}

    // {{{ clampIndex
    /**
     * maps the targetIndex a caller gave to a valid insertion index of a
     * child list; the index counts all child rows (element nodes as well
     * as text nodes and the other node types)
     *
     * null and negative indexes (like -1, "append") are the index behind
     * the last child, indexes beyond the end of the child list are
     * clamped to it.
     *
     * @param $targetIndex (int|null) index a caller wants a node at
     * @param $numSiblings (int) number of children the index counts
     *
     * @return (int) index a node is inserted at
     */
    private function clampIndex($targetIndex, int $numSiblings): int
    {
        if (!is_numeric($targetIndex) || (int) $targetIndex < 0) {
            return $numSiblings;
        }

        return min((int) $targetIndex, $numSiblings);
    }
    // }}}

    // {{{ normalizeIndex
    /**
     * gets the index a node is inserted at in the child list of a parent
     * node for the targetIndex a caller asked for; this is the one place
     * where the target indexes of the public api (-1 appends, the other
     * values are the 0-based index the node gets) are resolved
     *
     * @param $parentId (int) db-id of parent node
     * @param $targetIndex (int|null) index a caller wants the node at
     * @param $excludeId (int) db-id of a child that does not count into
     *        the index (a node that is moved inside its own parent)
     *
     * @return (int) index the node is inserted at
     */
    public function normalizeIndex($parentId, $targetIndex, $excludeId = null)
    {
        return $this->clampIndex(
            $targetIndex,
            count($this->siblingsWithout($parentId, $excludeId))
        );
    }
    // }}}

    // {{{ indexById
    /**
     * gets the index of a node in the child list of its parent node
     * (number of siblings before it), the "dense" position, independent
     * of the sparse pos values
     *
     * @param $id (int) node db-id
     *
     * @return (int) index in parents childlist, false if the node doesn't
     *         exist
     */
    public function indexById($id)
    {
        $query = $this->pdo->prepare(
            "SELECT xml.id_parent, xml.pos
            FROM {$this->table} AS xml
            WHERE xml.id = :id AND xml.id_doc = :doc_id
            LIMIT 1",
        );
        $query->execute([
            'id' => $id,
            'doc_id' => $this->docId,
        ]);

        if (!($node = $query->fetchObject())) {
            return false;
        }

        $query = $this->pdo->prepare(
            "SELECT COUNT(*) AS sibling_index
            FROM {$this->table} AS xml
            WHERE xml.id_parent = :parent_id AND xml.id_doc = :doc_id AND xml.pos < :pos",
        );
        $query->execute([
            'parent_id' => $node->id_parent,
            'pos' => $node->pos,
            'doc_id' => $this->docId,
        ]);
        $result = $query->fetchObject();

        return (int) $result->sibling_index;
    }
    // }}}

    // {{{ appendIndex
    /**
     * gets the index a child would get when appended to the child list,
     * i.e. the number of current children
     *
     * @param $parentId (int) db-id of parent node
     */
    public function appendIndex($parentId)
    {
        $query = $this->pdo->prepare(
            "SELECT COUNT(*) AS pos
            FROM {$this->table} AS xml
            WHERE xml.id_parent = :target_id AND id_doc = :doc_id",
        );
        $query->execute([
            'target_id' => $parentId,
            'doc_id' => $this->docId,
        ]);

        if ($object = $query->fetchObject()) {
            $result = $object->pos;
        } else {
            $result = null;
        }

        return $result;
    }
    // }}}

    // {{{ positionForIndex
    /**
     * calculates the sparse pos value for a node to be inserted at the
     * given index of a parent node's child list; the result lies between
     * the positions of the neighbouring siblings, so no other rows have
     * to be renumbered. if the space between the neighbours is exhausted,
     * the child list is rebalanced and the position recalculated.
     *
     * @param $parentId (int) db-id of parent node
     * @param $index (int) insertion index in the child list, a negative
     *        position (like -1) appends the node
     * @param $excludeId (int) db-id of a child that does not count into
     *        the index (a node that is moved inside its own parent)
     *
     * @return (int) pos value for the node to insert
     */
    public function positionForIndex($parentId, $index, $excludeId = null)
    {
        $siblings = $this->siblingsWithout($parentId, $excludeId);

        $numSiblings = count($siblings);
        $index = $this->clampIndex($index, $numSiblings);

        if ($index >= $numSiblings) {
            // appending does not need room between two neighbours
            $lastPos = $numSiblings > 0 ? (int) $siblings[$numSiblings - 1]->pos : 0;

            return $lastPos + self::POS_GAP;
        }

        $prevPos = $index > 0 ? (int) $siblings[$index - 1]->pos : 0;
        $nextPos = (int) $siblings[$index]->pos;

        if ($nextPos - $prevPos - 1 < self::POS_MIN_GAP) {
            $this->rebalance($parentId);

            return $this->positionForIndex($parentId, $index, $excludeId);
        }

        return intdiv($prevPos + $nextPos, 2);
    }
    // }}}

    // {{{ rebalance
    /**
     * renumbers the children of a parent node to have an even distance of
     * self::POS_GAP between their positions; called when the sparse
     * positions of a child list are exhausted
     *
     * @param $parentId (int) db-id of parent node
     * @param $order (array) optional list of child ids giving the new
     *        order, null to keep the order stored in the database
     */
    public function rebalance($parentId, $order = null): void
    {
        $siblings = $this->siblings($parentId);

        if (count($siblings) == 0) {
            return;
        }

        $current = [];
        foreach ($siblings as $sibling) {
            $current[] = (int) $sibling->id;
        }

        if (is_null($order)) {
            // keep the order that is stored in the database
            $ids = $current;
        } else {
            // reorder into the given child order (used by the patch
            // applier)
            $ids = array_map('intval', $order);

            if (count($ids) != count($current) || array_diff($ids, $current) || array_diff($current, $ids)) {
                throw new XmlDbException("Can not reorder the children of node {$parentId}, the node list does not match.");
            }
        }

        // the unique sibling index is checked row by row, so the positions
        // have to be moved out of the way first: adding a big offset in
        // descending order cannot collide with a sibling that has not
        // been updated yet, and the offset is big enough to keep the
        // scratch positions clear of the final positions that are
        // assigned in the update below
        $this->pdo->exec(
            "UPDATE {$this->table}
            SET pos = pos + " . self::SCRATCH_OFFSET . "
            WHERE id_doc = " . (int) $this->docId . " AND id_parent = " . (int) $parentId . "
            ORDER BY pos DESC"
        );

        // every sibling gets a position of its own, so writing the final
        // positions in batches cannot collide with the unique sibling
        // index either
        $positions = new CaseUpdate($this->pdo, $this->table, $this->batchSize);
        foreach ($ids as $index => $id) {
            $positions->add($id, ($index + 1) * self::POS_GAP);
        }
        $positions->write('pos', ['id_doc' => $this->docId]);
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
