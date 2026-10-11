<?php

namespace App\Services\V1\QrPoster;

/**
 * Everything one clinic's QR poster draws, already laid out.
 *
 * Positions are in poster pixels on a `width` × `height` canvas, so the view
 * only places what it is given and makes no decisions of its own.
 */
final class QrPoster
{
    /**
     * @param  list<array{text: string, y: int}>  $titleLines
     * @param  list<array{text: string, y: int}>  $callToActionLines
     * @param  array{x: int, y: int, size: int, radius: int, modules: int, moduleSize: float, padding: float, path: string}  $qr
     * @param  array{background: string, band: string, text: string, tile: string, modules: string}  $colors
     */
    public function __construct(
        public readonly string $url,
        public readonly string $fileName,
        public readonly int $width,
        public readonly int $height,
        public readonly int $textWidth,
        public readonly string $name,
        public readonly int $nameSize,
        public readonly int $nameY,
        public readonly array $titleLines,
        public readonly int $titleSize,
        public readonly array $qr,
        public readonly array $callToActionLines,
        public readonly int $callToActionSize,
        public readonly string $logo,
        public readonly int $logoY,
        public readonly int $logoHeight,
        public readonly int $logoWidth,
        public readonly string $domain,
        public readonly int $domainY,
        public readonly int $domainSize,
        public readonly array $colors,
    ) {}
}
