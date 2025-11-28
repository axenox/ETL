-- exf_monitor_error
CREATE TABLE IF NOT EXISTS exf_monitor_error (
    oid                 uuid            NOT NULL,
    created_on          timestamp(7)    NOT NULL,
    modified_on         timestamp(7)    NOT NULL,
    created_by_user_oid uuid,
    modified_by_user_oid uuid,
    log_id              varchar(10)     NOT NULL,
    error_level         varchar(20)     NOT NULL,
    error_widget        text            NOT NULL,
    message             text            NOT NULL,
    "date"              date            NOT NULL,
    status              smallint        NOT NULL,
    user_oid            uuid,
    action_oid          uuid,
    request_id          varchar(50)     NOT NULL DEFAULT '',
    comment             text,
    ticket_no           varchar(20),
    CONSTRAINT pk_exf_monitor_error_oid PRIMARY KEY (oid)
);


-- etl_flow
CREATE TABLE IF NOT EXISTS etl_flow (
    oid                 uuid            NOT NULL,
    created_on          timestamp(7)    NOT NULL,
    modified_on         timestamp(7)    NOT NULL,
    created_by_user_oid uuid            NOT NULL,
    modified_by_user_oid uuid           NOT NULL,
    name                varchar(50)     NOT NULL,
    alias               varchar(50)     NOT NULL,
    description         text,
    app_oid             uuid,
    scheduler_oid       uuid,
    version             varchar(50),
    CONSTRAINT pk_etl_flow PRIMARY KEY (oid)
);


-- etl_step
CREATE TABLE IF NOT EXISTS etl_step (
    oid                  uuid            NOT NULL,
    created_on           timestamp(7)    NOT NULL,
    modified_on          timestamp(7)    NOT NULL,
    created_by_user_oid  uuid            NOT NULL,
    modified_by_user_oid uuid            NOT NULL,
    name                 varchar(50)     NOT NULL,
    type                 char(1)         NOT NULL,
    description          text,
    flow_oid             uuid            NOT NULL,
    from_object_oid      uuid,
    to_object_oid        uuid,
    etl_prototype_path   varchar(250)    NOT NULL,
    etl_config_uxon      text,
    disabled             smallint        NOT NULL DEFAULT 0,
    stop_flow_on_error   smallint        NOT NULL DEFAULT 1,
    step_flow_sequence   integer         NOT NULL,
    parent_step_oid      uuid,
    CONSTRAINT pk_etl_step PRIMARY KEY (oid)
);


-- etl_step_run
CREATE TABLE IF NOT EXISTS etl_step_run (
    oid                       uuid            NOT NULL,
    created_on                timestamp(7)    NOT NULL,
    modified_on               timestamp(7)    NOT NULL,
    created_by_user_oid       uuid            NOT NULL,
    modified_by_user_oid      uuid            NOT NULL,
    step_oid                  uuid            NOT NULL,
    flow_oid                  uuid            NOT NULL,
    flow_run_oid              uuid            NOT NULL,
    flow_run_pos              integer         NOT NULL,
    start_time                timestamp(7)    NOT NULL,
    debug_widget              text,
    timeout_seconds           integer         NOT NULL,
    end_time                  timestamp(7),
    result_count              integer         NOT NULL DEFAULT 0,
    result_uxon               text,
    output                    text,
    incremental_flag          smallint        NOT NULL DEFAULT 0,
    incremental_after_run_oid uuid,
    step_disabled_flag        smallint        NOT NULL DEFAULT 0,
    success_flag              smallint        NOT NULL DEFAULT 0,
    skipped_flag              smallint        NOT NULL DEFAULT 0,
    invalidated_flag          smallint        NOT NULL DEFAULT 0,
    error_flag                smallint        NOT NULL DEFAULT 0,
    error_message             varchar(200),
    error_log_id              varchar(10),
    error_widget              text,
    CONSTRAINT pk_etl_step_run PRIMARY KEY (oid),
    CONSTRAINT uc_etl_step_run_pos_unique_per_flow_run
    UNIQUE (flow_run_oid, flow_run_pos)
);


-- etl_webservice_type
CREATE TABLE IF NOT EXISTS etl_webservice_type (
    oid                 uuid            NOT NULL,
    created_on          timestamp       NOT NULL,
    modified_on         timestamp       NOT NULL,
    created_by_user_oid uuid            NOT NULL,
    modified_by_user_oid uuid           NOT NULL,
    name                varchar(100)    NOT NULL,
    version             varchar(50),
    app_oid             uuid,
    schema_class        varchar(200),
    CONSTRAINT pk_etl_webservice_type PRIMARY KEY (oid)
);