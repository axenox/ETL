<?php
namespace axenox\ETL;

use exface\Core\Interfaces\InstallerInterface;
use exface\Core\CommonLogic\Model\App;
use exface\Core\CommonLogic\AppInstallers\AbstractSqlDatabaseInstaller;

class ETLApp extends App
{
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\App::getInstaller()
     */
    public function getInstaller(InstallerInterface $injected_installer = null)
    {
        $installer = parent::getInstaller($injected_installer);
        
        $modelLoader = $this->getWorkbench()->model()->getModelLoader();
        $modelDataSource = $modelLoader->getDataConnection();
        $installerClass = get_class($modelLoader->getInstaller()->getInstallers()[0]);
        $schema_installer = new $installerClass($this->getSelector());
        if ($schema_installer instanceof AbstractSqlDatabaseInstaller) {
            $schema_installer
            ->setFoldersWithMigrations(['InitDB','Migrations'])
            ->setDataConnection($modelDataSource)
            ->setFoldersWithStaticSql(['Views'])
            ->setMigrationsTableName('_migrations_etl');
        }
        $installer->addInstaller($schema_installer); 
        
        return $installer;
    }
}