-- UP

IF OBJECT_ID ('dbo.etl_step_note', N'U') IS NULL
CREATE TABLE etl_step_note (
  oid binary(16) NOT NULL,
  created_on datetime NOT NULL,
  modified_on datetime NOT NULL,
  created_by_user_oid binary(16) NOT NULL,
  modified_by_user_oid binary(16) NOT NULL,
  flow_run_oid binary(16),
  step_run_oid binary(16),
  message nvarchar(400),
  log_level varchar(20),
  exception_flag tinyint,
  exception_message nvarchar(400),
  exception_log_id nvarchar(10),
  count_reads int,
  count_writes int,
  count_creates int,
  count_updates int,
  count_deletes int,
  count_errors int,
  count_warnings int,
  CONSTRAINT PK_etl_step_note PRIMARY KEY CLUSTERED (oid)
)

-- DOWN

-- Do not drop the notes table, keep the data!