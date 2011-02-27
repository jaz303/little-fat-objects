-- Database table definition for LFO
-- Table name can be anything; remember to configure gateway correctly
-- Fields 'id' and those prefixed by double underscores are reserved for use by LFO.
-- Extra fields may be added; these will be used as indexes.
CREATE TABLE `object` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `__object_class` varchar(255) NOT NULL,
  `__serialized_format` varchar(20) NOT NULL,
  `__serialized_data` longtext NOT NULL,
  PRIMARY KEY (`id`)
);
