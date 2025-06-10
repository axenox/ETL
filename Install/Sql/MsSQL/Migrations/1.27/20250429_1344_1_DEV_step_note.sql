-- UP

IF COL_LENGTH('dbo.etl_step_note', 'ordering_id') IS NULL 
ALTER TABLE dbo.etl_step_note
    ADD ordering_id int

-- DOWN

IF COL_LENGTH('dbo.etl_step_note', 'ordering_id') IS NULL 
ALTER TABLE dbo.etl_step_note
    DROP ordering_id

