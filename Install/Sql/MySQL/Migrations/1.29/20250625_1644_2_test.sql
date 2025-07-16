-- UP

ALTER TABLE `etl_webservice_response` ADD `test` LONGTEXT;
/*
@plugin.WriteFilesToSqlRows(
    "SELECT body_file, CONCAT('0x', LOWER(HEX(`oid`))) as oid FROM etl_webservice_response WHERE body_file IS NOT NULL;",
    "UPDATE etl_webservice_response SET test = [#content#] WHERE CONCAT('0x', LOWER(HEX(`oid`))) = [#key#];",
    'body_file',
	'oid',
	'axenox.ETL.webservice_request_storage'
);
*/

ALTER TABLE `etl_webservice_response` DROP COLUMN `test`;

-- DOWN


