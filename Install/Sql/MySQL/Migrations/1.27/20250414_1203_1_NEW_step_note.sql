-- UP

CREATE TABLE IF NOT EXISTS `etl_step_note` (
  `oid` binary(16) NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) NOT NULL,
  `modified_by_user_oid` binary(16) NOT NULL,
  `flow_run_oid` binary(16) DEFAULT NULL,
  `step_run_oid` binary(16) DEFAULT NULL,
  `message` varchar(400),
  `log_level` varchar(20) DEFAULT NULL,
  `exception_flag` tinyint DEFAULT NULL,
  `exception_message` varchar(400) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
  `exception_log_id` varchar(10) DEFAULT NULL,
  `count_reads` int DEFAULT NULL,
  `count_writes` int DEFAULT NULL,
  `count_creates` int DEFAULT NULL,
  `count_updates` int DEFAULT NULL,
  `count_deletes` int DEFAULT NULL,
  `count_errors` int DEFAULT NULL,
  `count_warnings` int DEFAULT NULL,
  PRIMARY KEY (`oid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 ROW_FORMAT=DYNAMIC;

-- DOWN

-- Do not drop the notes table, keep the data!