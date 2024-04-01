<?php

declare(strict_types=1);

namespace XGraphQL\SchemaCache\Test;

use GraphQL\Language\DirectiveLocation;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaPrinter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use XGraphQL\SchemaCache\Exception\RuntimeException;
use XGraphQL\SchemaCache\SchemaCache;

class SchemaCacheTest extends TestCase
{
    public function testClearCacheBeforeSave(): void
    {
        $psrCache = new Psr16Cache(new ArrayAdapter());
        $schemaCache = new SchemaCache($psrCache);

        $psrCache->set('test', 'test data');

        $this->assertTrue($psrCache->has('test'));

        $schemaCache->save(new Schema([]));

        $this->assertFalse($psrCache->has('test'));
    }

    public function testSaveSchemaHaveAST(): void
    {
        $psrCache = new Psr16Cache(new ArrayAdapter());
        $schemaCache = new SchemaCache($psrCache);
        $sdl = <<<'SDL'
directive @dummy on FIELD

type Query {
  getBooks: [Book!]!
}

type Mutation {
  createBook(name: String!): Book!
}

type Book {
  id: ID!
  name: String!
}

type Unused {
  id: ID!
  nestedUnused: [NestedUnused!]!
}

type NestedUnused {
  id: ID!
}

SDL;
        $schema = BuildSchema::build($sdl);
        $schemaCache->save($schema);
        $schemaFromCache = $schemaCache->load();

        $this->assertTrue($schemaFromCache->hasType('Query'));
        $this->assertTrue($schemaFromCache->hasType('Mutation'));
        $this->assertTrue($schemaFromCache->hasType('Book'));
        $this->assertTrue($schemaFromCache->hasType('Unused'));
        $this->assertTrue($schemaFromCache->hasType('NestedUnused'));
        $this->assertEquals($sdl, SchemaPrinter::doPrint($schemaFromCache));
    }

    public function testSaveSchemaNotHaveAST(): void
    {
        $psrCache = new Psr16Cache(new ArrayAdapter());
        $schemaCache = new SchemaCache($psrCache);
        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'dummy' => Type::string(),
                    'dummyObject' => new ObjectType([
                        'name' => 'DummyObject',
                        'fields' => [
                            'id' => Type::id(),
                        ],
                    ]),
                ],
            ]),
            'types' => [
                new CustomScalarType(['name' => 'CustomScalar']),
            ],
            'directives' => [
                new Directive(['name' => 'dummy', 'locations' => [DirectiveLocation::FIELD]])
            ],
        ]);


        $schemaCache->save($schema);

        $schemaFromCache = $schemaCache->load();

        $sdlExpecting = <<<'SDL'
directive @dummy on FIELD

scalar CustomScalar

type Query {
  dummy: String
  dummyObject: DummyObject
}

type DummyObject {
  id: ID
}

SDL;

        $this->assertEquals($sdlExpecting, SchemaPrinter::doPrint($schemaFromCache));
    }

    public function testMissingDirectiveASTFromCache(): void
    {
        $psrCache = new Psr16Cache(new ArrayAdapter());
        $psrCache->set('types', []);
        $psrCache->set('directives', ['missing']);
        $schemaCache = new SchemaCache($psrCache);

        $this->expectExceptionMessage('Not found AST of directive: `missing` from cache');
        $this->expectException(RuntimeException::class);

        $schemaCache->load();
    }

    public function testMissingTypeASTFromCache(): void
    {
        $psrCache = new Psr16Cache(new ArrayAdapter());
        $psrCache->set('types', ['missing']);
        $psrCache->set('directives', []);
        $schemaCache = new SchemaCache($psrCache);

        $this->expectExceptionMessage('Not found AST of type: `missing` from cache');
        $this->expectException(RuntimeException::class);

        $schemaCache->load()->getTypeMap();
    }

    public function testLoadSchemaFromEmptyCache(): void
    {
        $psrCache = new Psr16Cache(new ArrayAdapter());
        $schemaCache = new SchemaCache($psrCache);

        $this->assertNull($schemaCache->load());
    }
}
