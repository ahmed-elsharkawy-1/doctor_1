<?php

namespace Tests\Feature\Filament;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Models\Clinic;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The patient's booking horizon on the clinic form: any value up to the
 * clinic's own window saves, anything beyond it is refused.
 */
class ClinicPatientBookingWindowTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->clinic = Clinic::factory()->create(['phone' => '+201001234567', 'slug' => 'dr-test']);
        app(ProvisionClinicAction::class)->execute($this->clinic, 'secret-password');
    }

    public function test_a_value_within_the_clinic_window_saves(): void
    {
        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm([
                'booking_window_days' => 10,
                'patient_booking_window_days' => 8,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(8, $this->clinic->fresh()->patient_booking_window_days);
    }

    public function test_a_value_equal_to_the_clinic_window_saves(): void
    {
        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm([
                'booking_window_days' => 7,
                'patient_booking_window_days' => 7,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(7, $this->clinic->fresh()->patient_booking_window_days);
    }

    public function test_a_value_beyond_the_clinic_window_is_refused(): void
    {
        $before = $this->clinic->fresh()->patient_booking_window_days;

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm([
                'booking_window_days' => 7,
                'patient_booking_window_days' => 8,
            ])
            ->call('save')
            ->assertHasFormErrors(['patient_booking_window_days' => 'lte']);

        $this->assertSame($before, $this->clinic->fresh()->patient_booking_window_days);
    }
}
