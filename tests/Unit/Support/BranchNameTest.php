<?php

namespace Tests\Unit\Support;

use App\Support\BranchName;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchNameTest extends TestCase
{
    /** @return array<string, array{?string, ?string, string}> */
    public static function addresses(): array
    {
        return [
            'street, area, governorate' => ['١٢ شارع مصدق، الدقي، الجيزة', 'الجيزة', 'عيادة الدقي'],
            'street, area' => ['15 شارع الجمهورية، جديلة', 'المنصورة', 'عيادة جديلة'],
            'latin commas' => ['5 El Tahrir St, Tur\'a', null, 'عيادة Tur\'a'],
            'only a street' => ['١٢ شارع مصدق', 'الجيزة', 'عيادة د. سارة'],
            'street then the city alone' => ['١٢ شارع مصدق، الجيزة', 'الجيزة', 'عيادة د. سارة'],
            'a building number, not an area' => ['شارع مصدق، 12', null, 'عيادة د. سارة'],
            'no address' => [null, 'الجيزة', 'عيادة د. سارة'],
            'blank address' => ['   ', null, 'عيادة د. سارة'],
        ];
    }

    public function test_a_long_clinic_name_is_cut_to_what_a_branch_name_holds(): void
    {
        $name = BranchName::forFirstBranch(null, null, str_repeat('ع', 180));

        $this->assertSame(100, mb_strlen($name));
    }

    #[DataProvider('addresses')]
    public function test_the_first_branch_is_named_after_the_clinics_area(?string $address, ?string $city, string $expected): void
    {
        $this->assertSame($expected, BranchName::forFirstBranch($address, $city, 'عيادة د. سارة'));
    }
}
