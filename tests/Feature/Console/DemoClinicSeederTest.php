<?php

namespace Tests\Feature\Console;

use App\Models\Clinic;
use Database\Seeders\DemoClinicSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The demo clinic must never be mistaken for — or mistake itself for — a real one.
 *
 * It used to be called exactly what a live clinic is called, and it begins by
 * deleting any clinic of its own name. Run on production by mistake, that
 * would have cascaded through a real doctor's patients and bookings.
 */
class DemoClinicSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        // Called directly, not through db:seed: the command's own "are you
        // sure?" is one keypress (or one --force) away. The seeder is the
        // last line, so it is the one under test.
        $this->expectException(RuntimeException::class);

        $this->app->make(DemoClinicSeeder::class)->run();
    }

    /** Refused before anything is deleted, not halfway through. */
    public function test_a_refused_run_leaves_everything_in_place(): void
    {
        $this->seed(SpecialtySeeder::class);
        $clinic = Clinic::factory()->create(['name' => 'عيادة تجريبية — د. منى عادل']);
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->app->make(DemoClinicSeeder::class)->run();
        } catch (RuntimeException) {
        }

        $this->assertModelExists($clinic);
    }

    /** A real clinic that shares the old demo name survives a re-seed. */
    public function test_it_never_touches_a_clinic_it_did_not_create(): void
    {
        $this->seed(SpecialtySeeder::class);
        $real = Clinic::factory()->create([
            'name' => 'عيادة د. سارة النجار',
            'slug' => 'aayad-d-sar-alngar',
        ]);

        $this->seed(DemoClinicSeeder::class);

        $this->assertModelExists($real);
        $this->assertSame(2, Clinic::count());
    }

    /** Says what it is, so a screenshot from staging cannot pass for a real doctor. */
    public function test_the_demo_clinic_is_named_as_one(): void
    {
        $this->seed(DemoClinicSeeder::class);

        $demo = Clinic::where('slug', 'demo-clinic')->sole();

        $this->assertStringContainsString('تجريبية', $demo->name);
    }
}
