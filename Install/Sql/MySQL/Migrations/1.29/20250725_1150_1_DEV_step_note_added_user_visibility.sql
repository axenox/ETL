-- UP
ALTER TABLE `etl_step_note`
ADD COLUMN `visible_for_user_roles` VARCHAR(255) NOT NULL DEFAULT "AUTHENTICATED";

-- DOWN
ALTER TABLE `etl_step_note`
DROP COLUMN `visible_for_user_roles`;