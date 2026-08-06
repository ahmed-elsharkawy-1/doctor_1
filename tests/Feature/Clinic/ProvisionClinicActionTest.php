<?php

namespace Tests\Feature\Clinic;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Enums\DayOfWeek;
use App\Models\Clinic;
use App\Models\Specialty;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionClinicActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): ProvisionClinicAction
    {
        return app(ProvisionClinicAction::class);
    }

    public function test_it_copies_the_specialtys_default_visit_types(): void
    {
        $this->seed(SpecialtySeeder::class);

        $dentistry = Specialty::where('slug', 'dentistry')->firstOrFail();
        $clinic = Clinic::factory()->create(['specialty_id' => $dentistry->id]);

        $this->action()->execute($clinic);

        $this->assertSame(
            ['كشف', 'حشو', 'خلع', 'تنظيف'],
            $clinic->visitTypes()->orderBy('sort_order')->pluck('name')->all(),
        );

        $this->assertSame(45, $clinic->visitTypes()->where('name', 'تنظيف')->value('duration_minutes'));
    }

    public function test_seeded_visit_types_start_with_a_zero_price(): void
    {
        $this->seed(SpecialtySeeder::class);

        $clinic = Clinic::factory()->create([
            'specialty_id' => Specialty::where('slug', 'general')->value('id'),
        ]);

        $this->action()->execute($clinic);

        $this->assertEquals(0, (float) $clinic->visitTypes()->first()->price);
    }

    public function test_it_creates_all_seven_days_closed(): void
    {
        $clinic = Clinic::factory()->create();

        $this->action()->execute($clinic);

        $this->assertSame(7, $clinic->schedules()->count());
        $this->assertSame(0, $clinic->schedules()->where('is_open', true)->count());

        $days = $clinic->schedules()->orderBy('day_of_week')->pluck('day_of_week')->all();

        $this->assertEquals(DayOfWeek::week(), $days);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(SpecialtySeeder::class);

        $clinic = Clinic::factory()->create([
            'specialty_id' => Specialty::where('slug', 'pediatrics')->value('id'),
        ]);

        $this->action()->execute($clinic);
        $visitTypeCount = $clinic->visitTypes()->count();

        $this->action()->execute($clinic);

        $this->assertSame($visitTypeCount, $clinic->visitTypes()->count());
        $this->assertSame(7, $clinic->schedules()->count());
    }

    public function test_it_does_not_overwrite_edited_visit_types(): void
    {
        $this->seed(SpecialtySeeder::class);

        $clinic = Clinic::factory()->create([
            'specialty_id' => Specialty::where('slug', 'general')->value('id'),
        ]);

        $this->action()->execute($clinic);

        $clinic->visitTypes()->first()->update(['price' => 350, 'duration_minutes' => 25]);

        $this->action()->execute($clinic);

        $visitType = $clinic->visitTypes()->first();
        $this->assertEquals(350, (float) $visitType->price);
        $this->assertSame(25, $visitType->duration_minutes);
    }
}
