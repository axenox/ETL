/*
 * Searches data flow models in the same way as the `exf_model_search` view does for core models.
 *
 */
IF OBJECT_ID('dbo.etl_model_search', 'V') IS NOT NULL
    DROP VIEW [dbo].[etl_model_search];
GO

CREATE VIEW [dbo].[etl_model_search] (
    uxon,
    object_name,
    attribute_name,
    instance_name,
    instance_alias,
    oid,
    table_name,
    app_oid,
    component
) AS

/* DataFlow step config UXON */
SELECT
    s.etl_config_uxon AS uxon,
    'DataFlow step' AS object_name,
    'Configuration' AS attribute_name,
    CONCAT(
        (CASE
            WHEN f.version IS NOT NULL AND f.version != '' THEN CONCAT(f.name, ' [', f.version, ']')
            ELSE f.name
        END),
        ' > ',
        CONCAT(s.step_flow_sequence, '. ', s.name)
    ) AS instance_name,
    NULL AS instance_alias,
    s.oid AS oid,
    'etl_step' AS table_name,
    f.app_oid AS app_oid,
    'dataflow_step' AS component
FROM etl_step AS s
         INNER JOIN etl_flow AS f
                    ON f.oid = s.flow_oid
WHERE s.etl_config_uxon IS NOT NULL

UNION ALL

/* Webservice config UXON */
SELECT
    ws.config_uxon AS uxon,
    'Web service' AS object_name,
    'Configuration' AS attribute_name,
    CONCAT(ws.name, ' [', ws.version, ']') AS instance_name,
    ws.alias AS instance_alias,
    ws.oid AS oid,
    'etl_webservice' AS table_name,
    ws.app_oid AS app_oid,
    'dataflow_webservice' AS component
FROM etl_webservice AS ws
WHERE ws.config_uxon IS NOT NULL

UNION ALL

/* Webservice OpenAPI JSON */
SELECT
    ws.swagger_json AS uxon,
    'Web service' AS object_name,
    'OpenAPI.json' AS attribute_name,
    CONCAT(ws.name, ' [', ws.version, ']') AS instance_name,
    ws.alias AS instance_alias,
    ws.oid AS oid,
    'etl_webservice' AS table_name,
    ws.app_oid AS app_oid,
    'dataflow_webservice' AS component
FROM etl_webservice AS ws
WHERE ws.swagger_json IS NOT NULL;
