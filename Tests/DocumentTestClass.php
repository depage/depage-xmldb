<?php

namespace Depage\XmlDb\Tests;

class DocumentTestClass extends \Depage\XmlDb\Document
{
    public $free_element_ids = [];
    // {{{ setDoctypeHandler
    public function setDoctypeHandler($dth)
    {
        $this->doctypeHandlers[$this->doc_id] = $dth;
    }
    // }}}

    // {{{ getAppendIndex
    public function getAppendIndex($target_id)
    {
        return parent::getAppendIndex($target_id);
    }
    // }}}
    // {{{ saveNodeToDb
    public function saveNodeToDb($node, $id, $target_id, $targetIndex, $set_position = false)
    {
        return parent::saveNodeToDb($node, $id, $target_id, $targetIndex, $set_position);
    }
    // }}}
    // {{{ getNodeArrayForSaving
    public function getNodeArrayForSaving(&$node_array, $node, $parent_index = null, $pos = 0, $stripwhitespace = true)
    {
        return parent::getNodeArrayForSaving($node_array, $node, $parent_index, $pos, $stripwhitespace);
    }
    // }}}
    // {{{ getFreeNodeIds
    public function getFreeNodeIds($preference = [])
    {
        return parent::getFreeNodeIds($preference);
    }
    // }}}
    // {{{ rebalancePositions
    public function rebalancePositions($parent_id, $order = null)
    {
        return parent::rebalancePositions($parent_id, $order);
    }
    // }}}
    // {{{ saveNodeIn
    public function saveNodeIn($node, $target_id, $targetIndex, $inc_children)
    {
        return parent::saveNodeIn($node, $target_id, $targetIndex, $inc_children);
    }
    // }}}
    // {{{ saveNodePrivate
    public function saveNodePrivate($node)
    {
        return parent::saveNodePrivate($node);
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
