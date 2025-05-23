-- UP

ALTER TABLE `etl_step_note`
RENAME COLUMN `log_level` TO `message_type`;

UPDATE etl_step_note SET message_type = 'ERROR' WHERE message_type IN ('error', 'alert', 'critical', 'emergency');
UPDATE etl_step_note SET message_type = 'WARNING' WHERE message_type = 'warning';
UPDATE etl_step_note SET message_type = 'HINT' WHERE message_type = 'notice';
UPDATE etl_step_note SET message_type = 'INFO' WHERE message_type = 'info';
UPDATE etl_step_note SET message_type = NULL WHERE message_type = 'debug';

-- DOWN

ALTER TABLE `etl_step_note`
RENAME COLUMN `message_type` TO `log_level`;