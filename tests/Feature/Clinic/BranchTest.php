<?php

namespace Tests\Feature\Clinic;

use App\Models\Branch;
use App\Models\Clinic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_clinic_gets_its_first_branch(): void
    {
        $clinic = Clinic::factory()->create([
            'name' => 'عيادة د. سارة',
            'address' => '١٢ شارع مصدق، الدقي، الجيزة',
            'city' => 'الجيزة',
        ]);

        $this->assertCount(1, $clinic->branches);

        $branch = $clinic->branches->first();
        $this->assertSame('عيادة الدقي', $branch->name);
        $this->assertTrue($branch->is_active);
        // Nothing is copied: the clinic stays the one place these are edited.
        $this->assertNull($branch->address);
        $this->assertNull($branch->phone);
    }

    public function test_the_backfill_gives_every_clinic_without_a_branch_exactly_one(): void
    {
        $bare = Clinic::factory()->create(['name' => 'عيادة أ', 'address' => null]);
        $kept = Clinic::factory()->create();
        $second = Branch::factory()->create(['clinic_id' => $kept->id]);
        DB::table('branches')->where('clinic_id', $bare->id)->delete();

        $migration = require database_path('migrations/2026_11_25_100000_create_branches_table.php');
        $migration->backfill();
        $migration->backfill();

        $this->assertSame(['عيادة أ'], $bare->branches()->pluck('name')->all());
        $this->assertSame(2, $kept->branches()->count());
        $this->assertTrue($kept->branches()->whereKey($second->id)->exists());
    }

    public function test_one_run_of_the_backfill_reaches_every_clinic_past_the_first_chunk(): void
    {
        // Copies of one clinic row: the factory's unique names run out first.
        $row = (array) DB::table('clinics')->find(Clinic::factory()->create()->id);
        unset($row['id']);
        DB::table('clinics')->insert(array_map(
            fn (int $i) => ['name' => 'عيادة '.$i, 'slug' => null] + $row,
            range(1, 449),
        ));
        DB::table('branches')->delete();

        (require database_path('migrations/2026_11_25_100000_create_branches_table.php'))->backfill();

        $this->assertSame(0, Clinic::query()->doesntHave('branches')->count());
        $this->assertSame(450, Branch::query()->count());
    }

    public function test_a_clinic_with_a_long_name_and_no_area_still_gets_its_branch(): void
    {
        $clinic = Clinic::factory()->create(['name' => str_repeat('ع', 200), 'address' => null]);

        $this->assertSame(100, mb_strlen($clinic->branches()->value('name')));
    }

    public function test_a_branch_without_its_own_details_reads_the_clinics(): void
    {
        $clinic = Clinic::factory()->create([
            'address' => '١٢ شارع مصدق، الدقي',
            'latitude' => 30.0384,
            'longitude' => 31.2101,
            'phone' => '+201012345678',
        ]);
        $branch = $clinic->branches->first();

        $this->assertSame('١٢ شارع مصدق، الدقي', $branch->displayAddress());
        // MySQL pads decimals and SQLite doesn't, so compare the numbers.
        $this->assertEqualsWithDelta([30.0384, 31.2101], array_map('floatval', explode(',', (string) $branch->coordinates())), 0.00001);
        $this->assertSame('+201012345678', $branch->contactPhone()?->e164);
    }

    public function test_a_branch_with_its_own_details_never_mixes_in_the_clinics(): void
    {
        $clinic = Clinic::factory()->create([
            'latitude' => 30.0384,
            'longitude' => 31.2101,
            'phone' => '+201012345678',
        ]);
        $branch = Branch::factory()->create([
            'clinic_id' => $clinic->id,
            'address' => 'شارع الجمهورية، جديلة',
            'latitude' => null,
            'longitude' => null,
            'phone' => '+201099999999',
        ]);

        $this->assertSame('شارع الجمهورية، جديلة', $branch->displayAddress());
        // Its own address with no pin: the clinic's pin would point elsewhere.
        $this->assertNull($branch->coordinates());
        $this->assertSame('+201099999999', $branch->contactPhone()?->e164);
    }

    public function test_deleting_a_clinic_removes_its_branches(): void
    {
        $clinic = Clinic::factory()->create();
        Branch::factory()->create(['clinic_id' => $clinic->id]);

        $clinic->delete();

        $this->assertSame(0, Branch::query()->count());
    }
}
