-- UP

-- Rename table
IF OBJECT_ID('dbo.etl_webservice_route', 'U') IS NOT NULL
BEGIN
    EXEC sp_rename 'dbo.etl_webservice_route', 'etl_webservice';
END;

-- Change url column
IF COL_LENGTH('etl_webservice', 'in_url') IS NOT NULL
BEGIN
    EXEC sp_rename 'etl_webservice.in_url', 'local_url', 'COLUMN';
END;

-- Change connection column
IF COL_LENGTH('etl_webservice', 'out_connection_oid') IS NOT NULL
BEGIN
    EXEC sp_rename 'etl_webservice.out_connection_oid', 'remote_connection_oid', 'COLUMN';
END;

-- Change direction to request direction
IF COL_LENGTH('etl_webservice', 'direction') IS NOT NULL
BEGIN 
    EXEC sp_rename 'etl_webservice.direction', 'request_direction', 'COLUMN';
END;
    
IF COL_LENGTH('etl_webservice', 'request_direction') IS NOT NULL
BEGIN 
    DECLARE @alterQuery NVARCHAR(MAX);
    SET @alterQuery = 
        'SET NOCOUNT ON; ALTER TABLE etl_webservice ALTER COLUMN request_direction nvarchar(10);';

    EXEC sp_executesql @alterQuery;
    
    DECLARE @updateQuery1 NVARCHAR(MAX);
    SET @updateQuery1 = 
        'SET NOCOUNT ON; UPDATE etl_webservice SET request_direction = ''Inbound'' WHERE request_direction = ''IN'';';

    DECLARE @updateQuery2 NVARCHAR(MAX);
    SET @updateQuery2 = 
        'SET NOCOUNT ON; UPDATE etl_webservice SET request_direction = ''Outbound'' WHERE request_direction = ''OUT'';';
        
    EXEC sp_executesql @updateQuery1;
    EXEC sp_executesql @updateQuery2;
END

-- Add flow direction
IF COL_LENGTH('etl_webservice', 'flow_direction') IS NULL
BEGIN 
    SET NOCOUNT ON; 
    ALTER TABLE etl_webservice ADD flow_direction NVARCHAR(3) NULL;
END
   
IF COL_LENGTH('etl_webservice', 'flow_direction') IS NOT NULL
BEGIN 
    DECLARE @updateQuery3 NVARCHAR(MAX);
    SET @updateQuery3 = 
        'SET NOCOUNT ON; UPDATE etl_webservice SET flow_direction = ''IN'' WHERE request_direction = ''Inbound'';';

    DECLARE @updateQuery4 NVARCHAR(MAX);
    SET @updateQuery4 = 
        'SET NOCOUNT ON; UPDATE etl_webservice SET flow_direction = ''OUT'' WHERE request_direction = ''Outbound'';';
        
    EXEC sp_executesql @updateQuery3;
    EXEC sp_executesql @updateQuery4;
    
    DECLARE @alterQuery2 NVARCHAR(MAX);
    SET @alterQuery2 = 
        'SET NOCOUNT ON; ALTER TABLE etl_webservice ALTER COLUMN flow_direction nvarchar(3) NOT NULL;';

    EXEC sp_executesql @alterQuery2;
END

-- DOWN

-- No changing back