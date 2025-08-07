-- UP
ALTER TABLE etl_step_note
ADD visible_for_user_roles VARCHAR(255) NOT NULL 
    CONSTRAINT DF_etl_step_note_visible_for_user_roles DEFAULT 'AUTHENTICATED';

-- DOWN
ALTER TABLE etl_step_note DROP CONSTRAINT DF_etl_step_note_visible_for_user_roles;

ALTER TABLE etl_step_note
DROP COLUMN visible_for_user_roles;
