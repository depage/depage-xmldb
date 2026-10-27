/*
    XmlDb Tree Table
    -----------------------------------

    @tablename _xmltree
    @connection _xmldocs
    @version 1.5.0-beta.1
*/
CREATE TABLE `_xmltree` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_doc` int(10) unsigned DEFAULT '0',
  `id_parent` int(10) unsigned DEFAULT NULL,
  `pos` mediumint(8) unsigned DEFAULT '0',
  `name` varchar(50) DEFAULT NULL,
  `value` mediumtext NOT NULL,
  `type` enum('ELEMENT_NODE','TEXT_NODE','CDATA_SECTION_NODE','PI_NODE','COMMENT_NODE','ENTITY_REF_NODE','WAIT_FOR_REPLACE','DELETED') DEFAULT 'ELEMENT_NODE',
  PRIMARY KEY (`id`),
  KEY `SECONDARY` (`id_parent`,`id_doc`,`type`),
  KEY `THIRD` (`name`),
  KEY `id_doc` (`id_doc`),
  CONSTRAINT `_xmltree_ibfk_1` FOREIGN KEY (`id_parent`) REFERENCES `_xmltree` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `_xmltree_ibfk_2` FOREIGN KEY (`id_doc`) REFERENCES `_xmldocs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*
    @version 2.0.0
*/

/*
    @version 2.2.0

    Sparse sibling positions: instead of renumbering the whole child list
    on every insert/delete, sibling positions are spaced by a gap (65536)
    and new nodes are positioned between their future neighbours.
    pos grows to bigint to leave room for midpoint positions, existing
    rows are renumbered to multiples of the gap and a unique index
    enforces that no two siblings share a position.
*/
ALTER TABLE `_xmltree` MODIFY `pos` bigint unsigned DEFAULT NULL;

UPDATE `_xmltree` AS tree
JOIN (
    SELECT
        id,
        (ROW_NUMBER() OVER (PARTITION BY id_doc, id_parent ORDER BY pos, id) - 1) * 65536 AS new_pos
    FROM `_xmltree`
    WHERE id_doc IS NOT NULL
) AS positions ON positions.id = tree.id
SET tree.pos = positions.new_pos;

ALTER TABLE `_xmltree` ADD UNIQUE KEY `sibling` (`id_doc`, `id_parent`, `pos`);
