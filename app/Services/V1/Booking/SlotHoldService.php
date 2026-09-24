<?php

namespace App\Services\V1\Booking;

use App\Enums\ApiErrorCode;
use App\Enums\BookingSource;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\SlotHold;
use App\Models\User;
use App\Models\VisitType;
use App\Services\V1\Settings\VisitTypeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Claiming a slot while somebody fills in the rest of the form.
 *
 * Three doors compete for the same times — the mobile app, the clinic web app
 * and the patient's own page. The day lock already stops two of them *saving*
 * the same slot; a hold stops two of them *working on* it, which is the part
 * people actually notice.
 *
 * Holds block everyone alike. Staff do not override a patient and a patient
 * does not override staff: one rule is easier to trust than two, and the worst
 * case is a short wait for a slot somebody is actively booking.
 *
 * Timekeeping follows the rest of the domain: `start_at` and `visit_date` are
 * the clinic's wall clock, because that is what a slot is. `expires_at` is not
 * a wall clock but a deadline, so it is written and read on one clock — the
 * app's — and never converted. A clinic's timezone has no business deciding
 * how long five minutes is.
 */
class SlotHoldService
{
    public function __construct(
        private readonly SlotAvailabilityService $slots,
        private readonly SlotGuard $guard,
        private readonly ClinicDayLock $lock,
        private readonly VisitTypeService $visitTypes,
    ) {}

    /**
     * Claims a slot, or moves this holder's existing claim onto it.
     *
     * Runs under the clinic-day lock, so two people tapping the same time in
     * the same second cannot both come away holding it.
     *
     * @param  string|null  $token  the holder's existing token. Passing it
     *                              moves that hold rather than adding a second
     *                              one — one holder is only ever sitting on one
     *                              slot.
     */
    public function hold(
        Clinic $clinic,
        int $visitTypeId,
        string $date,
        string $startTime,
        BookingSource $source = BookingSource::CLINIC,
        ?User $actor = null,
        ?string $token = null,
    ): SlotHold {
        $visitType = $this->bookableVisitType($clinic, $visitTypeId, $source);
        $startAt = Carbon::parse("{$date} {$startTime}", $clinic->timezone);
        $day = Carbon::parse($date, $clinic->timezone)->startOfDay();
        $token ??= $this->newToken();

        return $this->lock->claim($clinic, $day, function () use (
            $clinic, $visitType, $startAt, $day, $source, $actor, $token
        ): SlotHold {
            // Asked with this holder's own token, so moving a hold back onto
            // the slot it already sits on is never refused by itself.
            $this->guard->ensureFree($clinic, $startAt, $visitType, holdToken: $token);

            return SlotHold::updateOrCreate(['token' => $token], [
                'clinic_id' => $clinic->id,
                'visit_type_id' => $visitType->id,
                'visit_date' => $day->toDateString(),
                'start_at' => $startAt,
                'end_at' => $startAt->copy()->addMinutes($visitType->duration_minutes),
                'source' => $source,
                'created_by' => $actor?->id,
                'expires_at' => $this->expiry(),
            ]);
        });
    }

    /**
     * Gives a slot back — the holder navigated away, changed their mind, or
     * finished with it.
     *
     * Deliberately unlocked. Releasing only ever frees time, so it cannot
     * cause the collision the lock exists to prevent, and it has to stay safe
     * to call from inside a booking that is already holding that lock.
     */
    public function release(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }

        SlotHold::where('token', $token)->delete();
    }

    /**
     * Turns a hold into the booking it was holding the slot for.
     *
     * Called from inside BookingService's own transaction, which is already
     * under the day lock — so this must never claim it again. The lock is not
     * re-entrant and would time out waiting for its own caller.
     */
    public function consume(?string $token): void
    {
        $this->release($token);
    }

    /**
     * Clears out yesterday's leftovers.
     *
     * Housekeeping only. Correctness never waits for this: `expires_at` is a
     * query filter, so a lapsed hold stops blocking its slot the moment it
     * lapses whether or not anything has swept the table.
     */
    public function purgeExpired(?Clinic $clinic = null): int
    {
        return SlotHold::query()
            ->when($clinic !== null, fn ($query) => $query->where('clinic_id', $clinic->id))
            ->where('expires_at', '<=', Carbon::now())
            ->delete();
    }

    /**
     * The hold a token still has, if it has not lapsed.
     */
    public function find(?string $token): ?SlotHold
    {
        if ($token === null || $token === '') {
            return null;
        }

        return SlotHold::where('token', $token)->live()->first();
    }

    public function newToken(): string
    {
        return Str::random(48);
    }

    public function ttlMinutes(): int
    {
        return (int) config('clinic.self_booking.hold_ttl_minutes');
    }

    private function expiry(): Carbon
    {
        return Carbon::now()->addMinutes($this->ttlMinutes());
    }

    /**
     * The same checks BookingService makes, plus one: a patient may only hold
     * a type their clinic actually offers on the public page. The page already
     * filters the list, so reaching this is a crafted request.
     */
    private function bookableVisitType(Clinic $clinic, int $visitTypeId, BookingSource $source): VisitType
    {
        $visitType = $this->visitTypes->find($clinic, $visitTypeId);

        if (! $visitType->is_active) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_INACTIVE,
                __('booking.visit_type_inactive'),
            );
        }

        if ($source === BookingSource::PATIENT_WEB && ! $visitType->is_self_bookable) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_INACTIVE,
                __('booking.visit_type_inactive'),
            );
        }

        return $visitType;
    }
}
