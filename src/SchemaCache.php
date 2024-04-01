<?php

declare(strict_types=1);

namespace XGraphQL\SchemaCache;

use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Type\SchemaConfig;
use GraphQL\Utils\AST;
use GraphQL\Utils\ASTDefinitionBuilder;
use GraphQL\Utils\SchemaPrinter;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use XGraphQL\SchemaCache\Exception\RuntimeException;

final readonly class SchemaCache
{
    private const TYPES_KEY = 'types';

    private const DIRECTIVES_KEY = 'directives';

    public function __construct(private CacheInterface $cache)
    {
    }

    /**
     * @throws SerializationError
     * @throws InvalidArgumentException
     * @throws \JsonException
     * @throws SyntaxError
     * @throws Error
     */
    public function save(Schema $schema): void
    {
        $this->cache->clear();

        $types = $directives = [];
        $directivePrinterRef = new \ReflectionMethod(SchemaPrinter::class, 'printDirective');
        $directivePrinter = $directivePrinterRef->getClosure();

        foreach ($schema->getDirectives() as $directive) {
            if (in_array($directive, Directive::getInternalDirectives(), true)) {
                continue;
            }

            $ast = $directive->astNode;

            if (null === $ast) {
                $sdl = $directivePrinter($directive, []);
                $ast = Parser::parse($sdl, ['noLocation' => true])->definitions[0];
            }

            $name = $directives[] = $directive->name;

            $this->cache->set($this->keyOfDirective($name), AST::toArray($ast));
        }

        foreach ($schema->getTypeMap() as $type) {
            if (in_array($type, Type::builtInTypes(), true)) {
                continue;
            }

            $ast = $type->astNode();

            if (null === $ast) {
                $sdl = SchemaPrinter::printType($type);
                $ast = Parser::parse($sdl, ['noLocation' => true])->definitions[0];
            }

            $name = $types[] = $type->name();

            $this->cache->set($this->keyOfType($name), AST::toArray($ast));
        }

        foreach (['query', 'mutation', 'subscription'] as $operation) {
            $operationType = $schema->getOperationType($operation);

            $this->cache->set($operation, $operationType?->name());
        }

        $this->cache->set(self::TYPES_KEY, $types);
        $this->cache->set(self::DIRECTIVES_KEY, $directives);
    }

    /**
     * @throws \ReflectionException
     * @throws Error
     * @throws InvalidArgumentException
     */
    public function load(): ?Schema
    {
        if (!$this->cache->has(self::TYPES_KEY) || !$this->cache->has(self::DIRECTIVES_KEY)) {
            return null;
        }

        /** @var ASTDefinitionBuilder $builder */
        $builder = null;
        $typeResolver = $this->makeTypeResolver($builder);
        $builder = new ASTDefinitionBuilder([], [], $typeResolver);
        $config = new SchemaConfig();

        foreach (['query', 'mutation', 'subscription'] as $operation) {
            $typename = $this->cache->get($operation);

            if (null !== $typename) {
                call_user_func([$config, 'set' . ucfirst($operation)], fn() => $builder->buildType($typename));
            }
        }

        $config->setTypeLoader($this->makeTypeLoader($builder));
        $config->setDirectives($this->loadDirectives($builder));
        $config->setTypes($this->loadTypes($builder));

        return new Schema($config);
    }

    private function loadDirectives(ASTDefinitionBuilder $builder): array
    {
        $directives = [];
        $names = $this->cache->get(self::DIRECTIVES_KEY, []);

        foreach ($names as $name) {
            $astNormalized = $this->cache->get($this->keyOfDirective($name));

            if (null === $astNormalized) {
                throw new RuntimeException(sprintf('Not found AST of directive: `%s` from cache', $name));
            }

            $directives[] = $builder->buildDirective(AST::fromArray($astNormalized));
        }

        return $directives;
    }

    private function loadTypes(ASTDefinitionBuilder $builder): \Closure
    {
        return function () use ($builder): array {
            $names = $this->cache->get(self::TYPES_KEY, []);
            $types = [];

            foreach ($names as $name) {
                $types[$name] = $builder->buildType($name);
            }

            return $types;
        };
    }

    private function makeTypeResolver(?ASTDefinitionBuilder &$builder): \Closure
    {
        return function (string $name) use (&$builder) {
            static $schemaDef = null;

            if (null === $schemaDef) {
                $schemaDefRef = new \ReflectionMethod($builder, 'makeSchemaDef');
                $schemaDef = $schemaDefRef->getClosure($builder);
            }

            $astNormalized = $this->cache->get($this->keyOfType($name));

            if (null === $astNormalized) {
                throw new RuntimeException(sprintf('Not found AST of type: `%s` from cache', $name));
            }

            return $schemaDef(AST::fromArray($astNormalized));
        };
    }

    private function makeTypeLoader(ASTDefinitionBuilder $builder): \Closure
    {
        return function (string $name) use ($builder): ?NamedType {
            if (!$this->cache->has($this->keyOfType($name))) {
                return null;
            }

            return $builder->buildType($name);
        };
    }

    private function keyOfType(string $typename): string
    {
        return sprintf('%s.%s', self::TYPES_KEY, $typename);
    }

    private function keyOfDirective(string $directiveName): string
    {
        return sprintf('%s.%s', self::DIRECTIVES_KEY, $directiveName);
    }
}
