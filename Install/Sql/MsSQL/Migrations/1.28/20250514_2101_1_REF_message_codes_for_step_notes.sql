-- UP

ALTER TABLE dbo.etl_step_note
	ADD message_code NVARCHAR(10) NULL;

-- DOWN

ALTER TABLE dbo.etl_step_note
	DROP COLUMN message_code;

