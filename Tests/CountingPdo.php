<?php

namespace Depage\XmlDb\Tests;

/**
 * database connection for the tests of the write buffers (Helpers\NodeBatch
 * and Helpers\CaseUpdate): the buffers only prepare statements, so this
 * hands them through to the connection of the test and counts them, which
 * is how the tests see how many statements a buffer really uses.
 */
class CountingPdo
{
    // {{{ variables
    public $statements = 0;
    // }}}

    // {{{ __construct
    public function __construct(protected $pdo) {}
    // }}}

    // {{{ reset
    public function reset(): void
    {
        $this->statements = 0;
    }
    // }}}

    // {{{ prepare
    public function prepare($sql, $options = [])
    {
        $this->statements++;

        return $this->pdo->prepare($sql, $options);
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
