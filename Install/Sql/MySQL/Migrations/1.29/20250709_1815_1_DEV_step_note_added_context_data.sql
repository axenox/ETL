-- UP

ALTER TABLE `etl_step_note` ADD `context_data` LONGTEXT;

-- DOWN

ALTER TABLE `etl_step_note` DROP COLUMN `context_data`;