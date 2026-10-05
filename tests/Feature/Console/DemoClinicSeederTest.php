<?php

namespace Tests\Feature\Console;

use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use App\Services\V1\Reports\ClinicPeriodReportService;
use App\Support\TestClinic;
use Database\Seeders\DemoClinicSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
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

    /** The shared test clinic: the same name, address and logins as everywhere else. */
    public function test_it_builds_the_shared_test_clinic(): void
    {
        $this->seed(DemoClinicSeeder::class);

        $clinic = Clinic::where('slug', TestClinic::SLUG)->sole();

        $this->assertSame(TestClinic::NAME, $clinic->name);
        $this->assertTrue($clinic->is_test);
        $this->assertTrue($clinic->reports_enabled);
        $this->assertTrue(Hash::check(TestClinic::DOCTOR_PASSWORD, User::where('email', TestClinic::DOCTOR_EMAIL)->sole()->password));
        $this->assertTrue(Hash::check(TestClinic::ASSISTANT_PASSWORD, User::where('email', TestClinic::ASSISTANT_EMAIL)->sole()->password));
    }

    /** Enough variety that every screen and report section has something to show. */
    public function test_the_data_covers_every_case(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00', 'Africa/Cairo')); // a Monday

        $this->seed(DemoClinicSeeder::class);
        $clinic = Clinic::where('slug', TestClinic::SLUG)->sole();
        $statuses = $clinic->bookings()->pluck('status')->map(fn ($s) => $s->value)->unique()->sort()->values()->all();

        foreach (['booked', 'arrived', 'with_doctor', 'done', 'no_show', 'cancelled'] as $status) {
            $this->assertContains($status, $statuses);
        }

        $this->assertSame(4, $clinic->bookings()->distinct()->count('visit_type_id'));
        $this->assertGreaterThan(0, $clinic->bookings()->where('source', 'patient_web')->count());
        $this->assertGreaterThan(0, $clinic->bookings()->where('booking_kind', 'emergency')->count());
        $this->assertGreaterThan(0, BookingReview::where('clinic_id', $clinic->id)->count());
        $this->assertGreaterThan(0, $clinic->bookings()->whereDate('visit_date', '>', '2026-10-05')->count());
        $this->assertGreaterThan(300, $clinic->bookings()->count());

        // A holiday behind and one ahead.
        $this->assertSame(1, $clinic->holidays()->whereDate('date', '<', '2026-10-05')->count());
        $this->assertSame(1, $clinic->holidays()->whereDate('date', '>', '2026-10-05')->count());

        // Last month has both new and returning patients.
        $report = app(ClinicPeriodReportService::class)->for(
            $clinic,
            ReportPeriod::forMonth(Carbon::parse('2026-09-01', 'Africa/Cairo'), Carbon::parse('2026-10-05', 'Africa/Cairo')),
        );
        $this->assertGreaterThan(0, $report->patients['new_count']);
        $this->assertGreaterThan(0, $report->patients['returning_count']);

        Carbon::setTestNow();
    }

    /** Re-running refreshes the test clinic — and only the test clinic. */
    public function test_running_it_again_refreshes_only_the_test_clinic(): void
    {
        $this->seed(SpecialtySeeder::class);
        $real = Clinic::factory()->create(['slug' => 'real-clinic']);
        Booking::factory()->forClinic($real)->create();

        $this->seed(DemoClinicSeeder::class);
        $first = Clinic::where('slug', TestClinic::SLUG)->sole()->bookings()->count();

        $this->seed(DemoClinicSeeder::class);

        $this->assertSame(1, Clinic::where('is_test', true)->count());
        $this->assertSame($first, Clinic::where('slug', TestClinic::SLUG)->sole()->bookings()->count());
        $this->assertSame(1, $real->bookings()->count());
    }
}
