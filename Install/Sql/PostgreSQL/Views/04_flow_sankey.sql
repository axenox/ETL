CREATE OR REPLACE VIEW etl_flow_sankey AS
SELECT
    source_level,
    target_level,
    source_type,
    source_name,
    name,
    target_type,
    target_name,
    source_object_oid,
    source_oid,
    target_object_oid,
    target_oid,
    step_oid,
    flow_oid
FROM
    (
        (
            SELECT
                fof.level AS source_level,
                fot.level AS target_level,
                s.from_object_oid AS source_object_oid,
                s.from_object_oid AS source_oid,
                'object' AS source_type,
                CONCAT(fofo.object_name, ' [', fofo.object_alias, ']') AS source_name,
                s.to_object_oid AS target_object_oid,
                s.to_object_oid AS target_oid,
                'object' AS target_type,
                CONCAT(foto.object_name, ' [', foto.object_alias, ']') AS target_name,
                s.name,
                s.oid AS step_oid,
                s.flow_oid
            FROM etl_step s
                     INNER JOIN etl_flow_objects fof ON s.flow_oid = fof.flow_oid
                AND fof.object_oid = s.from_object_oid
                     INNER JOIN etl_flow_objects fot ON s.flow_oid = fot.flow_oid
                AND fot.object_oid = s.to_object_oid
                     LEFT JOIN exf_object fofo ON fofo.oid = fof.object_oid
                     LEFT JOIN exf_object foto ON foto.oid = fot.object_oid
        )
        /* 
         Add connection for sending HTTP requests 
         */
        UNION ALL
        (
            SELECT
                fot.level AS source_level,
                fot.level + 1 AS target_level,
                s.to_object_oid AS source_object_oid,
                s.to_object_oid AS source_oid,
                'object' AS source_type,
                CONCAT(foto.object_name, ' [', foto.object_alias, ']') AS source_name,
                '11eeae4695964d78ae46025041000001' AS target_object_oid, -- UID of the object axenox.ETL.webservice_request
                wf.oid AS target_oid,
                'webservice_flow' AS target_type,
                /* Names must be unique for every source_oid, so we need all information about the web service */
                /* The spaces make sure the chart can split long URLs in multiple lines*/
                CONCAT('Web service: /', ws.local_url, ' /', (CASE WHEN COALESCE(ws.version, '') != '' THEN CONCAT(ws.version, ' /') ELSE '' END), wf.route) AS target_name,
                'Send HTTP Response' AS name,
                NULL AS step_oid,
                fot.flow_oid AS flow_oid
            FROM
                etl_step s
                    INNER JOIN etl_webservice_flow wf ON wf.flow_oid = s.flow_oid
                    INNER JOIN etl_webservice ws ON ws.oid = wf.webservice_oid
                    AND ws.flow_direction = 'OUT'
                    INNER JOIN etl_flow_objects fot ON s.flow_oid = fot.flow_oid
                    AND fot.object_oid = s.to_object_oid
                    LEFT JOIN exf_object foto ON foto.oid = fot.object_oid
            WHERE
                fot.level = (SELECT MAX(fo1.level) FROM etl_flow_objects fo1 WHERE fot.flow_oid = fo1.flow_oid)

        )
        /* 
         Add connections for "receiving an HTTP request" placing it into the request log 
        */
        UNION ALL
        (
            SELECT
                fof.level - 1 AS source_level,
                fof.level AS target_level,
                '11eeae4695964d78ae46025041000001' AS source_object_oid, -- UID of the object axenox.ETL.webservice_request
                wf.oid AS source_oid,
                'webservice_flow' AS source_type,
                /* Names must be unique for every source_oid, so we need all information about the web service */
                /* The spaces make sure the chart can split long URLs in multiple lines*/
                CONCAT('Web service: /', ws.local_url, ' /', (CASE WHEN COALESCE(ws.version, '') != '' THEN CONCAT(ws.version, ' /') ELSE '' END), wf.route) AS source_name,
                s.from_object_oid AS target_object_oid,
                s.from_object_oid AS target_oid,
                'object' AS target_type,
                /* Need the name of the object axenox.ETL.webservice_request here for the sankey chart to work (it uses these names to connect piers) */
                CONCAT(fofo.object_name, ' [', fofo.object_alias, ']') AS target_name,
                'Recieve HTTP Request' AS name,
                NULL AS step_oid,
                fof.flow_oid AS flow_oid
            FROM
                (etl_step s
                    INNER JOIN etl_webservice_flow wf ON wf.flow_oid = s.flow_oid
                    INNER JOIN etl_webservice ws ON ws.oid = wf.webservice_oid
                    AND ws.flow_direction = 'IN')
                    INNER JOIN etl_flow_objects fof ON s.flow_oid = fof.flow_oid
                    AND fof.object_oid = s.from_object_oid
                    LEFT JOIN exf_object fofo ON fofo.oid = fof.object_oid
            WHERE
                fof.level = (SELECT MIN(fo1.level) FROM etl_flow_objects fo1 WHERE fof.flow_oid = fo1.flow_oid)
              AND s.from_object_oid != '11ef97055df83b389705025041000001'

        )
        /* 
         Add connections for "uploading a file" to the upload file log.
        This is very similar to receiving web service requests.
         */
        UNION ALL
        (
            SELECT
                fof.level - 1 AS source_level,
                fof.level AS target_level,
                '11ef97055df83b389705025041000001' AS source_object_oid, -- UID of the object axenox.ETL.file_upload
                ff.oid AS source_oid,
                'upload_flow' AS source_type,
                CONCAT('File upload [', ff.name, ']') AS source_name,
                s.from_object_oid AS target_object_oid,
                s.from_object_oid AS target_oid,
                'object' AS target_type,
                /* Need the name of the object axenox.ETL.file_upload here for the sankey chart to work (it uses these names to connect piers) */
                CONCAT(fofo.object_name, ' [', fofo.object_alias, ']') AS target_name,
                'Upload file' AS name,
                NULL AS step_oid,
                fof.flow_oid AS flow_oid
            FROM
                etl_step s
                    INNER JOIN etl_file_flow ff ON ff.flow_oid = s.flow_oid
                    INNER JOIN etl_flow_objects fof ON s.flow_oid = fof.flow_oid
                    AND fof.object_oid = s.from_object_oid
                    LEFT JOIN exf_object fofo ON fofo.oid = fof.object_oid
            WHERE
                fof.level = (SELECT MIN(fo1.level) FROM etl_flow_objects fo1 WHERE fof.flow_oid = fo1.flow_oid)
              AND s.from_object_oid = '11ef97055df83b389705025041000001'

        )
    ) sankeydata
-- WHERE flow_oid IN ('11ef97c581880b0497c5005056bef75d', '11efb6ece1e50d36b6ec005056bef75d')