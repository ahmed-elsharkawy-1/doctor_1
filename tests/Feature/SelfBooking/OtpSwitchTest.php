<?php

namespace Tests\Feature\SelfBooking;

use App\Enums\DayOfWeek;
use App\Livewire\Patient\BookVisit;
use App\Models\Clinic;
use App\Models\User;
use App\Services\Messaging\OtpSender;
use App\Services\Settings\SystemSettings;
use App\Services\V1\Booking\PatientBookingService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingOtpSender;
use Tests\TestCase;

/**
 * Whether the public page asks for a code: the project's switch, then the
 * clinic's own. The project's off is off everywhere — the switch pulled when
 * the SMS provider is down; a clinic's off is that clinic alone.
 */
class OtpSwitchTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    private RecordingOtpSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->openForBooking($this->clinic, 'dr-sara');

        config(['clinic.self_booking.require_otp' => true]);

        $this->sender = new RecordingOtpSender;
        $this->app->instance(OtpSender::class, $this->sender);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function openForBooking(Clinic $clinic, string $slug): void
    {
        $clinic->update([
            'slug' => $slug,
            'self_booking_enabled' => true,
            'booking_window_days' => 7,
            'patient_booking_window_days' => 3,
        ]);

        $schedule = $clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $schedule->update(['is_open' => true]);

        if ($schedule->periods()->count() === 0) {
            $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
        }
    }

    private function afterPhone(string $slug = 'dr-sara'): Testable
    {
        return Livewire::test(BookVisit::class, ['slug' => $slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', '01012225521')
            ->call('sendCode');
    }

    private function projectSwitch(bool $on): void
    {
        app(SystemSettings::class)->set(SystemSettings::REQUIRE_OTP, $on);
    }

    public function test_both_on_asks_for_a_code(): void
    {
        $this->afterPhone()->assertViewHas('stage', 'code');

        $this->assertCount(1, $this->sender->sent);
    }

    public function test_a_clinic_switched_off_books_without_a_code(): void
    {
        $this->clinic->update(['otp_required' => false]);

        $this->afterPhone()->assertViewHas('stage', 'appointment');

        $this->assertCount(0, $this->sender->sent);
    }

    /** One clinic's choice is its own. */
    public function test_another_clinic_still_asks(): void
    {
        $this->clinic->update(['otp_required' => false]);

        $other = $this->otherClinic();
        $this->openForBooking($other, 'dr-other');

        $this->afterPhone('dr-other')->assertViewHas('stage', 'code');
    }

    /** The project's switch off is off everywhere, whatever a clinic says. */
    public function test_the_project_switched_off_turns_it_off_for_every_clinic(): void
    {
        $this->projectSwitch(false);

        $this->afterPhone()->assertViewHas('stage', 'appointment');

        $this->assertCount(0, $this->sender->sent);
    }

    /** Until someone sets it on the settings page, the server's .env decides. */
    public function test_without_a_saved_value_the_env_setting_decides(): void
    {
        config(['clinic.self_booking.require_otp' => false]);

        $this->afterPhone()->assertViewHas('stage', 'appointment');
    }

    /** Once saved on the page, the saved value wins over .env, both ways. */
    public function test_a_saved_value_wins_over_the_env_setting(): void
    {
        config(['clinic.self_booking.require_otp' => false]);
        $this->projectSwitch(true);

        $this->afterPhone()->assertViewHas('stage', 'code');
    }

    /** The shortcut that skips the code refuses while this clinic asks for one. */
    public function test_the_unverified_path_refuses_while_the_clinic_asks(): void
    {
        $this->expectException(\LogicException::class);

        app(PatientBookingService::class)->acceptPhoneUnverified($this->clinic, '01012225521');
    }

    public function test_a_new_clinic_asks_for_a_code_by_default(): void
    {
        $clinic = Clinic::factory()->create();

        $this->assertTrue($clinic->fresh()->otp_required);
    }

    public function test_the_project_switch_records_who_changed_it(): void
    {
        $admin = User::factory()->superAdmin()->create();

        app(SystemSettings::class)->set(SystemSettings::REQUIRE_OTP, false, $admin);

        $this->assertDatabaseHas('system_settings', [
            'key' => SystemSettings::REQUIRE_OTP,
            'updated_by' => $admin->id,
        ]);
        $this->assertFalse(app(SystemSettings::class)->requireOtp());
    }
}
