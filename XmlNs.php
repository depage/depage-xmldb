<?php

namespace Depage\XmlDb;

class XmlNs
{
    // {{{ variables
    public $ns;
    public $uri;
    // }}}

    // {{{ constructor
    public function __construct($ns, $uri)
    {
        $this->ns = $ns;
        $this->uri = $uri;
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
