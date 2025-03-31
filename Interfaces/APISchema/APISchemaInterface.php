<?php
namespace axenox\ETL\Interfaces\APISchema;

use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use Psr\Http\Message\ServerRequestInterface;

interface APISchemaInterface extends WorkbenchDependantInterface
{
    public function getObjectSchema(MetaObjectInterface $object, string $customSchemaName = null) : APIObjectSchemaInterface;

    public function getRouteForRequest(ServerRequestInterface $request) : APIRouteInterface;

}