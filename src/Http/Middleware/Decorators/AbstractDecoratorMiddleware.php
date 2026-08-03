<?php

namespace MacropaySolutions\CrufdWizardDecorator\Http\Middleware\Decorators;

use Closure;
use MacropaySolutions\CrufdWizard\Helpers\GeneralHelper;
use MacropaySolutions\CrufdWizard\Models\BaseModel;
use MacropaySolutions\CrufdWizardDecorator\Decorators\AbstractResourceDecorator;
use MacropaySolutions\CrufdWizardDecorator\Support\Response\ResponseBuilder;
use MacropaySolutions\Kernel\Http\Base\BinaryFileResponse;
use MacropaySolutions\Kernel\Http\Base\StreamedResponse;
use MacropaySolutions\Kernel\Http\JsonResponse;
use MacropaySolutions\Kernel\Http\Request;

abstract class AbstractDecoratorMiddleware
{
    public const METHODS = [
        'list',
        'create',
        'get',
        'getRelated',
        'update',
        'updateRelated',
        'delete',
        'deleteRelated',
    ];
    public const WITH_RELATIONS = 'withRelations';
    public const WITH_RELATIONS_COUNT = 'withRelationsCount';
    public const WITH_RELATIONS_EXISTENCE = 'withRelationsExistence';
    protected string $decoratorClass;
    protected BaseModel $resourceModel;
    protected BaseModel $relatedModel;
    /**
     * @var array define the relation name as key and the relation decorator FQM as value
     */
    protected array $relatedDecoratorClassMap = [];
    protected AbstractResourceDecorator $decorator;
    protected bool $isUpsert;
    protected bool $isList;
    private array $upsertFailed = [];

    abstract public function setResourceModel(): void;

    public function setDecorator(AbstractResourceDecorator $decorator): void
    {
        $this->decorator = $decorator;
    }

    /**
     * @return JsonResponse|BinaryFileResponse|StreamedResponse|mixed
     * @throws \Exception
     */
    public function handle(
        Request $request,
        Closure $next,
        string $method
    ): mixed {
        if (!\in_array($method, static::METHODS, true)) {
            throw new \Exception('Development error: method not in: ' . \implode(', ', static::METHODS));
        }

        $this->setResourceModel();

        if (
            \in_array($method, ['getRelated', 'updateRelated'], true)
            && '' !== ($relation = (string)$request->route('relation'))
        ) {
            if ('' === ($decoratorClass = $this->relatedDecoratorClassMap[$relation] ?? '')) {
                throw new \Exception(
                    'Development error: Decorator not mapped for relation: ' .
                    $relation . ' of resource: ' . $this->resourceModel::resourceName()
                );
            }

            $this->relatedModel = $this->resourceModel->{$relation}()->getRelated();
        }

        $this->setDecorator(GeneralHelper::app(
            $decoratorClass ?? $this->decoratorClass,
            ['resourceModel' => $this->relatedModel ?? $this->resourceModel]
        ));
        $originalRequest = $request->all();
        $this->undecorateRequest($originalRequest, $request, $method);
        $request->headers->set(
            GeneralHelper::JSON_RESPONSE_AS_ARRAY_FOR_DECORATION_IN_REQUEST_ATTRIBUTES,
            \hash_hmac('sha256', GeneralHelper::JSON_RESPONSE_AS_ARRAY, \config('app.key'))
        );

        $response = $this->executeRequest($next, $request, $method);

        /** @var ResponseBuilder $responseBuilder */
        $responseBuilder = GeneralHelper::app(ResponseBuilder::class);

        if (!$response instanceof JsonResponse) {
            if ($response instanceof BinaryFileResponse) {
                if (
                    $request->header('Accept') === 'application/xls'
                    && $this->isUserAllowedToDownloadXls()
                ) {
                    return $response;
                }
            }

            return $response;
        }

        return $this->decorateResponse($response, $request, $originalRequest, $next, $responseBuilder);
    }

    public function undecorateRequest(
        array $originalRequest,
        Request $request,
        string $method
    ): void {
        $this::removeForbiddenFieldsFromRequest($request);

        $this->isUpsert = false;
        $this->isList = false;

        if (\in_array($method, ['get', 'getRelated'], true)) {
            $request->merge([
                self::WITH_RELATIONS => $this->decorator->getWithRelations(),
                self::WITH_RELATIONS_COUNT => $this->decorator->getCountRelations(),
                self::WITH_RELATIONS_EXISTENCE => $this->decorator->getExistRelations(),
            ]);

            return;
        }

        if ($method === 'list') {
            $this->undecorateList($originalRequest, $request);
            $this->isList = true;

            return;
        }

        if (\in_array($method, ['update', 'updateRelated', 'create'], true)) {
            $this->undecorateUpsert($originalRequest, $request, $method);
            $this->isUpsert = true;
        }
    }

    /**
     * @throws \Exception
     */
    public function decorateResponse(
        JsonResponse $response,
        Request $request,
        array $originalRequest,
        Closure $next,
        ResponseBuilder $responseBuilder
    ): JsonResponse|StreamedResponse|BinaryFileResponse {
        $statusCode = $response->getStatusCode();

        if ($statusCode === 204) {
            return $responseBuilder->respondSuccess(code: $statusCode);
        }

        $content = (array)($request->attributes->get(GeneralHelper::JSON_RESPONSE_AS_ARRAY) ??
            $response->getData(true));
        $request->attributes->remove(GeneralHelper::JSON_RESPONSE_AS_ARRAY);
        $request->headers->remove(GeneralHelper::JSON_RESPONSE_AS_ARRAY_FOR_DECORATION_IN_REQUEST_ATTRIBUTES);

        if ($statusCode === 202) {
            return $responseBuilder->respondSuccess($content, $statusCode);
        }

        if ($this->isUpsert) {
            if (!\str_starts_with((string)$statusCode, '2')) {
                return $this->upsertResponseWithDecoratedErrors($responseBuilder, $statusCode);
            }

            return $responseBuilder->respondSuccess($this->decorator->decorate($content), $statusCode);
        }

        if (!\str_starts_with((string)$statusCode, '2')) {
            return $responseBuilder->respondError(
                $content['message'] ?? 'Error',
                null,
                $statusCode
            );
        }

        if (!$this->isList) {
            return $responseBuilder->respondSuccess($this->decorator->decorate($content), $statusCode);
        }

        return $responseBuilder->respondSuccess($this->getListNewContent($request, $content), $statusCode);
    }

    public static function removeForbiddenFieldsFromRequest(Request $request): void
    {
        foreach (
            [
                self::WITH_RELATIONS,
                self::WITH_RELATIONS_COUNT,
                self::WITH_RELATIONS_EXISTENCE,
            ] as $key
        ) {
            $request->forceOffsetUnset($key);
        }

        $request->headers->remove(GeneralHelper::JSON_RESPONSE_AS_ARRAY_FOR_DECORATION_IN_REQUEST_ATTRIBUTES);
    }

    /**
     * @throws \Exception
     */
    protected function getListNewContent(Request $request, array $content, array $columns = [], $isCsv = false): array
    {
        $newContent = $content;
        $newContent['data'] = [];

        foreach (($content['data'] ?? []) as $row) {
            $decoratedRow = $this->decorator->decorate((array)$row);
            $decoratedRow[AbstractResourceDecorator::PKI] ??= AbstractResourceDecorator::applyHtmlSpecialChars(
                ($onePk ??= \count($pks ??= $this->getPks()) === 1) ?
                    $row[$firstPk ??= \reset($pks)] ?? null :
                    \implode(
                        ($this->relatedModel ?? $this->resourceModel)::COMPOSITE_PK_SEPARATOR,
                        \array_map(fn(string $pk): mixed => $row[$pk] ?? '', $pks)
                    )
            );
            $newContent['data'][] = $decoratedRow;
        }

        if (isset($newContent['index_required_on_filtering'])) {
            $indexedSortable = $this->decorator->decorateSortableIndexes($newContent);
            unset($newContent['index_required_on_filtering']);
        }

        $newContent['filterable'] = $this->decorator->getFilters();
        $newContent['sortable'] = \array_merge(
            $indexedSortable ?? $this->decorator->getFilters(),
            \array_map(fn(string $rel): string => $rel .
                $this->decorator::EXIST_SUFFIX, $this->decorator->getExistRelations()),
            \array_map(fn(string $rel): string => $rel .
                $this->decorator::COUNT_SUFFIX, $this->decorator->getCountRelations())
        );

        return $newContent;
    }

    /**
     * Overwrite this if needed
     */
    protected function isUserAllowedToDownloadXls(): bool
    {
        return false;
    }

    protected function undecorateSort(mixed $value): array
    {
        $undecorated = [];

        foreach ((array)$value as $index => $sort) {
            if (!\is_string($sort['by'] ?? [])) {
                continue;
            }

            if ('' === $sort['by']) {
                $undecorated[$index]['by'] = $sort['by'];

                continue;
            }

            if (isset($this->decorator->cachedFlippedResourceMappings[$sort['by']])) {
                $undecorated[$index]['by'] = $this->decorator->cachedFlippedResourceMappings[$sort['by']];

                if (\in_array($sort['dir'] ?? null, ['asc', 'ASC', 'desc', 'DESC'], true)) {
                    $undecorated[$index]['dir'] = \strtoupper($sort['dir']);
                }

                continue;
            }

            if (!$this->decorator->isRelationAggregate($sort['by'])) {
                continue;
            }

            $undecorated[$index]['by'] = $sort['by'];

            if (\in_array($sort['dir'] ?? null, ['asc', 'ASC', 'desc', 'DESC'], true)) {
                $undecorated[$index]['dir'] = \strtoupper($sort['dir']);
            }
        }

        return $undecorated;
    }

    protected function undecorateList(array $originalRequest, Request $request): void
    {
        $decoratedRequest = [];

        foreach ($originalRequest as $key => $value) {
            if (
                \in_array(
                    $key,
                    [
                        'page',
                        'logError',
                        'sqlDebug',
                        'simplePaginate',
                        'cursor',
                    ],
                    true
                )
            ) {
                $decoratedRequest[$key] = $value;

                continue;
            }

            if ($key === 'perPage') {
                continue;
            }

            if ($key === 'sort') {
                $decoratedRequest[$key] = $this->undecorateSort($value);

                continue;
            }

            if (isset($this->decorator->cachedFlippedResourceMappings[$key])) {
                $decoratedRequest[$this->decorator->cachedFlippedResourceMappings[$key]] = $value;
            }
        }

        $decoratedRequest = \array_merge($decoratedRequest, [
            self::WITH_RELATIONS => $this->decorator->getWithRelations(),
            self::WITH_RELATIONS_COUNT => $this->decorator->getCountRelations(),
            self::WITH_RELATIONS_EXISTENCE => $this->decorator->getExistRelations(),
        ]);

        if (\is_numeric($originalRequest['perPage'] ?? '')) {
            $decoratedRequest['limit'] = $originalRequest['perPage'];
        }

        $request->forceReplace($decoratedRequest);
    }

    protected function undecorateUpsert(array $originalRequest, Request $request, string $method): void
    {
        $undecoratedRequest = [];

        foreach ($originalRequest as $key => $value) {
            if (isset($this->decorator->cachedFlippedResourceMappings[$key])) {
                $undecoratedRequest[$this->decorator->cachedFlippedResourceMappings[$key]] = $value;

                continue;
            }

            if (\in_array($key, ['logError', 'sqlDebug'], true)) {
                $undecoratedRequest[$key] = $value;
            }
        }

        $request->forceReplace($undecoratedRequest);
    }

    protected function executeRequest(Closure $next, Request $request, string $method): mixed
    {
        if (!\in_array($method, ['update', 'updateRelated'], true)) {
            return $next($request);
        }

        $result = $next($request);

        if (
            !$result instanceof JsonResponse
            || !\str_starts_with((string)$result->getStatusCode(), '2')
        ) {
            $this->upsertFailed[''] = $result;
        }

        return $result;
    }

    protected function upsertResponseWithDecoratedErrors(
        ResponseBuilder $responseBuilder,
        int $statusCode
    ): JsonResponse
    {
        $upsertFailed = $this->upsertFailed;
        $this->upsertFailed = [];

        return $responseBuilder->respondError(
            ResponseBuilder::THE_GIVEN_DATA_WAS_INVALID,
            $this->decorateUpsertErrors($upsertFailed),
            $statusCode
        );
    }

    protected function decorateUpsertErrors(array $upsertFailedForRelations): array
    {
        $decorated = [];

        foreach ($upsertFailedForRelations as $failedResult) {
            if (!$failedResult instanceof JsonResponse) {
                continue;
            }

            $content = (array)$failedResult->getData(true);

            return $this->decorator->internalDecorate($content['errors'] ?? []);
        }

        return $decorated;
    }

    /**
     * @throws \Exception
     */
    protected function getPks(): array
    {
        $pks = [];

        foreach (($this->relatedModel ?? $this->resourceModel)->getPrimaryKeyFilter() as $column => $value) {
            if (!\is_array($value) && \is_string($column)) {
                $pks[] = $column;

                continue;
            }

            $pks[] = (string)\reset($value);
        }

        return $pks;
    }
}
