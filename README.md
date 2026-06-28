# php-crufd-wizard-decorator - RetrieveQL

[![Build Status](https://github.com/macropay-solutions/php-crufd-wizard-decorator/actions/workflows/tests.yml/badge.svg)](https://github.com/macropay-solutions/php-crufd-wizard-decorator/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/macropay-solutions/php-crufd-wizard-decorator)](https://packagist.org/packages/macropay-solutions/php-crufd-wizard-decorator)
[![Latest Stable Version](https://img.shields.io/packagist/v/macropay-solutions/php-crufd-wizard-decorator)](https://packagist.org/packages/macropay-solutions/php-crufd-wizard-decorator)
[![License](https://img.shields.io/packagist/l/macropay-solutions/php-crufd-wizard-decorator)](https://packagist.org/packages/macropay-solutions/php-crufd-wizard-decorator)


This can be used for decorating [php-crufd-wizard](https://github.com/macropay-solutions/php-crufd-wizard)

## Decorator High Level Comparison: Freemium vs Pro

The Decorator suite acts as the presentation layer for your microservices, bridging the gap between your internal database structure and your public API contract.

| Feature | Decorator Freemium (`php-crufd-wizard-decorator`) | Decorator Pro (`php-rest-wizard-decorator`)                                                |
| :--- | :--- |:-------------------------------------------------------------------------------------------|
| **Schema Masking** | ✅ Rename/Map resource & relation columns | ✅ Rename/Map resource & relation columns                                                   |
| **Relation Wrapping** | ✅ Decorate attributes of loaded relations | ✅ Decorate attributes of loaded relations                                                  |
| **Data Flattening** | ✅ **Single Table Flattening** (Merge resource + relations into one flat response) | ✅ **Single Table Flattening** (Merge resource + relations into one flat response)          |
| **Virtual Columns** | ❌ | ✅ **Composed Columns** (Create new fields from multiple resource/relation columns)         |
| **Memory-Safe Export** | ❌ | ✅ **Streamed CSV Download** (Direct to client; no file saved on server)                    |
| **Filter Transformation** | ❌ | ✅ **Query Rewriting** (Map resource columns to relation filters via URL query string)      |
| **Response Control** | ✅ Standard JSON decoration | ✅ **Restricted Columns** (Return only requested keys, including in CSV streams)            |
| **Relational Updates** | ❌ | ✅ **One-to-One Update** (Update related models during resource update)                     |
| **Aggregations** | ❌ | ✅ **Resource & Relation Aggregations** (Sums, Avgs, etc. on children) from php-rest-wizard |
| **Security (XSS)** | ✅ **Auto-Sanitization** (All JSON string values parsed by `htmlspecialchars`) | ✅ **Auto-Sanitization** (All JSON string values parsed by `htmlspecialchars`)              |

---

### Key Advantages of the Pro Decorator Suite

#### Zero-Footprint CSV Streaming
The Pro decorator can stream millions of rows directly to the client's browser without ever saving a temporary file on the server. This bypasses PHP `memory_limit` crashes and eliminates "Disk Full" errors during large exports.

#### Composed & Flattened Data
Build high-performance frontend tables with ease. Pro allows you to merge data from multiple relations and compose new virtual columns (e.g., calculating `margin` from `cost` and `price` fields across different tables) on the fly.

#### Relation Filter Transformation
Eases the complexity of URL queries. Users can filter by a "public" mapped column name, and the engine automatically transforms it into the correct internal relational logic (joins/where clauses) behind the scenes.

#### Atomic One-to-One Updates
Maintain data integrity without extra controller boilerplate. When you update a main resource, the Pro decorator handles the simultaneous update of any associated one-to-one relations in a single, clean operation.

### AI Integration Note
If you are using the PHP Framework `.cursorrules` configuration, use the `{--decorated}` flag with the generator:
```bash
php run make:api-resource {resourceName} --decorated
```

It renames/maps the column names for the resource and its relations.

The reserved words / parameters that will be used as query params are:

- perPage
- page

The ```withRelations, withRelationsCount, withRelationsExistence``` query params will be disregarded


I. [Install](#i-install)

II. [Start using it](#ii-start-using-it)

III. [Crud routes](#iii-crud-routes)

III.1. [Create resource](#iii1-create-resource)

III.2. [Get resource](#iii2-get-resource)

III.3. [List filtered resource](#iii3-list-filtered-resource)

III.4. [Update resource (or create)](#iii4-update-resource-or-create)

III.5. [Delete resource](#iii5-delete-resource)



## I. Install

    composer require macropay-solutions/php-crufd-wizard-decorator


## II. Start using it


OBS.
- The ```withRelations, withRelationsCount, withRelationsExistence``` query params will be disregarded. These are to be used only internally with undecorated request.
- 202 http response code will not be decorated, and it can be used to send messaged to FE. Example: {"message":"Accepted"}
- 204 http response will be decorated as empty body.


Use the middleware in your crud route definition for each method if you have only few routes (it is faster to use the middleware FQN directly in the route definition):

```php
    SomeMiddleware::class . ':list'
    SomeMiddleware::class . ':get'
    SomeMiddleware::class . ':getRelated'
    SomeMiddleware::class . ':update'
    SomeMiddleware::class . ':updateRelated'
    SomeMiddleware::class . ':create'
    SomeMiddleware::class . ':delete'
    SomeMiddleware::class . ':deleteRelated'
```

In this way you avoid loading on each request the route middleware array that in some cases can become quite big (hundreds of elements).

Coupled with cached routes this solution is faster than the initial one.


Example:

```php
    <?php
    
    namespace MacropaySolutions\CrufdWizardDecorator\Models;
    
    use MacropaySolutions\Kernel\Database\Obvious\Model;
    use MacropaySolutions\Kernel\Database\Obvious\Relations\HasOne;
    use MacropaySolutions\CrufdWizard\Models\BaseModel;
    
    class ExampleModel extends BaseModel
    {
        public const RESOURCE_NAME = 'examples';
    
        protected $fillable = [
            'role_id',
            'created_at',
            'updated_at',
        ];
    
        public function roleRelation(): HasOne
        {
            return $this->hasOne(RelationExampleModel::class, 'id', 'role_id');
        }
    }
    
    <?php
    
    namespace MacropaySolutions\CrufdWizardDecorator\Models;
    
    use MacropaySolutions\Kernel\Database\Obvious\Model;
    use MacropaySolutions\Kernel\Database\Obvious\Relations\HasMany;
    use MacropaySolutions\CrufdWizard\Models\BaseModel;
    
    class RelationExampleModel extends BaseModel
    {
        public const RESOURCE_NAME = 'relation-examples';
    
        protected $fillable = [
            'name',
            'color',
            'created_at',
            'updated_at',
        ];
    
        public function exampleModels(): HasMany
        {
            return $this->hasMany(ExampleModel::class, 'role_id', 'id');
        }
    }
    
    <?php
    
    namespace MacropaySolutions\CrufdWizardDecorator\Http\Middleware\Decorators;
    
    use MacropaySolutions\CrufdWizardDecorator\Decorators\ExampleDecorator;
    use MacropaySolutions\CrufdWizardDecorator\Decorators\RelationExampleDecorator;
    
    class ExampleMiddleware extends AbstractDecoratorMiddleware
    {
        protected string $decoratorClass = ExampleDecorator::class;
        protected array $relatedDecoratorClassMap = [
            'roleRelation' => RelationExampleDecorator::class
        ];

        public function setResourceModel(): void
        {
            $this->resourceModel = new ExampleModel();
        }
    }
    
    <?php
    
    namespace MacropaySolutions\CrufdWizardDecorator\Http\Middleware\Decorators;
    
    use MacropaySolutions\CrufdWizardDecorator\Decorators\ExampleDecorator;
    use MacropaySolutions\CrufdWizardDecorator\Decorators\RelationExampleDecorator;
    
    class RelationExampleMiddleware extends AbstractDecoratorMiddleware
    {
        protected string $decoratorClass = RelationExampleDecorator::class;
        protected array $relatedDecoratorClassMap = [
            'exampleModels' => ExampleDecorator::class
        ];

        public function setResourceModel(): void
        {
            $this->resourceModel = new RelationExampleModel();
        }
    }
    
    <?php
    
    namespace MacropaySolutions\CrufdWizardDecorator\Decorators;
    
    class ExampleDecorator extends AbstractResourceDecorator
    {
        public function getResourceMappings(): array
        {
            return [
                'id' => 'ID',
                'updated_at' => 'updatedAt',
                'created_at' => 'createdAt',
            ];
        }
    
        /**
         * @inheritDoc
         */
        public function getRelationMappings(): array
        {
            return [
                'roleRelation' => [
                    'name' => 'roleRelationName',
                    'color' => 'roleRelationColor',
                ],
            ];
        }
    }
    
    
    <?php
    
    namespace MacropaySolutions\CrufdWizardDecorator\Decorators;
    
    class RelationExampleDecorator extends AbstractResourceDecorator
    {
        public array $countRelations = ['exampleModels'];
        public array $existRelations = ['exampleModels'];

        public function getResourceMappings(): array
        {
            return [
                'id' => 'ID',
                'name' => 'roleName',
                'color' => 'roleColor',
                'updated_at' => 'updatedAt',
                'created_at' => 'createdAt',
            ];
        }
    }
```


### III. Crud routes


#### III.1 Create resource
**POST** /{resource}

headers:

      Authorization: Bearer ... // if needed. not coded in this lib
      
      Accept: application/json
      
      ContentType: application/json

body:

      {
         "roleID": "1",
      }

Json Response:

200:

    {
        "success": true,
        "code": 201,
        "locale": "en",
        "message": "success",
        "data": {
            "ID": 3,
            "roleID": "1",
            "updatedAt": "2022-10-27 09:05:49",
            "createdAt": "2022-10-27 09:04:46",
            "pki": "3"
        }
    }

    {
        "success": false,
        "code": 400,
        "locale": "en",
        "message": "The given data was invalid: The role id field is required.",
        "data": {
            "roleID": [
                "The role id field is required."
            ]
        }
    }



#### III.2 Get resource
**GET** /{resource}/{identifier}

**GET** /{resource}/{identifier}/{relation}/{relatedIdentifier}


headers:

      Authorization: Bearer ... // if needed. not coded in this lib
      
      Accept: application/json

Json Response:

200:

    {
        "success": true,
        "code": 200,
        "locale": "en",
        "message": "success",
        "data": {
            "ID": 3,
            "roleID": "1",
            "updatedAt": "2022-10-27 09:05:49",
            "createdAt": "2022-10-27 09:04:46",
            "roleRelationName": "name",
            "roleRelationColor": "blue",
            "pki": "3"
        }
    }

    {
        "success": false,
        "code": 400,
        "locale": "en",
        "message": "Not found",
        "data": null
    }


#### III.3 List filtered resource
**GET** /{resource}?perPage=10&page=2

**GET** /{resource}/{identifier}/{relation}?... // paid version only

headers:

      Authorization: Bearer ... // if needed. not coded in this lib
      
      Accept: application/json or application/xls

The xls will contain undecorated columns and needs special access. See AbstractDecoratorMiddleware::isUserAllowedToDownloadXls

Json Response:

200:

    {
        "success": true,
        "code": 200,
        "locale": "en",
        "message": "success",
        "data": {
            "current_page": 1,
            "data": [
                {
                    "ID": 3,
                    "roleID": "1",
                    "updatedAt": "2022-10-27 09:05:49",
                    "createdAt": "2022-10-27 09:04:46",
                    "roleRelationName": "name",
                    "roleRelationColor": "blue",
                    "pki": "3"
                }
            ],
            "from": 1,
            "last_page": 1,
            "per_page": 10,
            "to": 1,
            "total": 1,
            "filterable": [
                "ID",
                "roleID",
                "updatedAt",
                "createdAt",
            ],
            "sortable": [
                "ID",
                "roleID"
            ]
        }
    }

Binary response for application/xls


#### III.4 Update resource (or create)
**PUT** /{resource}/{identifier}

**PUT** /{resource}/{identifier}/{relation}/{relatedIdentifier}

headers:

      Authorization: Bearer ... // if needed. not coded in this lib
      
      Accept: application/json
      
      ContentType: application/json

body:

      {
        "roleID": "2"
      }

Json Response:

200:

    {
        "success": true,
        "code": 200, // or 201 for upsert
        "locale": "en",
        "message": "success",
        "data": {
            "ID": 3,
            "roleID": "2",
            "updatedAt": "2022-10-27 09:05:49",
            "createdAt": "2022-10-27 09:04:46",
            "pki": "3"
        }
    }

    {
        "success": false,
        "code": 400,
        "locale": "en",
        "message": "The given data was invalid: The role id field is required.",
        "data": {
            "roleID": [
                "The role id field is required."
            ]
        }
    }


In case of validation errors, only the keys from data object will be decorated!

#### III.5 Delete resource
**DELETE** /{resource}/{identifier}

**DELETE** /{resource}/{identifier}/{relation}/{relatedIdentifier}

headers:

      Authorization: Bearer ... // if needed. not coded in this lib

      Accept: application/json

Json Response:

200:

    {
        "success": true,
        "code": 204,
        "locale": "en",
        "message": "success",
        "data": null
    }

    {
        "success": false,
        "code": 400,
        "locale": "en",
        "message": "Not found",
        "data": null
    }
