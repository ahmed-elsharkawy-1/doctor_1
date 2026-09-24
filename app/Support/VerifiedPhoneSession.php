<?php

namespace App\Support;

use App\Models\Clinic;
use Illuminate\Support\Carbon;

/**
 * "This browser proved it owns that number, recently."
 *
 * The claim is deliberately short-lived and checked on **read** against the
 * clock, rather than leaning on the session's own lifetime — that is a global
 * a deploy could lengthen without anyone connecting it to verification.
 *
 * Scoped per clinic: verifying at one clinic is not a licence to book at
 * another, and a shared device should not carry one patient's proof into
 * somebody else's booking.
 */
final class VerifiedPhoneSession
{
    private const PREFIX = 'self_booking.verified';

    public function remember(Clinic $clinic, string $phone): void
    {
        session()->put($this->key($clinic), [
            'phone' => $phone,
            'at' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * The verified number, or null when there is none or it has gone stale.
     */
    public function phoneFor(Clinic $clinic): ?string
    {
        $stored = session()->get($this->key($clinic));

        if (! is_array($stored) || ! isset($stored['phone'], $stored['at'])) {
            return null;
        }

        if ($this->hasLapsed((string) $stored['at'])) {
            $this->forget($clinic);

            return null;
        }

        return (string) $stored['phone'];
    }

    public function isVerified(Clinic $clinic): bool
    {
        return $this->phoneFor($clinic) !== null;
    }

    /**
     * Whether this exact number is the one that was proved.
     *
     * Typing a different number is a different claim — it has to be verified
     * on its own, or the husband who verified his wife's phone could switch it
     * for a stranger's on the last screen.
     */
    public function matches(Clinic $clinic, string $phone): bool
    {
        return $this->phoneFor($clinic) === $phone;
    }

    public function forget(Clinic $clinic): void
    {
        session()->forget($this->key($clinic));
    }

    private function hasLapsed(string $verifiedAt): bool
    {
        $minutes = (int) config('clinic.self_booking.verified_session_minutes');

        return Carbon::parse($verifiedAt)->addMinutes($minutes)->lessThanOrEqualTo(Carbon::now());
    }

    private function key(Clinic $clinic): string
    {
        return self::PREFIX.'.'.$clinic->id;
    }
}
