<?php

declare(strict_types=1);

namespace App\Domain\Dolinews\Api;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads the OpenAPI document that describes the public API (SPEC 5.2).
 *
 * The document in resources/openapi is the single source: the machine
 * readable specification served to client generators and the human
 * readable page both come from here, so the two cannot drift apart.
 *
 * Two shapes are exposed. document() is the specification itself, with
 * the server URL of the running instance filled in. operations() is the
 * flattened, $ref-free view the documentation page renders: a template
 * has no business chasing JSON pointers.
 */
class OpenApiSpec
{
    /**
     * Longest chain of $ref a branch may follow. The document stays far
     * below it; a resolver that trusts its input loops forever the day
     * a cycle appears.
     */
    private const MAX_DEPTH = 12;

    /**
     * Decoded document, memoised for the lifetime of the request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $cache = null;

    /**
     * The specification, with servers pointing at this instance.
     *
     * @return array<string, mixed>
     */
    public function document(?string $baseUrl = null): array
    {
        $document = $this->load();

        if ($baseUrl !== null) {
            $document['servers'] = [[
                'url' => $baseUrl,
                'description' => (string) ($document['servers'][0]['description'] ?? ''),
            ]];
        }

        return $document;
    }

    /**
     * Version of the API contract, as announced by the document.
     */
    public function version(): string
    {
        return (string) ($this->load()['info']['version'] ?? '');
    }

    /**
     * Prose of the document: title, summary, description, licence.
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        /** @var array<string, mixed> $info */
        $info = $this->load()['info'] ?? [];
        $info['description_paragraphs'] = $this->paragraphs((string) ($info['description'] ?? ''));

        return $info;
    }

    /**
     * Split a description into the paragraphs a template prints.
     *
     * Done here rather than in the view: the document separates its
     * paragraphs with a blank line, and a template has no sensible way
     * to handle the failure mode of a regular expression.
     *
     * @return list<string>
     */
    public function paragraphs(string $text): array
    {
        $parts = preg_split('/\n\n+/', trim($text));

        if ($parts === false) {
            return $text === '' ? [] : [$text];
        }

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $paragraph): bool => $paragraph !== '',
        ));
    }

    /**
     * How the bearer token is presented by the document.
     */
    public function securityDescription(): string
    {
        return (string) ($this->load()['components']['securitySchemes']['bearerToken']['description'] ?? '');
    }

    /**
     * Throttle table, one entry per limiter named by the operations.
     *
     * @return array<string, array<string, string>>
     */
    public function throttles(): array
    {
        /** @var array<string, array<string, string>> $throttles */
        $throttles = $this->load()['x-throttles'] ?? [];

        return $throttles;
    }

    /**
     * Error codes with the HTTP status each one carries.
     *
     * @return array<string, int>
     */
    public function errorCodes(): array
    {
        /** @var array<string, int> $codes */
        $codes = $this->load()['x-error-codes'] ?? [];

        return $codes;
    }

    /**
     * Every path and method of the document, in declaration order.
     *
     * The list is what the conformance test compares to the registered
     * routes: a route added without documentation must fail the suite,
     * not ship silently.
     *
     * @return list<array{method: string, path: string}>
     */
    public function endpoints(): array
    {
        $endpoints = [];

        /** @var array<string, array<string, mixed>> $paths */
        $paths = $this->load()['paths'] ?? [];

        foreach ($paths as $path => $operations) {
            foreach ($operations as $method => $operation) {
                // Path-level "parameters" sits next to the methods and is
                // not one of them.
                if ($method === 'parameters' || ! is_array($operation)) {
                    continue;
                }

                $endpoints[] = ['method' => strtoupper($method), 'path' => (string) $path];
            }
        }

        return $endpoints;
    }

    /**
     * Operations grouped by tag, in the tag order of the document, with
     * every $ref resolved and the path-level parameters merged in.
     *
     * @return list<array{name: string, anchor: string, description: string, operations: list<array<string, mixed>>}>
     */
    public function operationsByTag(): array
    {
        $document = $this->load();

        /** @var array<string, array<string, mixed>> $paths */
        $paths = $document['paths'] ?? [];

        /** @var array<int, array<string, string>> $tags */
        $tags = $document['tags'] ?? [];

        $grouped = [];

        foreach ($tags as $tag) {
            $grouped[$tag['name']] = [
                'name' => $tag['name'],
                'anchor' => Str::slug($tag['name']),
                'description' => $tag['description'] ?? '',
                'operations' => [],
            ];
        }

        foreach ($paths as $path => $operations) {
            /** @var list<array<string, mixed>> $shared */
            $shared = $this->resolve($operations['parameters'] ?? [], $document);

            foreach ($operations as $method => $operation) {
                if ($method === 'parameters' || ! is_array($operation)) {
                    continue;
                }

                /** @var array<string, mixed> $resolved */
                $resolved = $this->resolve($operation, $document);

                $resolved['method'] = strtoupper($method);
                $resolved['path'] = (string) $path;
                $resolved['parameters'] = array_merge($shared, $resolved['parameters'] ?? []);

                $resolved = $this->present($resolved);

                $tag = (string) ($resolved['tags'][0] ?? '');

                if (! isset($grouped[$tag])) {
                    $grouped[$tag] = [
                        'name' => $tag,
                        'anchor' => Str::slug($tag),
                        'description' => '',
                        'operations' => [],
                    ];
                }

                $grouped[$tag]['operations'][] = $resolved;
            }
        }

        return array_values($grouped);
    }

    /**
     * Turn a resolved operation into what the page displays: the
     * template walks flat rows, it never inspects a JSON schema.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function present(array $operation): array
    {
        // The security array is empty on the anonymous reads: the page
        // states "no account needed" rather than leaving the reader to
        // infer it from an absence.
        $operation['authenticated'] = ($operation['security'] ?? []) !== [];
        $operation['contributor_only'] = ($operation['x-requires'] ?? null) === 'contributor';
        $operation['description_paragraphs'] = $this->paragraphs((string) ($operation['description'] ?? ''));

        $operation['query_fields'] = [];
        $operation['path_fields'] = [];

        /** @var list<array<string, mixed>> $parameters */
        $parameters = $operation['parameters'] ?? [];

        foreach ($parameters as $parameter) {
            /** @var array<string, mixed> $schema */
            $schema = $parameter['schema'] ?? [];

            $row = [
                'name' => (string) ($parameter['name'] ?? ''),
                'type' => $this->typeLabel($schema),
                'required' => (bool) ($parameter['required'] ?? false),
                'description' => (string) ($parameter['description'] ?? ''),
                'enum' => $this->enumOf($schema),
                'default' => $schema['default'] ?? null,
            ];

            if (($parameter['in'] ?? '') === 'path') {
                $operation['path_fields'][] = $row;

                continue;
            }

            $operation['query_fields'][] = $row;
        }

        $request = $this->requestSchema($operation);

        $operation['request_media_type'] = $request['media_type'] ?? null;
        $operation['request_fields'] = $request === null ? [] : $this->fields($request['schema']);

        $operation['response_rows'] = [];

        /** @var array<string, array<string, mixed>> $responses */
        $responses = $operation['responses'] ?? [];

        foreach ($responses as $status => $response) {
            $operation['response_rows'][] = [
                'status' => (string) $status,
                'description' => (string) ($response['description'] ?? ''),
            ];
        }

        return $operation;
    }

    /**
     * Flatten a resolved schema into the rows the page tabulates.
     *
     * allOf compositions are merged: a reader wants the fields of an
     * article, not the fact that the document assembles them from two
     * pieces.
     *
     * @param  array<string, mixed>  $schema
     * @return list<array{name: string, type: string, required: bool, description: string, enum: list<string>}>
     */
    public function fields(array $schema): array
    {
        $schema = $this->flatten($schema);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'] ?? [];

        /** @var list<string> $required */
        $required = $schema['required'] ?? [];

        $fields = [];

        foreach ($properties as $name => $property) {
            $property = $this->flatten($property);

            $fields[] = [
                'name' => (string) $name,
                'type' => $this->typeLabel($property),
                'required' => in_array((string) $name, $required, true),
                'description' => (string) ($property['description'] ?? ''),
                'enum' => $this->enumOf($property),
            ];
        }

        return $fields;
    }

    /**
     * Human label for the type of a schema: "string", "integer or null",
     * "array of string".
     *
     * @param  array<string, mixed>  $schema
     */
    public function typeLabel(array $schema): string
    {
        $schema = $this->flatten($schema);
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            return implode(' | ', array_map('strval', $type));
        }

        if ($type === 'array') {
            $items = $this->flatten((array) ($schema['items'] ?? []));
            $itemType = $items['type'] ?? 'any';

            return 'array of '.(is_array($itemType) ? implode(' | ', array_map('strval', $itemType)) : (string) $itemType);
        }

        if ($type === null && isset($schema['oneOf'])) {
            $types = array_map(
                fn (array $branch): string => $this->typeLabel($branch),
                (array) $schema['oneOf'],
            );

            return implode(' | ', $types);
        }

        return (string) ($type ?? 'object');
    }

    /**
     * Enumerated values of a schema, on the schema itself or on the
     * items of an array.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function enumOf(array $schema): array
    {
        $schema = $this->flatten($schema);

        if (isset($schema['enum'])) {
            return array_values(array_map('strval', (array) $schema['enum']));
        }

        if (isset($schema['items'])) {
            $items = $this->flatten((array) $schema['items']);

            if (isset($items['enum'])) {
                return array_values(array_map('strval', (array) $items['enum']));
            }
        }

        if (isset($schema['oneOf'])) {
            foreach ((array) $schema['oneOf'] as $branch) {
                $values = $this->enumOf((array) $branch);

                if ($values !== []) {
                    return $values;
                }
            }
        }

        return [];
    }

    /**
     * The request body schema of an operation, with its media type.
     *
     * @param  array<string, mixed>  $operation
     * @return array{media_type: string, schema: array<string, mixed>}|null
     */
    public function requestSchema(array $operation): ?array
    {
        /** @var array<string, array<string, mixed>> $content */
        $content = $operation['requestBody']['content'] ?? [];

        foreach ($content as $mediaType => $definition) {
            return [
                'media_type' => (string) $mediaType,
                'schema' => (array) ($definition['schema'] ?? []),
            ];
        }

        return null;
    }

    /**
     * Merge an allOf composition into a single schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function flatten(array $schema): array
    {
        if (! isset($schema['allOf'])) {
            return $schema;
        }

        $merged = $schema;
        unset($merged['allOf']);

        foreach ((array) $schema['allOf'] as $branch) {
            $branch = $this->flatten((array) $branch);

            $merged['properties'] = array_merge(
                $merged['properties'] ?? [],
                $branch['properties'] ?? [],
            );
            $merged['required'] = array_values(array_unique(array_merge(
                $merged['required'] ?? [],
                $branch['required'] ?? [],
            )));
            $merged['type'] ??= $branch['type'] ?? null;
        }

        return $merged;
    }

    /**
     * Replace every {"$ref": "#/..."} by the node it points at.
     *
     * The guard tracks the chain of pointers followed down one branch,
     * not the nesting depth of the JSON: a legitimate document nests
     * far deeper than any $ref chain, and counting levels would reject
     * it. The trail is copied into each branch, so the same schema
     * referenced twice side by side is not mistaken for a cycle.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, mixed>  $document
     * @param  list<string>  $trail
     * @return array<array-key, mixed>
     */
    private function resolve(array $node, array $document, array $trail = []): array
    {
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $ref = $node['$ref'];

            if (in_array($ref, $trail, true)) {
                throw new RuntimeException('OpenApiSpec: cycle on $ref '.$ref.' via '.implode(' -> ', $trail));
            }

            if (count($trail) >= self::MAX_DEPTH) {
                throw new RuntimeException('OpenApiSpec: $ref chain beyond '.self::MAX_DEPTH.' links at '.$ref);
            }

            $trail[] = $ref;

            $target = $this->pointer($ref, $document);
            // A sibling of $ref overrides the target: the document uses
            // it to give one response a more specific description.
            unset($node['$ref']);

            $node = array_merge($target, $node);
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->resolve($value, $document, $trail);
            }
        }

        return $node;
    }

    /**
     * Follow a local JSON pointer such as #/components/schemas/Article.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function pointer(string $ref, array $document): array
    {
        if (! str_starts_with($ref, '#/')) {
            throw new RuntimeException('OpenApiSpec: only local $ref are supported, got '.$ref);
        }

        $node = $document;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new RuntimeException('OpenApiSpec: unresolved $ref '.$ref);
            }

            $node = $node[$segment];
        }

        if (! is_array($node)) {
            throw new RuntimeException('OpenApiSpec: $ref '.$ref.' does not point at an object.');
        }

        return $node;
    }

    /**
     * Read and decode the document.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = resource_path('openapi/v1.json');
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException('OpenApiSpec: specification unreadable at '.$path);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenApiSpec: invalid JSON in '.$path.': '.json_last_error_msg());
        }

        /** @var array<string, mixed> $decoded */
        return $this->cache = $decoded;
    }
}
