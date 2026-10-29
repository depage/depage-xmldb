<?php

namespace Depage\XmlDb\Interfaces;

/**
 * Interface of the doctype handler of a document
 *
 * A doctype handler decides which nodes may be created, where they may be
 * placed and what happens when the document is changed. It is stored in the
 * "type" column of a document and instantiated when the document is loaded.
 *
 * @see \Depage\XmlDb\XmlDoctypes\Base for an implementation that allows
 *      everything
 */
interface DoctypeInterface
{
    /**
     * gets the rules of the document
     *
     * @return  object  object with the properties "validParents" and
     *                   "availableNodes"
     */
    public function getPermissions();
    /**
     * gets the names of the nodes a node may be placed in, indexed by the
     * name of the node; the key "*" is the fallback for all nodes that are
     * not listed explicitly
     *
     * @return  array  array of node names, indexed by node name
     */
    public function getValidParents();
    /**
     * gets the nodes that may be created by name, indexed by their name
     *
     * @return  array  array of node descriptions, indexed by node name
     */
    public function getAvailableNodes();
    /**
     * gets the names of the nodes whose whitespace is not stripped while the
     * document is saved
     *
     * @return  array  array of node names
     */
    public function getPreserveWhitespace();
    /**
     * builds the node that is created by Document::addNodeByName()
     *
     * @param   string  $name  name of the node to create
     *
     * @return  \DOMNodeList|false  the new nodes or false if the node may
     *                             not be created
     */
    public function getNewNodeFor($name);

    /**
     * checks whether a node may be placed in another node
     *
     * @param   string  $nodeName       name of the node
     * @param   string  $targetNodeName name of the target node
     *
     * @return  bool  true if the node may be placed in the target node
     */
    public function isAllowedIn($nodeName, $targetNodeName);
    /**
     * checks whether a node may be added to a target node
     *
     * @param   \DomNode  $node      node to add
     * @param   int       $targetId  db-id of the target node
     *
     * @return  bool  true if the node may be added
     */
    public function isAllowedAdd($nodeName, $targetId);
    /**
     * checks whether a node may be copied to a target node
     *
     * @param   int  $nodeId    db-id of the node to copy
     * @param   int  $targetId  db-id of the target node
     *
     * @return  bool  true if the node may be copied
     */
    public function isAllowedCopy($nodeId, $targetId);
    /**
     * checks whether a node may be moved to a target node
     *
     * @param   int  $nodeId    db-id of the node to move
     * @param   int  $targetId  db-id of the target node
     *
     * @return  bool  true if the node may be moved
     */
    public function isAllowedMove($nodeId, $targetId);
    /**
     * checks whether a node may be deleted
     *
     * @param   int  $nodeId  db-id of the node to delete
     *
     * @return  bool  true if the node may be deleted
     */
    public function isAllowedDelete($nodeId);

    /**
     * called after the document has been changed and committed
     *
     * @return  bool  true if the document is valid
     */
    public function onDocumentChange();
    /**
     * called when a node is added to the document
     *
     * @param   \DomNode  $node       the new node
     * @param   int       $targetId  db-id of the target node
     * @param   int       $targetPos  position of the node in the child list
     * @param   array     $extras     additional data of the caller
     *
     * @return  bool  true if the node may be added
     */
    public function onAddNode(\DomNode $node, $targetId, $targetPos, $extras);
    /**
     * called when a node is copied
     *
     * @param   int  $nodeId  db-id of the node that was copied
     * @param   int  $copyId  db-id of the copy
     *
     * @return  bool  true if the copy is valid
     */
    public function onCopyNode($nodeId, $copyId);
    /**
     * called when a node is moved
     *
     * @param   int  $nodeId      db-id of the node that was moved
     * @param   int  $oldParentId db-id of the former parent node
     *
     * @return  bool  true if the new position is valid
     */
    public function onMoveNode($nodeId, $oldParentId);
    /**
     * called when a node is deleted
     *
     * @param   int  $nodeId    db-id of the deleted node
     * @param   int  $parentId  db-id of the former parent node
     *
     * @return  bool  true if the deletion is valid
     */
    public function onDeleteNode($nodeId, $parentId);
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
    public function onSetAttribute($nodeId, $attrName, $oldVal, $newVal);

    /**
     * called when a document is read; the document may be changed here
     *
     * @param   \DOMDocument  $xml  the document that was read
     *
     * @return  bool  true if the document was changed and has to be saved
     */
    public function testDocument($xml);
    /**
     * called when a version of the document is written to the history; the
     * document may be changed here
     *
     * @param   \DOMDocument  $xml  the version that is written
     *
     * @return  bool  true if the version was changed
     */
    public function testDocumentForHistory($xml);
}
