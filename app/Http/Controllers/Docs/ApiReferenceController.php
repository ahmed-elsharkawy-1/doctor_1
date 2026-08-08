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
            $this->parsed(),
            200,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
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

    private function guard(): void
    {
        if (! config('clinic.docs.enabled')) {
            throw new NotFoundHttpException;
        }
    }
}
