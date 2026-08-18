<?php

declare(strict_types=1);

namespace tests\support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Yii;

/**
 * The OpenAPI document, parsed once per run and exposed as the questions the
 * contract gates actually ask of it.
 *
 * `config/openapi.yaml` is declared the single source of truth for this API's
 * request/response shapes and RBAC gates, but it is written by hand — so the
 * gates in tests/unit/contract/ exist to prove the code agrees with it. This
 * class is their shared reader.
 *
 * The path comes from `params['openapi_path']` rather than a hard-coded alias,
 * so the tests read the very file {@see \app\controllers\DocsController} serves.
 */
final class OpenApiSpec
{
    private static ?self $instance = null;

    private function __construct(private readonly array $document)
    {
    }

    /**
     * The document is ~48 KB and every gate asks it several questions, so it is
     * parsed once and shared rather than re-read per test method.
     */
    public static function load(): self
    {
        if (self::$instance === null) {
            $path = Yii::getAlias(Yii::$app->params['openapi_path']);
            self::$instance = new self((array) Yaml::parseFile($path));
        }

        return self::$instance;
    }

    /**
     * Every documented operation, keyed `"GET /users/{id}"`.
     *
     * `parameters` is skipped: it is a path-level key holding parameters shared
     * by that path's methods, not a method of its own.
     *
     * @return array<string, array>
     */
    public function operations(): array
    {
        $operations = [];

        foreach ($this->document['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }
                $operations[strtoupper($method) . ' ' . $path] = $operation;
            }
        }

        return $operations;
    }

    public function operation(string $path, string $method): array
    {
        $key = strtoupper($method) . ' ' . $path;
        $operations = $this->operations();

        if (!isset($operations[$key])) {
            throw new RuntimeException("No operation $key in the OpenAPI document.");
        }

        return $operations[$key];
    }

    /** @return string[] */
    public function schemaNames(): array
    {
        return array_keys($this->document['components']['schemas']);
    }

    public function schema(string $name): array
    {
        if (!isset($this->document['components']['schemas'][$name])) {
            throw new RuntimeException("No schema \"$name\" in the OpenAPI document.");
        }

        return $this->document['components']['schemas'][$name];
    }

    /**
     * The property names a named schema publishes, with `allOf` compositions
     * flattened — `Me` is `UserWithAlbums` plus `roles`, which is in turn `User`
     * plus `albums`, and the code side of that is one `fields()` call.
     *
     * @return string[]
     */
    public function propertyNames(string $name): array
    {
        return $this->propertyNamesOf($this->schema($name));
    }

    /**
     * The property names of a response envelope's `data` object — the part a
     * DTO actually mirrors. The envelope itself only says where the payload
     * sits.
     *
     * @return string[]
     */
    public function dataPropertyNames(string $name): array
    {
        $properties = $this->propertiesOf($this->schema($name));

        if (!isset($properties['data'])) {
            throw new RuntimeException("Schema \"$name\" has no `data` property.");
        }

        return $this->propertyNamesOf($this->resolve($properties['data']));
    }

    /**
     * An operation's query parameters, keyed by name, with path-level ones
     * merged in and every `$ref` resolved.
     *
     * Resolving is not optional: the shared `page`/`per_page` parameters are
     * declared once under `components.parameters` and referenced everywhere, so
     * without this an index endpoint would look like it accepts neither.
     *
     * @return array<string, array>
     */
    public function queryParameters(string $path, string $method): array
    {
        $declared = array_merge(
            $this->document['paths'][$path]['parameters'] ?? [],
            $this->operation($path, $method)['parameters'] ?? [],
        );

        $parameters = [];
        foreach ($declared as $parameter) {
            $parameter = $this->resolve($parameter);
            if (($parameter['in'] ?? null) === 'query') {
                $parameters[$parameter['name']] = $parameter;
            }
        }

        return $parameters;
    }

    /**
     * An operation's request body schema for the given media type, `$ref`
     * resolved — so a body declared inline (the multipart photo upload) and one
     * referencing a named schema are read the same way.
     */
    public function requestBody(string $path, string $method, string $mediaType = 'application/json'): ?array
    {
        $schema = $this->operation($path, $method)['requestBody']['content'][$mediaType]['schema'] ?? null;

        return $schema === null ? null : $this->resolve($schema);
    }

    /**
     * Follows a `#/components/<section>/<name>` pointer. Anything without a
     * `$ref` is already resolved and is returned untouched.
     */
    public function resolve(array $node): array
    {
        if (!isset($node['$ref'])) {
            return $node;
        }

        $pointer = $node['$ref'];
        $parts = explode('/', ltrim($pointer, '#/'));

        $target = $this->document;
        foreach ($parts as $part) {
            if (!isset($target[$part])) {
                throw new RuntimeException("Unresolvable \$ref \"$pointer\" in the OpenAPI document.");
            }
            $target = $target[$part];
        }

        return $target;
    }

    /** @return string[] */
    private function propertyNamesOf(array $schema): array
    {
        return array_keys($this->propertiesOf($schema));
    }

    /**
     * Every `properties` entry a schema contributes, following `$ref` and
     * merging each branch of an `allOf`.
     *
     * @return array<string, array>
     */
    private function propertiesOf(array $schema): array
    {
        $schema = $this->resolve($schema);
        $properties = $schema['properties'] ?? [];

        foreach ($schema['allOf'] ?? [] as $branch) {
            $properties = array_merge($properties, $this->propertiesOf($branch));
        }

        return $properties;
    }
}
