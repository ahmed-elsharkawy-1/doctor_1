<?php

namespace App\Services\Settings;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Project-wide switches, saved from the admin panel's system settings page.
 *
 * A key nobody has saved falls back to its .env value, so the table can start
 * empty and a release changes nothing. Once saved, the saved value wins —
 * and takes effect at once, with no server access or container restart,
 * which is the point: the OTP switch is pulled when the SMS provider fails.
 */
class SystemSettings
{
    public const REQUIRE_OTP = 'self_booking.require_otp';

    private const CACHE_KEY = 'system_settings';

    /** Whether the public booking page asks for a code at all, anywhere. */
    public function requireOtp(): bool
    {
        return (bool) $this->get(self::REQUIRE_OTP, config('clinic.self_booking.require_otp'));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $saved = $this->all();

        return array_key_exists($key, $saved) ? $saved[$key] : $default;
    }

    public function set(string $key, mixed $value, ?User $by = null): void
    {
        SystemSetting::updateOrCreate(['key' => $key], [
            'value' => $value,
            'updated_by' => $by?->id,
        ]);

        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => SystemSetting::query()->pluck('value', 'key')->all(),
        );
    }
}
