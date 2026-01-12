-- Main tables

-- Data flow
CREATE TABLE IF NOT EXISTS etl_flow (
    oid uuid NOT NULL,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid NOT NULL,
    modified_by_user_oid uuid NOT NULL,
    name varchar(50) NOT NULL,
    alias varchar(50) NOT NULL,
    description text,
    app_oid uuid,
    scheduler_oid uuid,
    version varchar(50),
    CONSTRAINT pk_etl_flow PRIMARY KEY (oid),
    CONSTRAINT uq_alias_version UNIQUE (alias,version)
);

-- Data flow step
CREATE TABLE IF NOT EXISTS etl_step (
    oid uuid NOT NULL,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid NOT NULL,
    modified_by_user_oid uuid NOT NULL,
    name varchar(50) NOT NULL,
    type char(1) NOT NULL,
    description text,
    flow_oid uuid NOT NULL,
    from_object_oid uuid,
    to_object_oid uuid,
    etl_prototype_path varchar(250) NOT NULL,
    etl_config_uxon text,
    disabled smallint NOT NULL DEFAULT 0,
    stop_flow_on_error smallint NOT NULL DEFAULT 1,
    run_after_step_oid uuid,
    step_flow_sequence int NOT NULL,
    parent_step_oid uuid,
    CONSTRAINT pk_etl_step PRIMARY KEY (oid)
);

-- Data flow step run
CREATE TABLE IF NOT EXISTS etl_step_run (
    oid uuid NOT NULL,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid NOT NULL,
    modified_by_user_oid uuid NOT NULL,
    step_oid uuid NOT NULL,
    flow_oid uuid NOT NULL,
    flow_run_oid uuid NOT NULL,
    flow_run_pos int NOT NULL,
    start_time timestamp NOT NULL,
    debug_widget text,
    timeout_seconds int NOT NULL,
    end_time timestamp,
    result_count int NOT NULL DEFAULT 0,
    result_uxon text,
    output text,
    incremental_flag smallint NOT NULL DEFAULT 0,
    incremental_after_run_oid uuid,
    step_disabled_flag smallint NOT NULL DEFAULT 0,
    success_flag smallint NOT NULL DEFAULT 0,
    skipped_flag smallint NOT NULL DEFAULT 0,
    invalidated_flag smallint NOT NULL DEFAULT 0,
    error_flag smallint NOT NULL DEFAULT 0,
    error_message varchar(200),
    error_log_id varchar(10),
    error_widget text,
    CONSTRAINT pk_etl_step_run PRIMARY KEY (oid),
    CONSTRAINT uq_flow_run_flow_run_pos UNIQUE (flow_run_oid,flow_run_pos)
);
CREATE INDEX IF NOT EXISTS ix_flow_run_cols ON etl_step_run(flow_run_oid,flow_oid,start_time,end_time);
CREATE INDEX IF NOT EXISTS ix_etl_step_run_step_success_invalidated ON etl_step_run(step_oid,invalidated_flag,success_flag,modified_on,start_time);

-- Data flow notes
CREATE TABLE IF NOT EXISTS etl_step_note (
    oid uuid NOT NULL,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid NOT NULL,
    modified_by_user_oid uuid NOT NULL,
    flow_run_oid uuid,
    step_run_oid uuid,
    message varchar(400),
    message_code varchar(10),
    message_type varchar(20),
    exception_flag smallint,
    exception_message varchar(400),
    exception_log_id varchar(10),
    count_reads int,
    count_writes int,
    count_creates int,
    count_updates int,
    count_deletes int,
    count_errors int,
    count_warnings int,
    ordering_id int,
    context_data text,
    visible_for_user_roles varchar(255) NOT NULL DEFAULT 'AUTHENTICATED',
    CONSTRAINT pk_etl_step_note PRIMARY KEY (oid)
);

-- Webservice tables

-- Webservice type
CREATE TABLE IF NOT EXISTS etl_webservice_type (
    oid uuid NOT NULL,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid NOT NULL,
    modified_by_user_oid uuid NOT NULL,
    name varchar(100) NOT NULL,
    version varchar(50),
    app_oid uuid,
    schema_class varchar(200),
    CONSTRAINT pk_etl_webservice_type PRIMARY KEY (oid)
);

-- Webservices
CREATE TABLE IF NOT EXISTS etl_webservice (
    oid uuid NOT NULL,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid NOT NULL,
    modified_by_user_oid uuid NOT NULL,
    name varchar(100) NOT NULL,
    alias varchar(100) NOT NULL,
    app_oid uuid,
    description text,
    local_url varchar(400) NOT NULL,
    remote_connection_oid uuid,
    config_uxon text,
    swagger_json text,
    type_oid uuid,
    flow_direction varchar(3) NOT NULL,
    version varchar(50),
    enabled_flag smallint NOT NULL DEFAULT 1,
    CONSTRAINT pk_etl_webservice PRIMARY KEY (oid),
    CONSTRAINT uq_local_url_version UNIQUE (local_url,version)
);

-- Webservice flows
CREATE TABLE IF NOT EXISTS etl_webservice_flow (
    oid uuid NOT NULL,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid NOT NULL,
    modified_by_user_oid uuid NOT NULL,
    webservice_oid uuid NOT NULL,
    flow_oid uuid NOT NULL,
    route varchar(255),
    CONSTRAINT pk_etl_webservice_flow PRIMARY KEY (oid)
);
CREATE INDEX IF NOT EXISTS ix_flow ON etl_webservice_flow(flow_oid);
CREATE INDEX IF NOT EXISTS ix_webservice ON etl_webservice_flow(webservice_oid);

-- Webservice request log
CREATE TABLE IF NOT EXISTS etl_webservice_request (
    oid uuid NOT NULL,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid NOT NULL,
    modified_by_user_oid uuid NOT NULL,
    route_oid uuid,
    flow_run_uid uuid,
    url text NOT NULL,
    url_path text NOT NULL,
    http_method varchar(10) NOT NULL,
    http_headers text NOT NULL,
    http_body text,
    http_content_type varchar(200),
    status smallint NOT NULL DEFAULT 10,
    error_message text,
    error_logid varchar(20),
    http_response_code smallint DEFAULT 10,
    result_text text,
    response_body text,
    response_header text,
    CONSTRAINT pk_etl_webservice_request PRIMARY KEY (oid)
);
CREATE INDEX IF NOT EXISTS ix_route ON etl_webservice_request(route_oid);

-- File upload tables

-- File flow
CREATE TABLE IF NOT EXISTS etl_file_flow (
    oid uuid NOT NULL,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid NOT NULL,
    modified_by_user_oid uuid NOT NULL,
    name varchar(100) NOT NULL,
    flow_oid uuid NOT NULL,
    app_oid uuid,
    description text,
    enabled_flag smallint NOT NULL DEFAULT 1,
    subfolder varchar(255),
    alias varchar(100) NOT NULL,
    CONSTRAINT pk_etl_file_flow PRIMARY KEY (oid)
);

-- File upload
CREATE TABLE IF NOT EXISTS etl_file_upload (
    oid uuid NOT NULL,
    created_on timestamp NOT NULL,
    modified_on timestamp NOT NULL,
    created_by_user_oid uuid NOT NULL,
    modified_by_user_oid uuid NOT NULL,
    file_flow_oid uuid,
    file_name varchar(255) NOT NULL,
    file_mimetype varchar(100) NOT NULL,
    file_size_bytes int NOT NULL,
    file_md5 varchar(32),
    comment text,
    flow_run_oid uuid,
    CONSTRAINT pk_etl_file_upload PRIMARY KEY (oid),
    CONSTRAINT fk_etl_file_upload_file_flow FOREIGN KEY (file_flow_oid) REFERENCES etl_file_flow (oid) ON DELETE SET NULL ON UPDATE RESTRICT
);
CREATE INDEX IF NOT EXISTS ix_etl_file_upload_file_flow ON etl_file_upload(file_flow_oid);