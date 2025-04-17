-- UP

DROP INDEX IF EXISTS [IX_etl_step_run_step_success_invalidated] ON [dbo].[etl_step_run];
GO;
CREATE NONCLUSTERED INDEX [IX_etl_step_run_step_success_invalidated] ON [dbo].[etl_step_run] (
  [step_oid],
  [invalidated_flag], 
  [success_flag]
) INCLUDE (
  [modified_on], 
  [result_uxon], 
  [start_time]
) WITH (ONLINE = ON);

-- DOWN

DROP INDEX IF EXISTS [IX_etl_step_run_step_success_invalidated] ON [dbo].[etl_step_run];
