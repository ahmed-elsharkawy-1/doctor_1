<?php

namespace Tests\Feature\Console;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SeedPilotDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_pilot_doctor_with_twenty_future_bookings(): void
    {
        $this->travelTo(Carbon::parse('2026-08-31 08:00:00', 'Africa/Cairo'));

        $this->artisan('clinic:seed-pilot-doctor')
            ->assertSuccessful();

        $user = User::where('email', 'drseham@gmail.com')->firstOrFail();
        $clinic = Clinic::where('name', 'عيادة د. سهام عبدالعزيز')->firstOrFail();

        $this->assertSame('سهام عبدالعزيز', $user->name);
        $this->assertSame(UserRole::CLINIC, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->belongsToClinic($clinic->id));
        $this->assertSame($clinic->doctor?->id, $user->doctor_id);

        $this->assertSame(20, $clinic->patients()->count());
        $this->assertSame(20, $clinic->bookings()->count());

        $bookings = $clinic->bookings()->get();

        $this->assertTrue($bookings->every(fn ($booking) => $booking->status === BookingStatus::BOOKED));
        $this->assertTrue($bookings->every(fn ($booking) => $booking->booking_kind === BookingKind::NORMAL));
        $this->assertTrue($bookings->every(fn ($booking) => $booking->start_at !== null));

        $this->assertSame(
            10,
            $clinic->bookings()->whereDate('visit_date', '2026-09-01')->count(),
        );

        foreach (['2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
            $this->assertGreaterThan(0, $clinic->bookings()->whereDate('visit_date', $date)->count());
        }
    }

    public function test_it_does_not_duplicate_seeded_bookings_when_rerun(): void
    {
        $this->travelTo(Carbon::parse('2026-08-31 08:00:00', 'Africa/Cairo'));

        $this->artisan('clinic:seed-pilot-doctor')->assertSuccessful();
        $this->artisan('clinic:seed-pilot-doctor')->assertSuccessful();

        $clinic = Clinic::where('name', 'عيادة د. سهام عبدالعزيز')->firstOrFail();

        $this->assertSame(20, $clinic->patients()->count());
        $this->assertSame(20, $clinic->bookings()->count());
        $this->assertSame(10, $clinic->bookings()->whereDate('visit_date', '2026-09-01')->count());
    }
}
