CREATE OR REPLACE VIEW etl_flow_run_latest AS
SELECT 
	r.flow_oid,
	r.flow_run_uid AS flow_run_oid
FROM etl_run r
ORDER BY start_time DESC
LIMIT 1;