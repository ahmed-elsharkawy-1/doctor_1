<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\SystemSettingsPage;
use App\Models\SystemSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The system settings page: shows what the app is using now, saves only
 * what the admin changed, and refuses values that would break something.
 */
class SystemSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->superAdmin()->create();
        $this->actingAs($this->admin);

        config([
            'clinic.self_booking.otp.resend_cooldown' => 60,
            'clinic.self_booking.no_show_block.threshold' => 2,
            'clinic.reports.send_at' => '09:00',
            'clinic.site.email' => null,
        ]);
    }

    public function test_it_shows_the_values_in_use(): void
    {
        Livewire::test(SystemSettingsPage::class)
            ->assertSchemaStateSet([
                'self_booking.otp.resend_cooldown' => 60,
                'self_booking.no_show_block.threshold' => 2,
                'reports.send_at' => '09:00',
            ]);
    }

    /** Untouched fields stay on .env; only what changed is saved. */
    public function test_only_changed_fields_are_saved(): void
    {
        Livewire::test(SystemSettingsPage::class)
            ->fillForm([
                'self_booking.no_show_block.threshold' => 3,
                'reports.send_at' => '07:30',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            ['self_booking.no_show_block.threshold', 'reports.send_at'],
            SystemSetting::pluck('key')->all(),
        );
        $this->assertSame(3, config('clinic.self_booking.no_show_block.threshold'));
        $this->assertSame('07:30', config('clinic.reports.send_at'));
        $this->assertSame($this->admin->id, SystemSetting::find('reports.send_at')->updated_by);
    }

    public function test_out_of_range_numbers_are_refused(): void
    {
        Livewire::test(SystemSettingsPage::class)
            ->fillForm(['self_booking.no_show_block.threshold' => 0])
            ->call('save')
            ->assertHasFormErrors(['self_booking.no_show_block.threshold']);

        Livewire::test(SystemSettingsPage::class)
            ->fillForm(['self_booking.otp.max_per_phone_hour' => 500])
            ->call('save')
            ->assertHasFormErrors(['self_booking.otp.max_per_phone_hour']);

        $this->assertSame(0, SystemSetting::count());
    }

    public function test_a_bad_time_email_or_link_is_refused(): void
    {
        Livewire::test(SystemSettingsPage::class)
            ->fillForm([
                'reports.send_at' => '25:99',
                'site.email' => 'not-an-email',
                'site.social.facebook' => 'facebook',
            ])
            ->call('save')
            ->assertHasFormErrors(['reports.send_at', 'site.email', 'site.social.facebook']);

        $this->assertSame(0, SystemSetting::count());
    }

    /** The alarm's addresses: a list, each one checked, saved as typed. */
    public function test_alarm_emails_are_checked_one_by_one(): void
    {
        Livewire::test(SystemSettingsPage::class)
            ->fillForm(['otp_alarm.emails' => 'ahmed@elayadah.com, saad@'])
            ->call('save')
            ->assertHasFormErrors(['otp_alarm.emails']);

        $this->assertSame(0, SystemSetting::count());

        Livewire::test(SystemSettingsPage::class)
            ->fillForm(['otp_alarm.emails' => 'ahmed@elayadah.com, saad@elayadah.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ahmed@elayadah.com, saad@elayadah.com', config('clinic.otp_alarm.emails'));
    }

    public function test_alarm_whatsapp_numbers_must_be_mobile_numbers(): void
    {
        Livewire::test(SystemSettingsPage::class)
            ->fillForm(['otp_alarm.whatsapp_numbers' => '01000000003, 12345'])
            ->call('save')
            ->assertHasFormErrors(['otp_alarm.whatsapp_numbers']);

        $this->assertSame(0, SystemSetting::count());

        Livewire::test(SystemSettingsPage::class)
            ->fillForm(['otp_alarm.whatsapp_numbers' => '01000000003، +201000000004'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('01000000003، +201000000004', config('clinic.otp_alarm.whatsapp_numbers'));
    }

    /** Every field has its label and hint in both languages: no raw keys on the page. */
    public function test_every_label_and_hint_is_translated(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $html = Livewire::test(SystemSettingsPage::class)->html();

            $this->assertStringNotContainsString('filament.system_settings', $html, "Raw key on the {$locale} page.");
        }
    }

    /** The link error is in Arabic, like every other message on the page. */
    public function test_a_bad_link_is_explained_in_arabic(): void
    {
        app()->setLocale('ar');

        Livewire::test(SystemSettingsPage::class)
            ->fillForm(['site.social.facebook' => 'facebook'])
            ->call('save')
            ->assertSee('رابطًا صحيحًا');
    }
}
