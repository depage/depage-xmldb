<?php

namespace Depage\XmlDb\Tests;

class DoctypeHandlerTransactionTestClass extends DoctypeHandlerTestClass
{
    public $onAddNode = 0;
    public $onCopyNode = 0;
    public $onMoveNode = 0;
    public $onDeleteNode = 0;
    public $onDocumentChange = 0;

    // {{{ onAddNode
    public function onAddNode(\DomNode $node, $targetId, $targetIndex, $extras)
    {
        $this->transactionTest('onAddNode');
        $this->onAddNode++;

        return parent::onAddNode($node, $targetId, $targetIndex, $extras);
    }
    // }}}
    // {{{ onCopyNode
    public function onCopyNode($nodeId, $copyId)
    {
        $this->transactionTest('onCopyNode');
        $this->onCopyNode++;

        return parent::onCopyNode($nodeId, $copyId);
    }
    // }}}
    // {{{ onMoveNode
    public function onMoveNode($nodeId, $movedId)
    {
        $this->transactionTest('onMoveNode');
        $this->onMoveNode++;

        return parent::onMoveNode($nodeId, $movedId);
    }
    // }}}
    // {{{ onDeleteNode
    public function onDeleteNode($nodeId, $parentId)
    {
        if (!$this->document->isInTransaction()) {
            throw new \Exception("onDeleteNode triggered outside transaction.");
        }
        $this->onDeleteNode++;

        return parent::onDeleteNode($nodeId, $parentId);
    }
    // }}}
    // {{{ onDocumentChange
    public function onDocumentChange()
    {
        $this->transactionTest('onDocumentChange');
        $this->onDocumentChange++;

        return parent::onDocumentChange();
    }
    // }}}

    // {{{ testDocument
    public function testDocument($xml)
    {
        return $this->testDocument;
    }
    // }}}

    // {{{ transactionTest
    public function transactionTest($hook)
    {
        if ($this->document->isInTransaction()) {
            throw new \Exception("$hook triggered during transaction.");
        }
    }
    // }}}
}

/* vim:set ft=php fenc=UTF-8 sw=4 sts=4 fdm=marker et : */
