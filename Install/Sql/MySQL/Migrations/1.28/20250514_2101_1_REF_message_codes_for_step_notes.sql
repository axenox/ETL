-- UP

ALTER TABLE `etl_step_note`
	ADD COLUMN `message_code` VARCHAR(10) NULL DEFAULT NULL AFTER `message`;

-- DOWN

ALTER TABLE `etl_step_note`
	DROP COLUMN `message_code`;

