-- UP

EXEC sp_rename 'dbo.etl_step_note.log_level', 'message_type', 'COLUMN';
GO;

UPDATE etl_step_note SET message_type = 'ERROR' WHERE message_type IN ('error', 'alert', 'critical', 'emergency');
UPDATE etl_step_note SET message_type = 'WARNING' WHERE message_type = 'warning';
UPDATE etl_step_note SET message_type = 'HINT' WHERE message_type = 'notice';
UPDATE etl_step_note SET message_type = 'INFO' WHERE message_type = 'info';
UPDATE etl_step_note SET message_type = NULL WHERE message_type = 'debug';

-- DOWN

EXEC sp_rename 'dbo.etl_step_note.message_type', 'log_level', 'COLUMN';