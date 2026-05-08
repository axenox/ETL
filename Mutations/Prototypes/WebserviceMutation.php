<?php

namespace axenox\ETL\Mutations\Prototypes;

use axenox\ETL\Interfaces\APISchema\APISchemaInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\Mutations\AppliedMutationInterface;
use exface\Core\Mutations\AppliedMutationOnArray;
use exface\Core\Mutations\Prototypes\GenericUxonMutation;

/**
 * Mutation for webservice schemas with optional facade configuration updates.
 *
 * This mutation applies inherited JSONPath-based UXON operations (`change`, `append`,
 * `insert`, `replace`, `remove`, `move`) to the OpenAPI schema and can additionally
 * update facade options via `change_facade_options`.
 *
 * Supported subject type: `\axenox\ETL\Interfaces\APISchema\WebserviceInterface`.
 *
 * ## Examples
 *
 * ### Change OpenAPI metadata
 *
 * ```json
 * {
 *   "change": {
 *     "$.info.title": "Orders API",
 *     "$.info.version": "2.1.0"
 *   }
 * }
 * ```
 *
 * ### Change OpenAPI and facade options together
 *
 * ```json
 * {
 *   "change": {
 *     "$.info.description": "Updated by mutation"
 *   },
 *   "change_facade_options": {
 *     "validation": {
 *       "verbose": true
 *     }
 *   }
 * }
 * ```
 */
class WebserviceMutation extends GenericUxonMutation
{
    private ?UxonObject $changeFacadeOptions = null;

    /**
     * {@inheritdoc}
     */
    public function apply($subject): AppliedMutationInterface
    {
        if (! $this->supports($subject)) {
            throw new InvalidArgumentException(
                'Cannot apply OpenAPI 3 mutation to ' . get_class($subject)
                . ' - subject must implement WebserviceInterface!'
            );
        }

        // Mutate the OpenAPI JSON.
        $schemaUxon = $subject->exportUxonObject();
        $applied = parent::apply($schemaUxon);
        $subject->importUxonObject($schemaUxon);
        
        $stateBefore['openAPI'] = $applied->dumpStateBefore();
        $stateAfter['openAPI'] = $applied->dumpStateAfter();

        // Mutate facade options.
        if($this->changeFacadeOptions !== null) {
            $facade = $subject->getWebserviceInfo()->getFacade();
            // TODO AbstractHTTPFacade::exportUxonObject
            $stateBefore['facade_options'] = $facade->exportUxonObject()->toArray();
            $facade->importUxonObject($this->changeFacadeOptions);
            $stateAfter['facade_options'] = $facade->exportUxonObject()->toArray();
        }
        
        return new AppliedMutationOnArray($this, $subject, $stateBefore, $stateAfter);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($subject): bool
    {
        return $subject instanceof APISchemaInterface;
    }

    /**
     * Applies facade option changes for the webservice facade configuration.
     *
     * @uxon-property change_facade_options
     * @uxon-type \axenox\ETL\Facades\DataFlowFacade
     * @uxon-template {"validation": {"verbose": false}}
     *
     * @param UxonObject $value
     * @return $this
     */
    protected function setChangeFacadeOptions(UxonObject $value): WebserviceMutation
    {
        $this->changeFacadeOptions = $value;
        return $this;
    }

    /**
     * @return UxonObject|null
     */
    protected function getChangeFacadeOptions(): ?UxonObject
    {
        return $this->changeFacadeOptions;
    }
}

