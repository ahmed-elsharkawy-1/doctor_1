<?php

namespace Tests\Unit\Support;

use App\Support\MapLink;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MapLinkTest extends TestCase
{
    /** @return array<string, array{string, ?array{0: float, 1: float}}> */
    public static function links(): array
    {
        return [
            'place link prefers the pin over the map centre' => [
                'https://www.google.com/maps/place/Gedeila/@31.0400000,31.3700000,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d31.0412345!4d31.3712345',
                [31.0412345, 31.3712345],
            ],
            'map centre' => ['https://www.google.com/maps/@30.0444,31.2357,15z', [30.0444, 31.2357]],
            'search path' => ['https://www.google.com/maps/search/30.0444,+31.2357?entry=ttu', [30.0444, 31.2357]],
            'search query' => ['https://maps.google.com/?q=30.0444,31.2357', [30.0444, 31.2357]],
            'api link, encoded comma' => ['https://www.google.com/maps/search/?api=1&query=30.0444%2C31.2357', [30.0444, 31.2357]],
            'short link carries nothing' => ['https://maps.app.goo.gl/AbCdEf123', null],
            'not a map link' => ['عيادة جديلة', null],
            'out of range' => ['https://www.google.com/maps/@95.1,31.2,15z', null],
        ];
    }

    #[DataProvider('links')]
    public function test_it_reads_the_pin_from_a_google_maps_link(string $link, ?array $expected): void
    {
        $this->assertSame($expected, MapLink::coordinates($link));
    }
}
