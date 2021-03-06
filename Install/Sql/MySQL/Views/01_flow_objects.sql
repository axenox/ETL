CREATE OR REPLACE VIEW etl_flow_objects AS
SELECT
	u.flow_oid,
	u.object_oid,
	ROUND(AVG(u.`level`)) AS `level`,
	CASE
		WHEN ROUND(AVG(u.`level`)) <= 1 THEN 'E'
		WHEN ROUND(AVG(u.`level`)) < 3 THEN 'T'
		WHEN ROUND(AVG(u.`level`)) >= 3 THEN 'L'
	END AS `type`
FROM 
(
	(
		SELECT 
			se.from_object_oid AS object_oid,
			se.flow_oid AS flow_oid,
			`type`,
			CASE se.`type`
				WHEN 'E' THEN 1
				WHEN 'T' THEN 3
				WHEN 'L' THEN 2
			END AS `level`
		FROM etl_step se
		WHERE se.from_object_oid IS NOT NULL
	)
	UNION ALL
	(
		SELECT 
			stl.to_object_oid AS object_oid,
			stl.flow_oid AS flow,
			stl.`type`,
			CASE stl.`type`
				WHEN 'E' THEN 2
				WHEN 'T' THEN 2
				WHEN 'L' THEN 3
			END AS `level`
		FROM etl_step stl
	) 
) u
GROUP BY u.flow_oid, u.object_oid;