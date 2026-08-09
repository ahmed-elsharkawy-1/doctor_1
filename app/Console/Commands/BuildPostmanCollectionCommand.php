<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Generates the Postman collection from docs/api/v1/openapi.yaml.
 *
 * Postman can import the OpenAPI file directly, but the result is bare: no
 * environment, and a token you have to paste into every request by hand. This
 * adds the two things that make it pleasant to actually use — {{base_url}} and
 * {{token}} variables, and a login test that captures the token automatically.
 *
 * The YAML stays the source of truth; this output is regenerated, never edited.
 */
class BuildPostmanCollectionCommand extends Command
{
    protected $signature = 'api:postman
                            {--spec=docs/api/v1/openapi.yaml : OpenAPI file to read}
                            {--out=docs/api/v1 : Directory to write into}';

    protected $description = 'Generate the Postman collection and environment from the OpenAPI spec';

    public function handle(): int
    {
        $specPath = base_path($this->option('spec'));

        if (! is_file($specPath)) {
            $this->error("Spec not found at {$specPath}.");

            return self::FAILURE;
        }

        $spec = Yaml::parseFile($specPath);
        $outDir = base_path($this->option('out'));

        $collection = $this->collection($spec);
        $environment = $this->environment($spec);

        $collectionPath = $outDir.'/doctor1.postman_collection.json';
        $environmentPath = $outDir.'/doctor1.postman_environment.json';

        file_put_contents($collectionPath, $this->encode($collection));
        file_put_contents($environmentPath, $this->encode($environment));

        $this->info('Wrote '.count($collection['item']).' folders from '.$this->countOperations($spec).' operations.');
        $this->line('  '.str_replace(base_path().'/', '', $collectionPath));
        $this->line('  '.str_replace(base_path().'/', '', $environmentPath));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function collection(array $spec): array
    {
        return [
            'info' => [
                '_postman_id' => $this->uuidFrom($spec['info']['title']),
                'name' => $spec['info']['title'].' — v'.$spec['info']['version'],
                'description' => trim($spec['info']['description'] ?? ''),
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            // Bearer at collection level: every request inherits it, and the
            // login test below fills it in.
            'auth' => [
                'type' => 'bearer',
                'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
            ],
            // Defined here as well as in the environment file, so the
            // collection works on its own if someone imports only that.
            'variable' => [
                ['key' => 'base_url', 'value' => 'http://127.0.0.1:8000', 'type' => 'string'],
                ['key' => 'token', 'value' => '', 'type' => 'string'],
                ['key' => 'locale', 'value' => 'ar', 'type' => 'string'],
            ],
            'item' => $this->folders($spec),
        ];
    }

    /**
     * One folder per OpenAPI tag, in the order the spec declares them.
     *
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function folders(array $spec): array
    {
        $byTag = [];

        foreach ($spec['paths'] ?? [] as $path => $operations) {
            $pathParameters = $operations['parameters'] ?? [];

            foreach ($operations as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $tag = $operation['tags'][0] ?? 'Other';
                $byTag[$tag][] = $this->request($spec, $path, $method, $operation, $pathParameters);
            }
        }

        $folders = [];

        foreach ($spec['tags'] ?? [] as $tag) {
            if (! isset($byTag[$tag['name']])) {
                continue;
            }

            $folders[] = [
                'name' => $tag['name'],
                'description' => $tag['description'] ?? '',
                'item' => $byTag[$tag['name']],
            ];

            unset($byTag[$tag['name']]);
        }

        // Anything tagged with something the spec never declared.
        foreach ($byTag as $name => $items) {
            $folders[] = ['name' => $name, 'item' => $items];
        }

        return $folders;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $operation
     * @param  list<array<string, mixed>>  $pathParameters
     * @return array<string, mixed>
     */
    private function request(array $spec, string $path, string $method, array $operation, array $pathParameters): array
    {
        $parameters = array_merge(
            array_map(fn ($p) => $this->resolve($spec, $p), $pathParameters),
            array_map(fn ($p) => $this->resolve($spec, $p), $operation['parameters'] ?? []),
        );

        $query = [];

        foreach ($parameters as $parameter) {
            if (($parameter['in'] ?? null) !== 'query') {
                continue;
            }

            $query[] = [
                'key' => $parameter['name'],
                'value' => (string) ($parameter['example'] ?? $parameter['schema']['default'] ?? ''),
                'description' => $parameter['description'] ?? '',
                // Optional params come in disabled so the request works as-is.
                'disabled' => ! ($parameter['required'] ?? false),
            ];
        }

        $segments = array_values(array_filter(explode('/', $path)));

        $request = [
            'method' => strtoupper($method),
            'header' => [
                ['key' => 'Accept', 'value' => 'application/json'],
                ['key' => 'Accept-Language', 'value' => '{{locale}}'],
            ],
            'url' => [
                'raw' => '{{base_url}}/api/v1'.$path.($query === [] ? '' : '?'.$this->queryString($query)),
                'host' => ['{{base_url}}'],
                'path' => array_merge(['api', 'v1'], $segments),
            ],
            'description' => trim($operation['description'] ?? $operation['summary'] ?? ''),
        ];

        if ($query !== []) {
            $request['url']['query'] = $query;
        }

        $body = $this->body($spec, $operation);

        if ($body !== null) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body'] = ['mode' => 'raw', 'raw' => $body, 'options' => ['raw' => ['language' => 'json']]];
        }

        if (($operation['security'] ?? null) === []) {
            $request['auth'] = ['type' => 'noauth'];
        }

        $item = [
            'name' => $operation['summary'] ?? $path,
            'request' => $request,
            'response' => [],
        ];

        if (($operation['operationId'] ?? null) === 'login') {
            $item['event'] = [$this->captureTokenScript()];
        }

        return $item;
    }

    /**
     * Builds a sample body from the request schema's examples, so every request
     * arrives ready to send rather than as an empty box.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $operation
     */
    private function body(array $spec, array $operation): ?string
    {
        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;

        if ($schema === null) {
            return null;
        }

        $sample = $this->sample($spec, $this->resolve($spec, $schema));

        return $sample === [] ? '{}' : $this->encode($sample);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $schema
     */
    private function sample(array $spec, array $schema): mixed
    {
        $schema = $this->resolve($spec, $schema);

        if (isset($schema['example'])) {
            return $schema['example'];
        }

        $type = $schema['type'] ?? 'object';

        if ($type === 'object' || isset($schema['properties'])) {
            $required = $schema['required'] ?? [];
            $out = [];

            foreach ($schema['properties'] ?? [] as $name => $property) {
                // Keep the body to what is actually required, plus anything
                // carrying an example — otherwise it fills with noise.
                if (! in_array($name, $required, true) && ! isset($property['example'])) {
                    continue;
                }

                $out[$name] = $this->sample($spec, $property);
            }

            return $out;
        }

        return match ($type) {
            'integer' => 1,
            'number' => 0,
            'boolean' => false,
            'array' => [],
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function resolve(array $spec, array $node): array
    {
        if (! isset($node['$ref'])) {
            return $node;
        }

        $segments = array_slice(explode('/', $node['$ref']), 1);
        $target = $spec;

        foreach ($segments as $segment) {
            $target = $target[$segment] ?? [];
        }

        return $this->resolve($spec, $target);
    }

    /**
     * @return array<string, mixed>
     */
    private function captureTokenScript(): array
    {
        return [
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    '// Saves the token so every other request just works.',
                    'const body = pm.response.json();',
                    '',
                    'if (pm.response.code === 200 && body.data && body.data.token) {',
                    "    pm.collectionVariables.set('token', body.data.token);",
                    "    console.log('Token saved for ' + body.data.title);",
                    '}',
                    '',
                    "pm.test('signed in', function () {",
                    '    pm.response.to.have.status(200);',
                    "    pm.expect(body.status).to.eql('success');",
                    '});',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function environment(array $spec): array
    {
        return [
            'id' => $this->uuidFrom($spec['info']['title'].'-env'),
            'name' => $spec['info']['title'].' — local',
            'values' => [
                ['key' => 'base_url', 'value' => 'http://127.0.0.1:8000', 'enabled' => true, 'type' => 'default'],
                ['key' => 'token', 'value' => '', 'enabled' => true, 'type' => 'secret'],
                ['key' => 'locale', 'value' => 'ar', 'enabled' => true, 'type' => 'default'],
            ],
            '_postman_variable_scope' => 'environment',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $query
     */
    private function queryString(array $query): string
    {
        return implode('&', array_map(
            static fn (array $p): string => $p['key'].'='.$p['value'],
            $query,
        ));
    }

    /**
     * Stable id so regenerating does not create a duplicate collection.
     */
    private function uuidFrom(string $seed): string
    {
        $hash = md5($seed);

        return implode('-', [
            substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4),
            substr($hash, 16, 4), substr($hash, 20, 12),
        ]);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function countOperations(array $spec): int
    {
        $count = 0;

        foreach ($spec['paths'] ?? [] as $operations) {
            foreach ($operations as $method => $_) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
