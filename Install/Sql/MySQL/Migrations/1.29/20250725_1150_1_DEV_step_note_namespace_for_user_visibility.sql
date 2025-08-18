-- UP
UPDATE `etl_step_note`
SET `visible_for_user_roles` = CASE
    WHEN `visible_for_user_roles` IS NULL THEN NULL
    WHEN `visible_for_user_roles` = '' THEN ''
    ELSE CONCAT('exface.Core.', `visible_for_user_roles`)
END;

-- DOWN

UPDATE `etl_step_note`
SET `visible_for_user_roles` = CASE
    WHEN `visible_for_user_roles` IS NULL THEN NULL
    WHEN `visible_for_user_roles` = '' THEN ''
    ELSE Replace(`visible_for_user_roles`,'exface.Core.', '')
END;