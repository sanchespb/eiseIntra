ALTER TABLE stbl_action ADD COLUMN actFlagMultiple tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Action can be run for multiple items' AFTER actFlagNot4Creator;