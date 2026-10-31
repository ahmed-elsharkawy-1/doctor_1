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
 * The two reminders are switched per clinic, by a super admin only, and both
 * start off — on a new clinic and on every clinic already running — until
 * somebody decides that clinic gets them.
 */
class ClinicReminderSettingsTest extends TestCase
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

    public function test_both_reminders_start_off_and_the_morning_one_waits_twelve_hours(): void
    {
        $clinic = $this->clinic->fresh();

        $this->assertFalse($clinic->sendsDailyReminder());
        $this->assertFalse($clinic->sendsPreVisitReminder());
        $this->assertSame(12, $clinic->dailyReminderMinHours());
    }

    /** A row written by hand, without the column, falls back to the config. */
    public function test_a_clinic_with_no_value_follows_the_platform_default(): void
    {
        $this->assertSame(12, (new Clinic)->dailyReminderMinHours());
        $this->assertFalse((new Clinic)->sendsDailyReminder());
        $this->assertFalse((new Clinic)->sendsPreVisitReminder());
    }

    public function test_a_super_admin_can_switch_each_on_and_set_the_hours(): void
    {
        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm([
                'daily_reminder_enabled' => true,
                'pre_visit_reminder_enabled' => true,
                'daily_reminder_min_hours' => 6,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $clinic = $this->clinic->fresh();

        $this->assertTrue($clinic->sendsDailyReminder());
        $this->assertTrue($clinic->sendsPreVisitReminder());
        $this->assertSame(6, $clinic->dailyReminderMinHours());
    }

    public function test_a_switch_on_means_nothing_while_the_clinics_whatsapp_is_off(): void
    {
        $this->clinic->update([
            'whatsapp_enabled' => false,
            'daily_reminder_enabled' => true,
            'pre_visit_reminder_enabled' => true,
        ]);

        $this->assertFalse($this->clinic->fresh()->sendsDailyReminder());
        $this->assertFalse($this->clinic->fresh()->sendsPreVisitReminder());
    }

    /**
     * The reminder goes out on the visit day, so more than a day of notice
     * would only ever mean "bookings made the day before".
     */
    public function test_the_hours_are_between_zero_and_twenty_four(): void
    {
        foreach ([-1, 25] as $hours) {
            Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
                ->fillForm(['daily_reminder_min_hours' => $hours])
                ->call('save')
                ->assertHasFormErrors(['daily_reminder_min_hours']);
        }

        $this->assertSame(12, $this->clinic->fresh()->dailyReminderMinHours());
    }
}
