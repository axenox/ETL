<?php
namespace axenox\ETL\Interfaces\APISchema;

use exface\Core\Interfaces\Model\MetaObjectInterface;

interface APIRouteInterface
{
    public function getAPI() : APISchemaInterface;
    
    public function parseData(string $body, MetaObjectInterface $object) : ?array;
}