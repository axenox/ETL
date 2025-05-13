-- UP

ALTER TABLE `etl_step_note`
    ADD COLUMN `ordering_id` int;

-- DOWN

ALTER TABLE `etl_step_note`
    DROP COLUMN `ordering_id`;

