<?php

namespace MacropaySolutions\CrufdWizardDecorator\Decorators;

interface ResourceDecoratorInterface
{
    public function getResourceMappings(): array;

    /**
     * NOT Including *-many type of relation
     */
    public function getRelationMappings(): array;

    public function decorate(array $resourceRepresentation, array $specialCases = []): array;

    /**
     * Decorate only the resource columns if they are set
     */
    public function internalDecorate(array $resourceRepresentation): array;

    public function decorateColumn(string $undecoratedColumn): ?string;

    /**
     * @throws \Exception
     */
    public function undecorateColumn(string $decoratedColumn): string;

    public function getWithRelations(): array;

    public function getCountRelations(): array;

    public function getExistRelations(): array;

    public function isRelationAggregate(string $columnName): bool;

    public function getResourceName(): string;
}
