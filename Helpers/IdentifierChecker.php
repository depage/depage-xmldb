<?php

/**
 * @file    Helpers/IdentifierChecker.php
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
 * @internal validation of identifiers that are interpolated into
 * statements; not part of the public api of this library
 */
trait IdentifierChecker
{
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
