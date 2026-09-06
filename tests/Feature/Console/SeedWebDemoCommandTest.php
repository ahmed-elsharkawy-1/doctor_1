<?php

namespace Tests\Feature\Console;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class SeedWebDemoCommandTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-03 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_fresh_refuses_to_delete_bookings_in_production_without_explicit_force(): void
    {
        app()->detectEnvironment(fn () => 'production');

        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', $this->clinic->timezone))
            ->create();

        $this->artisan('clinic:seed-web-demo', [
            '--clinic' => $this->clinic->id,
            '--fresh' => true,
        ])->assertFailed();

        $this->assertSame(1, Booking::count());
    }
}
