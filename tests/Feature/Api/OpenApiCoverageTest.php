<?php

namespace Tests\Feature\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps docs/api/v1/openapi.yaml honest.
 *
 * The spec is the contract the Flutter team builds against and the source the
 * Postman collection is generated from. Documentation that drifts is worse than
 * none, so drift is a failing test rather than something noticed months later.
 */
class OpenApiCoverageTest extends TestCase
{
    /**
     * Routed intentionally, but withheld from the mobile handoff docs.
     *
     * @var list<string>
     */
    private const HIDDEN_OPERATIONS = [
        'GET /auth/me',
    ];

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $path = base_path('docs/api/v1/openapi.yaml');

        $this->assertFileExists($path, 'The OpenAPI spec is missing.');

        return Yaml::parseFile($path);
    }

    /**
     * "METHOD /path" for everything mounted under /api/v1.
     *
     * @return list<string>
     */
    private function registeredOperations(): array
    {
        $operations = [];

        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $operations[] = $method.' '.substr($route->uri(), strlen('api/v1'));
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    /**
     * @return list<string>
     */
    private function documentedOperations(): array
    {
        $operations = [];

        foreach ($this->spec()['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $_) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $operations[] = strtoupper($method).' '.$path;
            }
        }

        sort($operations);

        return $operations;
    }

    public function test_every_route_is_documented(): void
    {
        $missing = array_diff(
            $this->registeredOperations(),
            $this->documentedOperations(),
            self::HIDDEN_OPERATIONS,
        );

        $this->assertSame(
            [],
            array_values($missing),
            "These routes exist but are not in openapi.yaml:\n  ".implode("\n  ", $missing),
        );
    }

    public function test_the_spec_documents_nothing_that_does_not_exist(): void
    {
        $phantom = array_diff($this->documentedOperations(), $this->registeredOperations());

        $this->assertSame(
            [],
            array_values($phantom),
            "These are documented but no longer routed:\n  ".implode("\n  ", $phantom),
        );
    }

    public function test_every_operation_is_described(): void
    {
        $bare = [];

        foreach ($this->spec()['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                if (blank($operation['summary'] ?? null) || blank($operation['operationId'] ?? null)) {
                    $bare[] = strtoupper($method).' '.$path;
                }
            }
        }

        $this->assertSame([], $bare, 'Every operation needs a summary and an operationId.');
    }

    /**
     * Postman folders come from tags, so an untagged operation would land in a
     * catch-all folder rather than where the reader expects it.
     */
    public function test_every_operation_is_tagged_with_a_declared_tag(): void
    {
        $declared = array_column($this->spec()['tags'] ?? [], 'name');
        $unknown = [];

        foreach ($this->spec()['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                foreach ($operation['tags'] ?? ['(none)'] as $tag) {
                    if (! in_array($tag, $declared, true)) {
                        $unknown[] = strtoupper($method).' '.$path.' → '.$tag;
                    }
                }
            }
        }

        $this->assertSame([], $unknown, 'Undeclared tags: '.implode(', ', $unknown));
    }

    /**
     * The generated collection is committed, so it must not lag behind the spec.
     */
    public function test_the_committed_postman_collection_is_up_to_date(): void
    {
        $path = base_path('docs/api/v1/doctor1.postman_collection.json');

        $this->assertFileExists($path, 'Run: php artisan api:postman');

        $committed = json_decode((string) file_get_contents($path), true);

        $requests = 0;

        foreach ($committed['item'] ?? [] as $folder) {
            $requests += count($folder['item'] ?? []);
        }

        $this->assertSame(
            count($this->documentedOperations()),
            $requests,
            'The collection is stale — run: php artisan api:postman',
        );
    }
}
