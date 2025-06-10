<?php
namespace axenox\ETL\Interfaces\APISchema;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use Psr\Http\Message\ServerRequestInterface;
use Stringable;

interface APISchemaInterface extends WorkbenchDependantInterface, iCanBeConvertedToUxon, Stringable
{
    public function getObjectSchema(MetaObjectInterface $object, string $customSchemaName = null) : APIObjectSchemaInterface;

    public function getRouteForRequest(ServerRequestInterface $request) : APIRouteInterface;

    /**
     * Get the validated version of the schema ot be published to external partners
     * 
     * @param string $baseUrl
     * @return void
     */
    public function publish(string $baseUrl) : string;
}