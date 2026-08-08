<?php

namespace App\Http\Controllers\Docs;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * Serves docs/api/v1/openapi.yaml as a browsable reference.
 *
 * The YAML is converted to JSON here rather than parsed in the browser, so the
 * page needs no YAML library and the spec stays a single file on disk.
 */
class ApiReferenceController
{
    public function page(): View
    {
        $this->guard();

        return view('docs.api-reference', [
            'title' => $this->parsed()['info']['title'] ?? 'API',
            'specUrl' => route('docs.api.spec'),
        ]);
    }

    /**
     * The spec itself, as JSON — what the renderer fetches.
     */
    public function document(): JsonResponse
    {
        $this->guard();

        return response()->json(
            $this->visibleDocument(),
            200,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleDocument(): array
    {
        $document = $this->parsed();
        $hiddenTags = config('clinic.docs.hidden_tags', []);

        if (! is_array($hiddenTags) || $hiddenTags === []) {
            return $document;
        }

        $document['tags'] = array_values(array_filter(
            $document['tags'] ?? [],
            static fn (array $tag): bool => ! in_array($tag['name'] ?? null, $hiddenTags, true),
        ));

        $document['paths'] = $this->withoutHiddenOperations($document['paths'] ?? [], $hiddenTags);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsed(): array
    {
        $path = base_path(config('clinic.docs.spec'));

        if (! is_file($path)) {
            throw new NotFoundHttpException('The API spec has not been written yet.');
        }

        // Keyed on the file's mtime, so editing the spec shows up on a refresh
        // without clearing anything.
        return Cache::remember(
            'api-docs.spec.'.filemtime($path),
            now()->addHour(),
            fn () => Yaml::parseFile($path),
        );
    }

    /**
     * @param  array<string, mixed>  $paths
     * @param  list<string>  $hiddenTags
     * @return array<string, mixed>
     */
    private function withoutHiddenOperations(array $paths, array $hiddenTags): array
    {
        $methods = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];
        $visible = [];

        foreach ($paths as $path => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            $visiblePathItem = $pathItem;
            $hasVisibleOperation = false;

            foreach ($methods as $method) {
                if (! isset($pathItem[$method]) || ! is_array($pathItem[$method])) {
                    continue;
                }

                $tags = $pathItem[$method]['tags'] ?? [];

                if (array_intersect($tags, $hiddenTags) !== []) {
                    unset($visiblePathItem[$method]);

                    continue;
                }

                $hasVisibleOperation = true;
            }

            if ($hasVisibleOperation) {
                $visible[$path] = $visiblePathItem;
            }
        }

        return $visible;
    }

    private function guard(): void
    {
        if (! config('clinic.docs.enabled')) {
            throw new NotFoundHttpException;
        }
    }
}
