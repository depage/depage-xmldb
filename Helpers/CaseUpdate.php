<?php

/**
 * @file    Helpers/CaseUpdate.php
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
 * @internal buffering the row updates of a save; not part of the public api
 * of this library
 *
 * Collects the value a set of rows should get and writes them with as few
 * statements as possible: one
 * "UPDATE table SET column = CASE id WHEN ... END WHERE id IN (...) AND ..."
 * per chunk of rows. That updates a whole document (the subhashes of all
 * its nodes, the positions of a child list) in a handful of statements
 * instead of one per row.
 */
class CaseUpdate
{
    /**
     * number of rows written with a single statement
     */
    public const DEFAULT_SIZE = 200;

    // {{{ variables
    protected $pdo;

    /**
     * values to write, keyed by the id of the row
     */
    protected $values = [];
    // }}}

    // {{{ __construct
    /**
     * @param $pdo database connection to write with
     * @param $table (string) name of the table to update; a table name can
     *        not be bound as a parameter, so it is checked to be a plain
     *        identifier
     * @param $size (int) number of rows written with one statement
     */
    public function __construct($pdo, protected string $table, protected int $size = self::DEFAULT_SIZE)
    {
        $this->pdo = $pdo;

        $this->checkIdentifier($table, 'table');
    }
    // }}}

    // {{{ isEmpty
    public function isEmpty(): bool
    {
        return count($this->values) == 0;
    }
    // }}}
    // {{{ isFull
    /**
     * whether the buffer holds as many rows as a single statement writes
     */
    public function isFull(): bool
    {
        return count($this->values) >= $this->size;
    }
    // }}}

    // {{{ add
    /**
     * collects the value one row should get; adding the same row again
     * overwrites the value collected before
     *
     * @param $id (int) id of the row
     * @param $value (mixed) value to write
     */
    public function add($id, $value): void
    {
        $this->values[(int) $id] = $value;
    }
    // }}}

    // {{{ write
    /**
     * writes the collected values into $column and empties the buffer
     *
     * @param $column (string) column to write to; a column name can not be
     *        a bound parameter, so it is checked to be a plain identifier
     * @param $conditions (array) column => value pairs the rows are limited
     *        to, eg. the document the rows belong to
     */
    public function write(string $column, array $conditions = []): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $column = $this->checkIdentifier($column);

        $where = '';
        $whereParams = [];
        foreach ($conditions as $name => $value) {
            $where .= ' AND ' . $this->checkIdentifier((string) $name) . ' = ?';
            $whereParams[] = $value;
        }

        $chunks = array_chunk($this->values, $this->size, true);
        $this->values = [];

        foreach ($chunks as $chunk) {
            $this->update($column, $where, $chunk, $whereParams);
        }
    }
    // }}}

    // {{{ update
    /**
     * writes one chunk of rows with a single statement
     */
    protected function update(string $column, string $where, array $chunk, array $whereParams): void
    {
        $case = '';
        $ids = [];
        $params = [];

        foreach ($chunk as $id => $value) {
            $case .= ' WHEN ? THEN ?';
            $params[] = $id;
            $params[] = $value;
            $ids[] = $id;
        }

        $query = $this->pdo->prepare(
            "UPDATE {$this->table}
            SET {$column} = CASE id{$case} END
            WHERE id IN (" . implode(", ", array_fill(0, count($ids), '?')) . ")" . $where
        );
        $query->execute(array_merge(
            $params,
            $ids,
            $whereParams
        ));
    }
    // }}}
    // {{{ checkIdentifier
    /**
     * table and column names can not be bound as parameters, so they are
     * checked to be plain identifiers before they are written into the
     * statement
     */
    protected function checkIdentifier(string $name, string $kind = 'column'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new XmlDbException("Can not use \"$name\" as a $kind name.");
        }

        return $name;
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
