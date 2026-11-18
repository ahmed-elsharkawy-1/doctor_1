<?php

namespace App\Services\Settings;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Project-wide settings, saved from the admin panel's system settings page.
 *
 * Each key is a path under config('clinic.*'). A key nobody has saved keeps
 * its .env value, so the table can start empty and a release changes
 * nothing. Once saved, apply() writes the saved value over the config, so
 * the code that reads config('clinic.…') — and there are dozens of places —
 * reads the saved value without being touched, and without a restart.
 *
 * apply() runs on every boot (web requests, the scheduler's per-minute runs)
 * and before every queued job, since a worker boots once and lives on.
 *
 * Only keys in DEFINITIONS can be saved. Drivers, secrets and test-only
 * switches stay in .env on purpose.
 */
class SystemSettings
{
    public const REQUIRE_OTP = 'self_booking.require_otp';

    /** key => the type its saved value is read back as. */
    public const DEFINITIONS = [
        'self_booking.require_otp' => 'bool',
        'self_booking.otp.resend_cooldown' => 'int',
        'self_booking.otp.max_per_phone_hour' => 'int',
        'self_booking.otp.max_per_ip_hour' => 'int',
        'self_booking.show_price' => 'bool',
        'self_booking.no_show_block.threshold' => 'int',
        'self_booking.no_show_block.window_days' => 'int',
        'tracking.show_estimate' => 'bool',
        'turn_alert.first_patients_lead_minutes' => 'int',
        'waiting_list.max_per_day' => 'int',
        'reports.morning_whatsapp' => 'bool',
        'reports.send_at' => 'string',
        'site.whatsapp' => 'string',
        'site.phone' => 'string',
        'site.email' => 'string',
        'site.social.facebook' => 'string',
        'site.social.instagram' => 'string',
        'site.social.linkedin' => 'string',
        'site.show_login' => 'bool',
        'otp_alarm.enabled' => 'bool',
        'otp_alarm.emails' => 'string',
        'otp_alarm.whatsapp_numbers' => 'string',
        'otp_alarm.min_codes' => 'int',
        'otp_alarm.min_phones' => 'int',
        'otp_alarm.window_minutes' => 'int',
        'otp_alarm.grace_minutes' => 'int',
    ];

    private const CACHE_KEY = 'system_settings';

    /** Whether the public booking page asks for a code at all, anywhere. */
    public function requireOtp(): bool
    {
        return (bool) $this->value(self::REQUIRE_OTP);
    }

    /** What the app is using for this key now: the saved value, or .env's. */
    public function value(string $key): mixed
    {
        $this->assertKnown($key);

        $saved = $this->saved();

        return array_key_exists($key, $saved)
            ? $this->cast($key, $saved[$key])
            : config('clinic.'.$key);
    }

    public function set(string $key, mixed $value, ?User $by = null): void
    {
        $this->assertKnown($key);

        SystemSetting::updateOrCreate(['key' => $key], [
            // A cleared field is stored as "" (the column holds JSON, never
            // NULL) and read back as no value.
            'value' => $this->cast($key, $value) ?? '',
            'updated_by' => $by?->id,
        ]);

        Cache::forget(self::CACHE_KEY);

        $this->apply();
    }

    /**
     * Writes every saved value over the config. Safe before the table exists
     * — during the deploy's own migrate, or a fresh test database — when the
     * .env values simply stand.
     */
    public function apply(): void
    {
        try {
            $saved = $this->saved();
        } catch (Throwable) {
            return;
        }

        foreach ($saved as $key => $value) {
            if (isset(self::DEFINITIONS[$key])) {
                config(['clinic.'.$key => $this->cast($key, $value)]);
            }
        }

        // An empty link means "no button", as an empty .env value does.
        config(['clinic.site.social' => array_filter((array) config('clinic.site.social'))]);
    }

    /** @return array<string, mixed> */
    private function saved(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => SystemSetting::query()->pluck('value', 'key')->all(),
        );
    }

    private function cast(string $key, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return self::DEFINITIONS[$key] === 'string' ? null : $value;
        }

        return match (self::DEFINITIONS[$key]) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            default => (string) $value,
        };
    }

    private function assertKnown(string $key): void
    {
        if (! isset(self::DEFINITIONS[$key])) {
            throw new InvalidArgumentException("[{$key}] is not a system setting.");
        }
    }

    /**
     * The saved and .env values together, as the settings page's form state.
     *
     * @return array<string, mixed>
     */
    public function formState(): array
    {
        $state = [];

        foreach (array_keys(self::DEFINITIONS) as $key) {
            Arr::set($state, $key, $this->value($key));
        }

        return $state;
    }
}
