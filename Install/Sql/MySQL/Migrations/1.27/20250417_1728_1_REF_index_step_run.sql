-- UP

DROP INDEX IF EXISTS IX_etl_step_run_step_success_invalidated ON etl_step_run;

CREATE INDEX IX_etl_step_run_step_success_invalidated
ON etl_step_run (
  step_oid,
  invalidated_flag,
  success_flag,
  modified_on,
  start_time
);

-- DOWN

DROP INDEX IF EXISTS IX_etl_step_run_step_success_invalidated ON etl_step_run;
