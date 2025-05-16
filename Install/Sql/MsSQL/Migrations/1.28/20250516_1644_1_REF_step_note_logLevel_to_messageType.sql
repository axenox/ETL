-- UP

ALTER TABLE etl_step_note
RENAME COLUMN log_level TO message_type;

-- DOWN

ALTER TABLE etl_step_note
RENAME COLUMN message_type TO log_level;