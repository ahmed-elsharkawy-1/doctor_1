<?php

namespace Tests\Feature\Filament;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\ClinicVisitLengthDay;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin sets a clinic's special-length days on the clinic form.
 */
class ClinicVisitLengthDaysTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-15 20:00:00', 'Africa/Cairo'));

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->clinic = Clinic::factory()->create(['phone' => '+201001234567', 'slug' => 'dr-test']);
        app(ProvisionClinicAction::class)->execute($this->clinic, 'secret-password');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function form(array $days): Testable
    {
        return Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm(['visitLengthDays' => $days])
            ->call('save');
    }

    public function test_the_admin_sets_three_days_to_twenty_minutes(): void
    {
        $this->form([
            ['date' => '2026-11-21', 'minutes' => 20],
            ['date' => '2026-11-22', 'minutes' => 20],
            ['date' => '2026-11-23', 'minutes' => 20],
        ])->assertHasNoFormErrors();

        $this->assertSame(
            ['2026-11-21' => 20, '2026-11-22' => 20, '2026-11-23' => 20],
            $this->clinic->visitLengthDays()->get()->mapWithKeys(
                fn (ClinicVisitLengthDay $day) => [$day->date->toDateString() => $day->minutes],
            )->all(),
        );
    }

    public function test_one_date_cannot_be_listed_twice(): void
    {
        $this->form([
            ['date' => '2026-11-21', 'minutes' => 20],
            ['date' => '2026-11-21', 'minutes' => 30],
        ])->assertHasFormErrors();

        $this->assertSame(0, ClinicVisitLengthDay::count());
    }

    public function test_the_length_must_be_sensible(): void
    {
        $this->form([['date' => '2026-11-21', 'minutes' => 0]])->assertHasFormErrors();
        $this->form([['date' => '2026-11-21', 'minutes' => 500]])->assertHasFormErrors();

        $this->assertSame(0, ClinicVisitLengthDay::count());
    }

    /** Bookings already on the date are named before the day is saved. */
    public function test_bookings_already_on_the_day_are_pointed_out(): void
    {
        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-11-21 17:00', 'Africa/Cairo'))
            ->create();

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm(['visitLengthDays' => [['date' => '2026-11-21', 'minutes' => 20]]])
            ->assertSee(__('filament.clinic.visit_length_day.existing_bookings', ['count' => 1]));
    }
}
