<?php

namespace App\Support;

use App\Models\Clinic;

/**
 * "This browser is part way through booking a slot."
 *
 * The token that names a hold has to survive a page reload. A hold blocks
 * everybody *except* the token that owns it, so a patient who reloads and
 * comes back without theirs is treated as a stranger and finds their own slot
 * struck through — until it lapses, on a page that gives them no way to say
 * the claim was theirs.
 *
 * The session is the right home for it and the component was the wrong one.
 * A Livewire public property is rebuilt from the browser on every request and
 * lost entirely on a reload, and this token is what lets its holder book the
 * slot it is blocking — so it has no business travelling on the wire at all.
 *
 * Scoped per clinic, like {@see VerifiedPhoneSession}: one browser can be part
 * way through booking at two clinics, and neither claim should answer for the
 * other.
 *
 * Nothing here checks whether the hold is still alive, deliberately. A lapsed
 * token is harmless on every path it reaches — it excludes no rows from the
 * availability query, releases nothing, and taking a slot with it writes a
 * fresh hold under the same name.
 */
final class HeldSlotSession
{
    private const PREFIX = 'booking.hold';

    /** The public booking page. */
    public const PATIENT = 'patient';

    /** The staff app's new-booking screen. */
    public const STAFF = 'staff';

    public function remember(Clinic $clinic, string $token, string $scope = self::PATIENT): void
    {
        session()->put($this->key($clinic, $scope), $token);
    }

    public function tokenFor(Clinic $clinic, string $scope = self::PATIENT): ?string
    {
        $token = session()->get($this->key($clinic, $scope));

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function forget(Clinic $clinic, string $scope = self::PATIENT): void
    {
        session()->forget($this->key($clinic, $scope));
    }

    /**
     * Separate keys per surface as well as per clinic.
     *
     * A secretary can have the staff app and the public page open in the same
     * browser at the same clinic — on a shared front-desk machine that is
     * ordinary. One key between them would mean picking a time on one screen
     * silently moved the other screen's claim.
     */
    private function key(Clinic $clinic, string $scope): string
    {
        return self::PREFIX.'.'.$scope.'.'.$clinic->id;
    }
}
