<?php

namespace App\Http\Controllers\Docs;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

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
            'designMapUrl' => route('docs.api.design-map'),
        ]);
    }

    /**
     * The tester's first page: the two environments, the API, and the one
     * test clinic's logins. Public, so it prints no login that guards real
     * data — the pilot clinic appears by name and email only.
     */
    public function handoff(): View
    {
        $this->guard();

        $pilot = $this->clinicFor(config('clinic.docs.pilot_account'));

        return view('docs.handoff', [
            'productionUrl' => rtrim((string) config('clinic.docs.production_url'), '/'),
            'stagingUrl' => rtrim((string) config('clinic.docs.staging_url'), '/'),
            'panelPath' => trim((string) config('clinic.panel.path'), '/'),
            'apiDocsUrl' => route('docs.api'),
            'openApiUrl' => route('docs.api.spec'),
            'designMapUrl' => route('docs.api.design-map'),
            'pilotClinic' => $pilot,
            'pilotEmail' => config('clinic.docs.pilot_account'),
            'pilotLandingUrl' => $pilot?->slug === null ? null : url($pilot->slug),
        ]);
    }

    private function clinicFor(?string $email): ?Clinic
    {
        try {
            return User::query()
                ->where('email', $email)
                ->first()
                ?->activeClinic();
        } catch (Throwable) {
            // The rest of the page is a static reference; it should still
            // render when the database is unreachable.
            return null;
        }
    }

    public function designMap(): View
    {
        $this->guard();

        $path = base_path('docs/api/v1/design-api-map.md');

        if (! is_file($path)) {
            throw new NotFoundHttpException('The API design map has not been written yet.');
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return view('docs.design-map', [
            'title' => 'Doctor 1 API to design map',
            'html' => $converter->convert(file_get_contents($path))->getContent(),
            'apiDocsUrl' => route('docs.api'),
            'handoffUrl' => route('docs.api.handoff'),
            'openApiUrl' => route('docs.api.spec'),
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
