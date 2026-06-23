<?php

namespace MacropaySolutions\CrufdWizardDecorator\Test;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use MacropaySolutions\CrufdWizard\Http\Controllers\ResourceControllerTrait;
use MacropaySolutions\CrufdWizard\Models\BaseModel;
use MacropaySolutions\CrufdWizard\Services\BaseResourceService;
use MacropaySolutions\CrufdWizardDecorator\Decorators\AbstractResourceDecorator;
use MacropaySolutions\CrufdWizardDecorator\Http\Middleware\Decorators\AbstractDecoratorMiddleware;
use PHPUnit\Framework\TestCase;

class ContractTest extends TestCase
{
    public static ?string $sql;
    public static ?array $bindings;
    public static ?BaseModel $model;

    public function testFilter(): void
    {
        $controller = new class () {
            use ResourceControllerTrait;

            public function __construct()
            {
                $this->init();
            }

            protected function setModelFqnToControllerMap(): void
            {
                $this->modelFqnToControllerMap = [];
            }

            protected function getPaginator(
                Relation|Builder $builder,
                array $allRequest
            ): LengthAwarePaginator|Paginator|CursorPaginator {
                ContractTest::$sql = $builder->toSql();
                ContractTest::$bindings = $builder->getBindings();

                // this will just return empty list
                throw new \Exception();
            }

            /**
             * @inheritDoc
             */
            protected function setResourceService(): void
            {
                $this->resourceService = new  class () extends BaseResourceService {
                    /**
                     * @inheritDoc
                     */
                    protected function setBaseModel(): void
                    {
                        $this->model = ContractTest::$model = new class () extends BaseModel {
                            protected bool $indexRequiredOnFiltering = false;
                            protected $fillable = [
                                'column1',
                                'column2',
                            ];
                            protected $table = 'test';

                            public function getColumns(bool $includingPrimary = true): array
                            {
                                return $includingPrimary ? ['id', 'column1', 'column2'] : ['column1', 'column2'];
                            }

                            protected function newBaseQueryBuilder(): \Illuminate\Database\Query\Builder
                            {
                                return (new Connection(new \PDO('sqlite::memory:')))->query();
                            }
                        };
                    }
                };
            }
        };

        $request = new class([
            'col1' => 5,
            'col2' => 3,
            'col3' => 3,
            'sort' => [['by' => 'col2'], ['by' => 'col1', 'dir' => 'ASC'], ['by' => 'col3', 'dir' => 'ASC']],
            'perPage' => 50,
        ]) extends Request {
            public function forceReplace(array $data): Request
            {
                /** @var Request $this */

                $this->query->replace();
                $this->request->replace();
                $this->replace($data);

                return $this;
            }
            public function forceOffsetUnset(string $offset): Request {
                /** @var Request $this */

                $this->query->remove($offset);
                $this->request->remove($offset);
                $this->offsetUnset($offset);

                return $this;
            }
        };

        $decorator = new class(ContractTest::$model) extends AbstractResourceDecorator {
            public function getResourceMappings(): array
            {
                return [
                    'column1' => 'col1',
                    'column2' => 'col2',
                ];
            }
        };
        $middleware = new class () extends AbstractDecoratorMiddleware {
            public function setResourceModel(): void
            {
                $this->resourceModel = ContractTest::$model;
            }
        };
        $middleware->setDecorator($decorator);
        $originalRequest = $request->all();
        $middleware->undecorateRequest($originalRequest, $request, 'list');
        self::assertInstanceOf(JsonResponse::class, $response = $controller->list($request));
        self::assertEquals([
            'has_more_pages' => false,
            'sums' => [],
            'avgs' => [],
            'mins' => [],
            'maxs' => [],
            'current_page' => 1,
            'data' => [],
            'from' => null,
            'last_page' => 1,
            'per_page' => 50,
            'to' => null,
            'total' => 0
        ], $response->getData(true));

        self::assertEquals(
            'select * from "test" where "column1" = ? and "column2" = ? order by "column2" desc, "column1" asc',
            static::$sql
        );
        static::$sql = null;
        self::assertEquals([5, 3], static::$bindings);
        static::$bindings = null;
    }
}
