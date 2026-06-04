<?php

namespace axenox\ETL\Mutations\Prototypes;

use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;

class InsertDataFlow
{
    use ImportUxonObjectTrait;
    
    private ?int $targetIndex = null;
    private ?string $aliasWithVersion = null;
    
    public static function fromUxon(UxonObject $uxonObject) : InsertDataFlow
    {
        $result = new InsertDataFlow();
        $result->importUxonObject($uxonObject);
        return $result;
    }
    
    public function getTargetIndex(): ?int
    {
        return $this->targetIndex;
    }

    /**
     * @uxon-property target_index
     * @uxon-type int
     * 
     * @param int|null $targetIndex
     * @return InsertDataFlow
     */
    public function setTargetIndex(?int $targetIndex): InsertDataFlow
    {
        $this->targetIndex = $targetIndex;
        return $this;
    }

    public function getAliasWithVersion(): ?string
    {
        return $this->aliasWithVersion;
    }

    /**
     * @uxon-property alias_with_version
     * @uxon-type metamodel:axenox.ETL.flow:alias_with_version
     *
     * @param string|null $aliasWithVersion
     * @return InsertDataFlow
     */
    public function setAliasWithVersion(?string $aliasWithVersion): InsertDataFlow
    {
        $this->aliasWithVersion = $aliasWithVersion;
        return $this;
    }
}