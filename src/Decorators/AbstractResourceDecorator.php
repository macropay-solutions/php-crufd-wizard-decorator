<?php

namespace MacropaySolutions\CrufdWizardDecorator\Decorators;

use Illuminate\Support\Str;
use MacropaySolutions\CrufdWizard\Models\BaseModel;

abstract class AbstractResourceDecorator implements ResourceDecoratorInterface
{
    public const COUNT_SUFFIX = '_count';
    public const EXIST_SUFFIX = '_exist';
    public const PKI = 'pki';

    /**
     * The list of relations for which a count aggregate is desired
     */
    public array $countRelations = [];

    /**
     * The list of relations for which an exist aggregate is desired
     */
    public array $existRelations = [];

    public readonly array $cachedFlippedResourceMappings;

    public function __construct(
        protected BaseModel $resourceModel
    ) {
        $this->cachedFlippedResourceMappings = \array_flip($this->getResourceMappings());
    }

    public function getResourceName(): string
    {
        return $this->resourceModel::resourceName();
    }

    public function decorateSortableIndexes(array $listResponse): array
    {
        $indexedSortable = [];

        foreach ((array)$listResponse['index_required_on_filtering'] as $undecoratedSortable) {
            $decoratedSortable = $this->decorateColumn($undecoratedSortable);

            if (null !== $decoratedSortable) {
                $indexedSortable[] = $decoratedSortable;
            }
        }

        return $indexedSortable;
    }

    public function getFilters(): array
    {
        return \array_values($this->getResourceMappings());
    }

    public function decorate(array $resourceRepresentation, array $specialCases = []): array
    {
        $decorated = [];
        $decoratedKeys = [];

        foreach ($this->getResourceMappings() as $undecoratedKey => $decoratedKey) {
            $decorated[$decoratedKey] = static::applyHtmlSpecialChars(
                $resourceRepresentation[$undecoratedKey] ?? null
            );
            $decoratedKeys[$undecoratedKey] = true;
        }

        $decorated = $this->decorateRelationAggregations(
            \array_diff_key($resourceRepresentation, $decoratedKeys),
            $decorated
        );

        return $this->decorateUnGrouped($specialCases, $resourceRepresentation, $decorated);
    }

    /**
     * @inheritDoc
     */
    public function internalDecorate(array $resourceRepresentation): array
    {
        $decorated = [];

        foreach ($this->getResourceMappings() as $undecoratedKey => $decoratedKey) {
            if (\array_key_exists($undecoratedKey, $resourceRepresentation)) {
                $decorated[$decoratedKey] = static::applyHtmlSpecialChars(
                    $resourceRepresentation[$undecoratedKey]
                );
            }
        }

        return $decorated;
    }

    public function decorateColumn(string $undecoratedColumn): ?string
    {
        return $this->getResourceMappings()[$undecoratedColumn] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function undecorateColumn(string $decoratedColumn): string
    {
        return $this->cachedFlippedResourceMappings[$decoratedColumn] ??
            throw new \Exception('Column mapping not found for ' . $decoratedColumn);
    }

    public function getResourceMappings(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getRelationMappings(): array
    {
        return [];
    }

    public function getWithRelations(): array
    {
        return \array_values(\array_keys($this->getRelationMappings()));
    }

    public function getCountRelations(): array
    {
        return $this->countRelations;
    }

    public function getExistRelations(): array
    {
        return $this->existRelations;
    }

    protected function handleRelationName(string $relation): string
    {
        return $this->resourceModel::$snakeAttributes ? Str::snake($relation) : $relation;
    }

    protected function handleSpecialCases(array $specialCases, array $resourceRepresentation, array $decorated): array
    {
        foreach ($specialCases as $specialCase) {
            if (isset($resourceRepresentation[$specialCase])) {
                $decorated[$specialCase] ??= static::applyHtmlSpecialChars($resourceRepresentation[$specialCase]);
            }
        }

        return $decorated;
    }

    protected function decorateRelationAggregations(array $resourceRepresentation, array $decorated): array
    {
        foreach ($resourceRepresentation as $key => $value) {
            if (\is_array($value)) {
                continue;
            }

            if ($this->isRelationAggregate((string)$key)) {
                $decorated[$key] = static::applyHtmlSpecialChars($value);
            }
        }

        return $decorated;
    }

    protected function decorateUnGrouped(array $specialCases, array $resourceRepresentation, array $decorated): array
    {
        foreach ($this->getRelationMappings() as $relation => $columnMapping) {
            $handleRelationName = $this->handleRelationName($relation);

            if (!\array_key_exists($handleRelationName, $resourceRepresentation)) {
                continue;
            }

            $relationValue = $resourceRepresentation[$handleRelationName] ?? null;

            foreach ($columnMapping as $relationUndecoratedKey => $decoratedKey) {
                if ($relationValue === null) {
                    $decorated[$decoratedKey] = null;

                    continue;
                }

                if (
                    \array_key_exists(
                        $relationUndecoratedKey,
                        $relationValue = ($resourceRepresentation[$handleRelationName] ?? [])
                    )
                ) {
                    $decorated[$decoratedKey] = static::applyHtmlSpecialChars(
                        $relationValue[$relationUndecoratedKey]
                    );
                }
            }
        }

        $decorated[self::PKI] = static::applyHtmlSpecialChars(
            $resourceRepresentation['primary_key_identifier'] ?? null
        );

        return $this->handleSpecialCases($specialCases, $resourceRepresentation, $decorated);
    }

    public static function applyHtmlSpecialChars(mixed $value): mixed
    {
        return \is_string($value) ? \htmlspecialchars($value, ENT_NOQUOTES) : $value;
    }

    /**
     * {relationName}_count {relationName}_exist
     */
    public function isRelationAggregate(string $columnName): bool
    {
        $suffix = \substr($columnName, -6);
        $relationName = \substr($columnName, 0, -6);

        return (
                \in_array($suffix, [static::COUNT_SUFFIX, static::EXIST_SUFFIX], true)
                && isset($this->getRelationMappings()[$relationName])
            ) || (
                $suffix === static::COUNT_SUFFIX
                && \in_array($relationName, $this->getCountRelations(), true)
            ) || (
                $suffix === static::EXIST_SUFFIX
                && \in_array($relationName, $this->getExistRelations(), true)
            );
    }
}
