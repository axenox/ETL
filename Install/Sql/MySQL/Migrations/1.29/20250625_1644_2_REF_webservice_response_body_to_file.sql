-- UP

ALTER TABLE `etl_webservice_response` ADD `body_file` NVARCHAR(200);
UPDATE `etl_webservice_response` SET `body_file` = CONCAT(NOW(),"/", CONCAT('0x', LOWER(HEX(`oid`))), "/response.json");

/* 
Save contents of the `response_body` as files to the paths found in the column `body_file`.

@plugin.WriteSqlRowsToFiles(
	'SELECT response_body, body_file FROM etl_webservice_response WHERE response_body IS NOT NULL;',
	'axenox.ETL.webservice_request_storage',
	'body_file',
	'response_body'
);
*/

ALTER TABLE `etl_webservice_response` DROP COLUMN `response_body`;

-- DOWN

ALTER TABLE `etl_webservice_response` ADD `response_body` LONGTEXT;

/*
@plugin.WriteFilesToSqlRows(
    "SELECT body_file, CONCAT('0x', LOWER(HEX(`oid`))) as oid FROM etl_webservice_response WHERE body_file IS NOT NULL;",
    "UPDATE etl_webservice_response SET response_body = [#content#] WHERE CONCAT('0x', LOWER(HEX(`oid`))) = [#key#];",
    'body_file',
	'oid',
	'axenox.ETL.webservice_request_storage'
);
*/

ALTER TABLE `etl_webservice_response` DROP COLUMN `body_file`;

