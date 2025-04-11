-- UP

ALTER TABLE etl_webservice_type
    ADD schema_class nvarchar(200);
GO;
UPDATE etl_webservice_type set schema_class = '\\axenox\\ETL\\Common\\OpenAPI\\OpenAPI3';

ALTER TABLE etl_webservice_type
    DROP COLUMN schema_json;
ALTER TABLE etl_webservice_type
    DROP COLUMN default_response_path;

-- DOWN

ALTER TABLE etl_webservice_type
    ADD schema_json nvarchar(max);
ALTER TABLE etl_webservice_type
    ADD default_response_path nvarchar(200);
