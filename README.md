Schema Cache
============

Save and lazy load [GraphQL Schema](https://webonyx.github.io/graphql-php/schema-definition/) from PSR-16 cache.

![unit tests](https://github.com/x-graphql/schema-cache/actions/workflows/unit_tests.yml/badge.svg)
[![codecov](https://codecov.io/gh/x-graphql/schema-cache/graph/badge.svg?token=c1xCJsFvIs)](https://codecov.io/gh/x-graphql/schema-cache)


Getting Started
---------------

Install this package via [Composer](https://getcomposer.org)

```shell
composer require x-graphql/schema-cache symfony/cache
```

Usages
------

Create an instance of `XGraphQL\SchemaCache\SchemaCache` with PSR-16 to save and load:

```php
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use XGraphQL\SchemaCache\SchemaCache;

$psr16 = new Psr16Cache(new ArrayAdapter());
$schemaCache = new SchemaCache($psr16);

$schemaCache->save(/* $schema */);

/// Lazy to load on another http requests

$schemaFromCache = $schemaCache->load();
```
> [!NOTE]
> This package not support to decorate type after load schema from cache,
> you need to add type resolvers before execute schema.

Credits
-------

Created by [Minh Vuong](https://github.com/vuongxuongminh)
