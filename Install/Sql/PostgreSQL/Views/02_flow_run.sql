CREATE OR REPLACE VIEW etl_flow_run AS
SELECT
    r.flow_run_oid AS oid,
    r.flow_oid,

    -- counts
    COUNT(*) AS steps_started,
    SUM(COALESCE(r.success_flag, 0) + COALESCE(r.error_flag, 0)) AS steps_run,
    SUM(COALESCE(r.error_flag, 0))            AS errors,
    SUM(COALESCE(r.step_disabled_flag, 0))    AS steps_disabled,
    SUM(COALESCE(r.skipped_flag, 0))          AS steps_skipped,
    SUM(COALESCE(r.invalidated_flag, 0))      AS steps_invalidated,

    -- running vs timed-out
    SUM(
            CASE
                WHEN r.success_flag = 0
                    AND r.end_time IS NULL
                    AND r.start_time + (r.timeout_seconds * INTERVAL '1 second') >= NOW()
                    THEN 1 ELSE 0
                END
    ) AS steps_running,
    SUM(
            CASE
                WHEN r.success_flag = 0
                    AND r.end_time IS NULL
                    AND r.start_time + (r.timeout_seconds * INTERVAL '1 second') < NOW()
                    THEN 1 ELSE 0
                END
    ) AS steps_timed_out,

    -- validity and valid rows
    CASE
        WHEN SUM(r.success_flag)
                 + SUM(r.step_disabled_flag)
                 + SUM(r.skipped_flag)
                 - SUM(r.invalidated_flag)
            = COUNT(*)
            THEN 1 ELSE 0
        END AS valid_flag,
    CASE
        WHEN SUM(r.success_flag)
                 + SUM(r.step_disabled_flag)
                 + SUM(r.skipped_flag)
                 - SUM(r.invalidated_flag)
            = COUNT(*)
            THEN SUM(COALESCE(r.result_count, 0)) ELSE 0
        END AS valid_rows,

    -- times and durations
    MIN(r.start_time) AS start_time,
    MAX(r.end_time)   AS end_time,
    EXTRACT(EPOCH FROM (MAX(r.end_time) - MIN(r.start_time)))::bigint AS duration_seconds,
    (MIN(r.start_time) - MAX(r.end_time)) AS duration,  -- mirrors MySQL TIMEDIFF(start, end)

    -- error + audit info
    MAX(r.error_message)        AS error_message,
    MAX(r.error_log_id)         AS error_log_id,
    MIN(r.created_on)           AS created_on,
    MAX(r.modified_on)          AS modified_on,
    CAST(MAX(r.created_by_user_oid::text) AS uuid)  AS created_by_user_oid,
    CAST(MAX(r.modified_by_user_oid::text) AS uuid) AS modified_by_user_oid
FROM etl_step_run r
GROUP BY r.flow_run_oid, r.flow_oid;