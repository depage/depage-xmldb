<?php

namespace Depage\XmlDb\XmlDoctypes;

use Depage\XmlDb\Interfaces\DoctypeInterface;

/**
 * Default doctype handler of a document
 *
 * The handler allows every node everywhere and does not change the document.
 * It is used for the documents that do not need any rules. Documents with
 * rules use a handler of their own, see the documentation of the
 * DoctypeInterface.
 */
class Base implements DoctypeInterface
{
    // {{{ variables
    // list of elements that may created by a user
    protected $availableNodes = [];

    // list of valid parents given by nodename
    protected $validParents = [
        '*' => [
            '*',
        ],
    ];

    // list of names of nodes not to be affected by whitespace stripping
    protected $preserveWhitespace = [];

    protected $xmlDb;
    protected $document;
    // }}}

    // {{{ constructor
    /**
     * @param $xmlDb
     * @param $document
     */
    public function __construct($xmlDb, $document)
    {
        $this->xmlDb = $xmlDb;
        $this->document = $document;
    }
    // }}}

    // {{{ getPermissions
    /**
     * gets the rules of the document
     *
     * @return  object  object with the properties "validParents" and
     *                   "availableNodes"
     */
    public function getPermissions()
    {
        return (object) [
            'validParents' => $this->getValidParents(),
            'availableNodes' => $this->getAvailableNodes(),
        ];
    }
    // }}}

    // {{{ getValidParents
    /**
     * gets the names of the nodes a node may be placed in, indexed by the
     * name of the node
     *
     * @return  array  array of node names, indexed by node name
     */
    public function getValidParents()
    {
        return $this->validParents;
    }
    // }}}

    // {{{ getAvailableNodes
    /**
     * gets the nodes that may be created by name, indexed by their name
     *
     * @return  array  array of node descriptions, indexed by node name
     */
    public function getAvailableNodes()
    {
        return $this->availableNodes;
    }
    // }}}

    // {{{ getPreserveWhitespace
    /**
     * gets the names of the nodes whose whitespace is not stripped while the
     * document is saved
     *
     * @return  array  array of node names
     */
    public function getPreserveWhitespace()
    {
        return $this->preserveWhitespace;
    }
    // }}}

    // {{{ getNewNodeFor
    /**
     * builds the node that is created by Document::addNodeByName()
     *
     * the node is built from the description in $availableNodes: either from
     * its "xmlTemplateData" property or from its "newName" and "attributes"
     * properties
     *
     * @param   string  $name  name of the node to create
     *
     * @return  \DOMNodeList|false  the new nodes or false if the node may
     *                             not be created
     */
    public function getNewNodeFor($name)
    {
        $result = false;

        if (isset($this->availableNodes[$name])) {
            $nodeInfo = $this->availableNodes[$name];
            $docInfo = $this->document->getNamespacesAndEntities();

            if (isset($nodeInfo->xmlTemplateData)) {
                $xml = $nodeInfo->xmlTemplateData;
            } else {
                $xml = "<$name {$docInfo->namespaces}";
                if (!empty($nodeInfo->newName)) {
                    $xml .= " name=\"" . htmlspecialchars($nodeInfo->newName) . "\"";
                }
                if (isset($nodeInfo->attributes)) {
                    foreach ($nodeInfo->attributes as $attr => $value) {
                        $xml .= " $attr=\"" . htmlspecialchars($value) . "\"";
                    }
                }
                $xml .= '/>';
            }
            $doc = new \DOMDocument();
            $doc->loadXML("<root xmlns:pg=\"http://cms.depagecms.net/ns/page\" xmlns:sec=\"http://cms.depagecms.net/ns/section\" xmlns:edit=\"http://cms.depagecms.net/ns/edit\">$xml</root>");

            $result = $doc->documentElement->childNodes;
        }

        return $result;
    }
    // }}}

    // {{{ isAllowedIn
    /**
     * checks whether a node may be placed in another node
     *
     * @param   string  $nodeName       name of the node
     * @param   string  $targetNodeName name of the target node
     *
     * @return  bool  true if the node may be placed in the target node
     */
    public function isAllowedIn($nodeName, $targetNodeName)
    {
        $result = false;

        if (isset($this->validParents['*'])) {
            $result = in_array('*', $this->validParents['*']) || in_array($targetNodeName, $this->validParents['*']);
        } elseif (isset($this->validParents[$nodeName])) {
            $result = in_array('*', $this->validParents[$nodeName]) || in_array($targetNodeName, $this->validParents[$nodeName]);
        }

        return $result;
    }
    // }}}

    // {{{ isAllowedAdd
    /**
     * checks whether a node may be added to a target node
     *
     * @param   \DomNode  $node      node to add
     * @param   int       $targetId  db-id of the target node
     *
     * @return  bool  true if the node may be added
     */
    public function isAllowedAdd($node, $targetId)
    {
        return $this->isAllowedIn(
            $node->nodeName,
            $this->document->getNodeNameById($targetId)
        );
    }
    // }}}

    // {{{ isAllowedCopy
    /**
     * checks whether a node may be copied to a target node
     *
     * @param   int  $nodeId    db-id of the node to copy
     * @param   int  $targetId  db-id of the target node
     *
     * @return  bool  true if the node may be copied
     */
    public function isAllowedCopy($nodeId, $targetId)
    {
        return $this->isAllowedMove($nodeId, $targetId);
    }
    // }}}

    // {{{ isAllowedMove
    /**
     * checks whether a node may be moved to a target node
     *
     * @param   int  $nodeId    db-id of the node to move
     * @param   int  $targetId  db-id of the target node
     *
     * @return  bool  true if the node may be moved
     */
    public function isAllowedMove($nodeId, $targetId)
    {
        return $this->isAllowedIn(
            $this->document->getNodeNameById($nodeId),
            $this->document->getNodeNameById($targetId)
        );
    }
    // }}}

    // {{{ isAllowedDelete
    /**
     * checks whether a node may be deleted
     *
     * @param   int  $nodeId  db-id of the node to delete
     *
     * @return  bool  true if the node may be deleted
     */
    public function isAllowedDelete($nodeId)
    {
        return true;
    }
    // }}}

    // {{{ onDocumentChange
    /**
     * called after the document has been changed and committed
     *
     * @return  bool  true if the document is valid
     */
    public function onDocumentChange()
    {
        return true;
    }
    // }}}

    // {{{ onAddNode
    /**
     * called when a node is added to the document
     *
     * @param   \DomNode  $node       the new node
     * @param   int       $target_id  db-id of the target node
     * @param   int       $target_pos  position of the node in the child list
     * @param   array     $extras     additional data of the caller
     *
     * @return  bool  true if the node may be added
     */
    public function onAddNode(\DomNode $node, $target_id, $target_pos, $extras)
    {
        return true;
    }
    // }}}

    // {{{ onCopyNode
    /**
     * called when a node is copied
     *
     * @param   int  $node_id  db-id of the node that was copied
     * @param   int  $copy_id  db-id of the copy
     *
     * @return  bool  true if the copy is valid
     */
    public function onCopyNode($node_id, $copy_id)
    {
        return true;
    }
    // }}}

    // {{{ onMoveNode
    /**
     * called when a node is moved
     *
     * @param   int  $node_id      db-id of the node that was moved
     * @param   int  $oldParentId  db-id of the former parent node
     *
     * @return  bool  true if the new position is valid
     */
    public function onMoveNode($node_id, $oldParentId)
    {
        return true;
    }
    // }}}

    // {{{ onDeleteNode
    /**
     * called when a node is deleted
     *
     * @param   int  $node_id    db-id of the deleted node
     * @param   int  $parent_id  db-id of the former parent node
     *
     * @return  bool  true if the deletion is valid
     */
    public function onDeleteNode($node_id, $parent_id)
    {
        return true;
    }
    // }}}

    // {{{ onSetAttribute
    /**
     * called when an attribute of a node is changed
     *
     * @param   int     $nodeId   db-id of the node
     * @param   string  $attrName name of the attribute
     * @param   string  $oldVal   value of the attribute before the change
     * @param   string  $newVal   value of the attribute after the change
     *
     * @return  bool  true if the new value is valid
     */
    public function onSetAttribute($nodeId, $attrName, $oldVal, $newVal)
    {
        return true;
    }
    // }}}

    // {{{ onHistorySave
    /**
     * called when a version of the document is written to the history
     *
     * @return  bool  true if the version is valid
     */
    public function onHistorySave()
    {
        return true;
    }
    // }}}

    // {{{ testDocument
    /**
     * called when a document is read; the document may be changed here
     *
     * @param   \DOMDocument  $xml  the document that was read
     *
     * @return  bool  true if the document was changed and has to be saved
     */
    public function testDocument($xml)
    {
        return false;
    }
    // }}}

    // {{{ testDocumentForHistory
    /**
     * called when a version of the document is written to the history; the
     * document may be changed here
     *
     * @param   \DOMDocument  $xml  the version that is written
     *
     * @return  bool  true if the version was changed
     */
    public function testDocumentForHistory($xml)
    {
        return false;
    }
    // }}}
}
