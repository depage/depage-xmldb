<?php

namespace Depage\XmlDb;

/**
 * A namespace prefix and the URI it is bound to
 */
class XmlNs
{
    // {{{ variables
    /**
     * prefix of the namespace, for example "db"
     */
    public $ns;
    /**
     * URI of the namespace, for example
     * "http://cms.depagecms.net/ns/database"
     */
    public $uri;
    // }}}

    // {{{ constructor
    /**
     * @param $ns prefix of the namespace
     * @param $uri URI of the namespace
     */
    public function __construct($ns, $uri)
    {
        $this->ns = $ns;
        $this->uri = $uri;
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
