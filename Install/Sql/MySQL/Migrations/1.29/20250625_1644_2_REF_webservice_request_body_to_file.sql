-- UP

ALTER TABLE `etl_webservice_request` ADD `body_file` NVARCHAR(200);
UPDATE `etl_webservice_request` SET `body_file` = CONCAT(NOW(),"/", CONCAT('0x', LOWER(HEX(`oid`))), "/request.json");

/* 
Save contents of the `http_body` as files to the paths found in the column `body_file`.

@plugin.WriteSqlRowsToFiles(
	'SELECT http_body, body_file FROM etl_webservice_request WHERE http_body IS NOT NULL;',
	'axenox.ETL.webservice_request_storage',
	'body_file',
	'http_body'
);
*/

ALTER TABLE `etl_webservice_request` DROP COLUMN `http_body`;

-- DOWN

ALTER TABLE `etl_webservice_request` ADD `http_body` LONGTEXT;

/*
@plugin.WriteFilesToSqlRows(
    "SELECT body_file, CONCAT('0x', LOWER(HEX(`oid`))) as oid FROM etl_webservice_request WHERE body_file IS NOT NULL;",
    "UPDATE etl_webservice_request SET http_body = [#content#] WHERE CONCAT('0x', LOWER(HEX(`oid`))) = [#key#];",
    'body_file',
	'oid',
	'axenox.ETL.webservice_request_storage'
);
*/

ALTER TABLE `etl_webservice_request` DROP COLUMN `body_file`;

