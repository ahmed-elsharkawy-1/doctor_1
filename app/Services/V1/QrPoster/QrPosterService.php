<?php

namespace App\Services\V1\QrPoster;

use App\Models\Clinic;
use App\Models\ClinicQrPoster;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * A clinic's printable QR poster: the doctor's name and specialty, a code
 * that opens the clinic's public page, and the platform's mark.
 *
 * Nothing is stored but the operator's adjustments. The poster is drawn from
 * the clinic and its doctor every time it is opened, so every clinic has one
 * from the moment it is created and it follows every change to the doctor's
 * details. The image itself is rendered by the browser — see the panel page —
 * because only a browser shapes Arabic text correctly.
 */
class QrPosterService
{
    /**
     * Roughly how wide a Tajawal glyph is, as a share of the font size. Only
     * used to decide where a long specialty line wraps; the page measures the
     * real text afterwards and shrinks anything that still does not fit.
     */
    private const GLYPH_WIDTH = 0.48;

    /** The adjustable fields, in the order the panel shows them. */
    public const TEXT_FIELDS = ['name', 'title', 'call_to_action'];

    public const SIZE_FIELDS = ['name_size', 'title_size', 'qr_size'];

    /**
     * The address the code opens: the clinic's public page, marked as coming
     * from the poster.
     */
    public function url(Clinic $clinic): string
    {
        $tracking = config('clinic.qr_poster.tracking');

        return route('landing', [
            'slug' => $clinic->slug,
            $tracking['param'] => $tracking['value'],
        ]);
    }

    /**
     * What the poster says when nobody has adjusted it.
     *
     * @return array{name: string, title: string, call_to_action: string, name_size: int, title_size: int, qr_size: int}
     */
    public function defaults(Clinic $clinic): array
    {
        $doctor = $clinic->doctor;

        return [
            'name' => (string) ($doctor?->name ?: $clinic->name),
            // The same fallback the public page uses.
            'title' => (string) ($doctor?->title ?: $clinic->specialty?->name_ar),
            // In the public pages' language, whatever the panel is set to.
            'call_to_action' => __('qr_poster.call_to_action', [], config('clinic.api.default_locale')),
            'name_size' => $this->size('name')['default'],
            'title_size' => $this->size('title')['default'],
            'qr_size' => $this->size('qr')['default'],
        ];
    }

    /**
     * The poster's current settings: the operator's adjustments over the
     * defaults. What the panel form opens with.
     *
     * @return array{name: string, title: string, call_to_action: string, name_size: int, title_size: int, qr_size: int}
     */
    public function settings(Clinic $clinic): array
    {
        $settings = $this->defaults($clinic);
        $saved = $clinic->qrPoster;

        foreach ([...self::TEXT_FIELDS, ...self::SIZE_FIELDS] as $field) {
            if ($saved?->{$field} !== null) {
                $settings[$field] = $saved->{$field};
            }
        }

        return $settings;
    }

    /**
     * Keeps the operator's adjustments.
     *
     * Only what differs from the default is stored, so a field left alone
     * keeps following the doctor's details. A poster with nothing adjusted has
     * no row at all.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Clinic $clinic, array $data): void
    {
        $overrides = $this->overrides($clinic, $data);

        if (array_filter($overrides, fn ($value) => $value !== null) === []) {
            $this->reset($clinic);

            return;
        }

        ClinicQrPoster::query()->updateOrCreate(['clinic_id' => $clinic->id], $overrides);
        $clinic->unsetRelation('qrPoster');
    }

    /** Back to the defaults. */
    public function reset(Clinic $clinic): void
    {
        $clinic->qrPoster()->delete();
        $clinic->unsetRelation('qrPoster');
    }

    /**
     * The poster, laid out.
     *
     * `$draft` is what the operator is typing and has not saved yet, so the
     * preview can follow the form; it goes through the same limits as a save.
     *
     * @param  array<string, mixed>|null  $draft
     */
    public function build(Clinic $clinic, ?array $draft = null): QrPoster
    {
        $settings = $this->settings($clinic);

        if ($draft !== null) {
            $defaults = $this->defaults($clinic);

            foreach ($this->overrides($clinic, $draft) as $field => $value) {
                $settings[$field] = $value ?? $defaults[$field];
            }
        }

        $config = config('clinic.qr_poster');
        $width = (int) $config['width'];
        $height = (int) $config['height'];
        $textWidth = $width - 2 * (int) $config['side_margin'];

        $nameSize = $settings['name_size'];
        $titleSize = $settings['title_size'];
        $callToActionSize = (int) $config['call_to_action_size'];

        // Top down: the name, then the specialty lines under it.
        $nameY = (int) round(300 + $nameSize * 0.8);
        $cursor = $nameY + $nameSize * 0.3;
        $titleLines = [];

        foreach ($this->wrap($settings['title'], $titleSize, $textWidth, (int) $config['title_max_lines']) as $line) {
            $cursor += $titleSize * 1.3;
            $titleLines[] = ['text' => $line, 'y' => (int) round($cursor)];
        }

        $headerBottom = $cursor + $titleSize * 0.4;

        // Bottom up: the readable address, and the platform's mark above it.
        $domainSize = 64;
        $domainY = $height - 170;
        [$logo, $logoRatio] = $this->logo();
        $logoHeight = 190;
        $logoWidth = (int) round($logoHeight * $logoRatio);
        $logoY = $domainY - $domainSize - 50 - $logoHeight;

        // The code and the line under it share what is left, centred in it.
        $callToAction = $this->lines($settings['call_to_action'], 3);
        $callToActionHeight = count($callToAction) * $callToActionSize * 1.35;
        $gap = 110;
        $room = $logoY - $headerBottom - 2 * 100;

        // Shrunk rather than overlapped when the operator's choices do not all
        // fit — a big title and a big code can never collide.
        $qrSize = (int) max(400, min($settings['qr_size'], $textWidth, $room - $gap - $callToActionHeight));
        $blockTop = $headerBottom + max(0, ($logoY - $headerBottom - ($qrSize + $gap + $callToActionHeight)) / 2);

        $callToActionLines = [];
        $lineY = $blockTop + $qrSize + $gap + $callToActionSize * 0.9;

        foreach ($callToAction as $line) {
            $callToActionLines[] = ['text' => $line, 'y' => (int) round($lineY)];
            $lineY += $callToActionSize * 1.35;
        }

        $url = $this->url($clinic);

        return new QrPoster(
            url: $url,
            fileName: 'qr-poster-'.$clinic->slug.'.png',
            width: $width,
            height: $height,
            textWidth: $textWidth,
            name: $this->singleLine($settings['name']),
            nameSize: $nameSize,
            nameY: $nameY,
            titleLines: $titleLines,
            titleSize: $titleSize,
            qr: $this->code($url, (int) round(($width - $qrSize) / 2), (int) round($blockTop), $qrSize),
            callToActionLines: $callToActionLines,
            callToActionSize: $callToActionSize,
            logo: $logo,
            logoY: (int) round($logoY),
            logoHeight: $logoHeight,
            logoWidth: $logoWidth,
            // Printed under the mark for anyone who cannot scan: the page's
            // address without the tracking marker, which nobody would type.
            domain: preg_replace('#^https?://#', '', route('landing', ['slug' => $clinic->slug])),
            domainY: $domainY,
            domainSize: $domainSize,
            colors: $this->colors(),
        );
    }

    /**
     * The fonts the poster is drawn in, embedded, so the image the browser
     * renders from it needs nothing from the network.
     */
    public function fontFaces(): string
    {
        $faces = '';

        foreach ([500, 800] as $weight) {
            foreach (['arabic', 'latin'] as $subset) {
                $file = public_path("fonts/tajawal/tajawal-{$weight}-{$subset}.woff2");

                if (! is_file($file)) {
                    continue;
                }

                $faces .= "@font-face{font-family:'Tajawal Poster';font-weight:{$weight};"
                    .'src:url(data:font/woff2;base64,'.base64_encode((string) file_get_contents($file)).") format('woff2');}";
            }
        }

        return $faces;
    }

    /**
     * Stored values for what the operator sent: trimmed, held inside the size
     * limits, and null wherever it matches the default.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|int|null>
     */
    private function overrides(Clinic $clinic, array $data): array
    {
        $defaults = $this->defaults($clinic);
        $overrides = [];

        foreach (self::TEXT_FIELDS as $field) {
            $value = $this->normaliseText($data[$field] ?? null);
            $overrides[$field] = ($value === null || $value === $this->normaliseText($defaults[$field])) ? null : $value;
        }

        foreach (self::SIZE_FIELDS as $field) {
            $limits = $this->size(substr($field, 0, -5));
            $value = isset($data[$field]) && is_numeric($data[$field])
                ? (int) max($limits['min'], min($limits['max'], (int) $data[$field]))
                : null;
            $overrides[$field] = $value === $defaults[$field] ? null : $value;
        }

        return $overrides;
    }

    private function normaliseText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Each line trimmed and blank lines dropped: the line breaks are the
        // operator's choice of where text wraps, the spacing around them is not.
        $lines = array_filter(array_map('trim', preg_split('/\R/u', $value) ?: []), fn (string $line) => $line !== '');

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * The specialty line as it will be drawn.
     *
     * The operator's own line breaks win. Without any, a line too long for the
     * poster is split into as few even lines as it needs, at word boundaries.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $size, int $width, int $maxLines): array
    {
        $lines = $this->lines($text, $maxLines);

        if (count($lines) !== 1) {
            return $lines;
        }

        $estimated = mb_strlen($lines[0]) * $size * self::GLYPH_WIDTH;
        $count = (int) min($maxLines, max(1, ceil($estimated / $width)));

        return $count === 1 ? $lines : $this->balance($lines[0], $count);
    }

    /**
     * Splits text at word boundaries into `$count` lines of about the same
     * length.
     *
     * @return list<string>
     */
    private function balance(string $text, int $count): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $target = mb_strlen($text) / $count;
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            // Break before the word that takes the line further past the
            // target than stopping short of it would leave it.
            if ($current !== ''
                && count($lines) < $count - 1
                && mb_strlen($candidate) - $target > $target - mb_strlen($current)) {
                $lines[] = $current;
                $current = $word;

                continue;
            }

            $current = $candidate;
        }

        $lines[] = $current;

        return $lines;
    }

    /**
     * Text split on the line breaks typed into it, at most `$max` lines; any
     * beyond that join the last one.
     *
     * @return list<string>
     */
    private function lines(string $text, int $max): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $text) ?: []),
            fn (string $line) => $line !== '',
        ));

        if (count($lines) > $max) {
            $lines = [...array_slice($lines, 0, $max - 1), implode(' ', array_slice($lines, $max - 1))];
        }

        return $lines;
    }

    private function singleLine(string $text): string
    {
        return implode(' ', $this->lines($text, 1));
    }

    /**
     * The code as one SVG path, in module units, plus where its white tile
     * sits on the poster.
     *
     * @return array{x: int, y: int, size: int, radius: int, modules: int, moduleSize: float, padding: float, path: string}
     */
    private function code(string $url, int $x, int $y, int $size): array
    {
        $matrix = (new QRCode(new QROptions([
            'eccLevel' => constant(EccLevel::class.'::'.config('clinic.qr_poster.error_correction')),
            // The white tile is the quiet zone.
            'addQuietzone' => false,
        ])))->addByteSegment($url)->getQRMatrix();

        $modules = $matrix->getSize();

        // Three and a half modules of white on every side: comfortably inside
        // what scanners need, without shrinking the code for nothing.
        $moduleSize = $size / ($modules + 7);
        $path = '';

        // One rectangle per run of dark modules in a row, so a 33×33 code is
        // a few hundred segments rather than a thousand squares.
        for ($row = 0; $row < $modules; $row++) {
            $column = 0;

            while ($column < $modules) {
                if (! $matrix->check($column, $row)) {
                    $column++;

                    continue;
                }

                $start = $column;

                while ($column < $modules && $matrix->check($column, $row)) {
                    $column++;
                }

                $path .= 'M'.$start.' '.$row.'h'.($column - $start).'v1h-'.($column - $start).'z';
            }
        }

        return [
            'x' => $x,
            'y' => $y,
            'size' => $size,
            'radius' => (int) round($size * 0.06),
            'modules' => $modules,
            'moduleSize' => $moduleSize,
            'padding' => $moduleSize * 3.5,
            'path' => $path,
        ];
    }

    /**
     * The platform's white mark, embedded, and its width as a multiple of its
     * height.
     *
     * @return array{0: string, 1: float}
     */
    private function logo(): array
    {
        $file = public_path(config('clinic.brand.logo_white'));

        if (! is_file($file)) {
            return ['', 1.0];
        }

        $dimensions = getimagesize($file) ?: [1, 1];
        $mime = mime_content_type($file) ?: 'image/png';

        return [
            'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file)),
            $dimensions[0] / max(1, $dimensions[1]),
        ];
    }

    /**
     * @return array{background: string, band: string, text: string, tile: string, modules: string}
     */
    private function colors(): array
    {
        $colors = config('clinic.qr_poster.colors');
        $colors['background'] ??= config('clinic.brand.color');

        return $colors;
    }

    /**
     * @return array{default: int, min: int, max: int}
     */
    public function size(string $element): array
    {
        return array_map('intval', config("clinic.qr_poster.sizes.{$element}"));
    }
}
