<?php

namespace Depage\XmlDb\Tests;

class DocumentTestClass extends \Depage\XmlDb\Document
{
    public $freeElementIds = [];
    // {{{ setDoctypeHandler
    public function setDoctypeHandler($dth)
    {
        $this->doctypeHandlers[$this->docId] = $dth;
    }
    // }}}

    // {{{ getAppendIndex
    public function getAppendIndex($targetId)
    {
        return parent::getAppendIndex($targetId);
    }
    // }}}
    // {{{ saveNodeToDb
    public function saveNodeToDb($node, $id, $targetId, $targetIndex, $setPosition = false)
    {
        return parent::saveNodeToDb($node, $id, $targetId, $targetIndex, $setPosition);
    }
    // }}}
    // {{{ getNodeArrayForSaving
    public function getNodeArrayForSaving(&$nodeArray, $node, $parentIndex = null, $pos = 0, $stripwhitespace = true)
    {
        return parent::getNodeArrayForSaving($nodeArray, $node, $parentIndex, $pos, $stripwhitespace);
    }
    // }}}
    // {{{ getFreeNodeIds
    public function getFreeNodeIds($preference = [])
    {
        return parent::getFreeNodeIds($preference);
    }
    // }}}
    // {{{ rebalancePositions
    public function rebalancePositions($parentId, $order = null)
    {
        return parent::rebalancePositions($parentId, $order);
    }
    // }}}
    // {{{ saveNodeIn
    public function saveNodeIn($node, $targetId, $targetIndex, $incChildren)
    {
        return parent::saveNodeIn($node, $targetId, $targetIndex, $incChildren);
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
