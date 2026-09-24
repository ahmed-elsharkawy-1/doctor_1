<?php

namespace App\Services\V1\Booking;

use App\DTOs\V1\Booking\BookingData;
use App\Enums\ApiErrorCode;
use App\Enums\BookingSource;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\VisitType;
use App\Services\V1\Patients\PatientService;
use App\Services\V1\Patients\PhoneVerificationService;
use App\Support\PhoneNumber;
use App\Support\VerifiedPhoneSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every rule the public booking page obeys.
 *
 * The page itself is a Livewire component, and **a Livewire component's public
 * properties are whatever the browser last sent** — anyone can post `step: 4`
 * straight to `/livewire/update`. So nothing here trusts the component: the
 * clinic is re-resolved from the URL, the verified phone is read from the
 * session, and every rule that decided what to render is asked again before
 * anything is written.
 *
 * That is the same discipline ClinicComponent already applies for the staff
 * app, on a surface that has no login at all.
 */
class PatientBookingService
{
    public function __construct(
        private readonly BookingDaysService $dayWindow,
        private readonly SlotAvailabilityService $slots,
        private readonly SlotHoldService $holds,
        private readonly BookingService $bookings,
        private readonly PatientService $patients,
        private readonly PhoneVerificationService $verification,
        private readonly VerifiedPhoneSession $verified,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Who we are booking with
    |--------------------------------------------------------------------------
    */

    /**
     * The clinic behind a slug, or a 404.
     *
     * A clinic that has not switched self-booking on has no booking page at
     * all — not a disabled one. Same treatment as a deactivated clinic's
     * landing page.
     */
    public function clinicFor(string $slug): Clinic
    {
        $clinic = Clinic::query()
            ->with(['doctor', 'specialty'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if ($clinic === null || ! $clinic->allowsSelfBooking()) {
            abort(404);
        }

        return $clinic;
    }

    /*
    |--------------------------------------------------------------------------
    | What the patient may choose
    |--------------------------------------------------------------------------
    */

    /**
     * The days the page offers — the patient window, which is shorter than the
     * clinic's own so the secretary keeps room for the people who phone her.
     *
     * @return list<array<string, mixed>>
     */
    public function days(Clinic $clinic): array
    {
        return $this->dayWindow->window($clinic, $clinic->patientBookingWindowDays());
    }

    /**
     * A wa.me link to the clinic, for the things this page deliberately does
     * not do — changing a booking, cancelling one, or anything urgent.
     */
    public function clinicWhatsAppUrl(Clinic $clinic): ?string
    {
        $phone = $clinic->phone === null
            ? null
            : PhoneNumber::tryParse($clinic->phone, $clinic->country_code);

        return $phone === null
            ? null
            : 'https://wa.me/'.ltrim((string) $phone, '+').'?text='.rawurlencode(
                __('landing.whatsapp_greeting', ['clinic' => $clinic->name]),
            );
    }

    /**
     * @return Collection<int, VisitType>
     */
    public function visitTypes(Clinic $clinic): Collection
    {
        return $clinic->visitTypes()->selfBookable()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * What to pre-select.
     *
     * A returning patient is usually booking the same thing again, so their
     * last visit type wins — but only while the clinic still offers it to
     * patients. Otherwise the clinic's "new concern" type, otherwise the first.
     */
    public function defaultVisitType(Clinic $clinic, ?Patient $patient = null): ?VisitType
    {
        $offered = $this->visitTypes($clinic);

        if ($patient !== null) {
            $last = $this->patients->lastVisit($patient)?->visit_type_id;
            $previous = $offered->firstWhere('id', $last);

            if ($previous !== null) {
                return $previous;
            }
        }

        return $offered->firstWhere('is_new_patient_type', true) ?? $offered->first();
    }

    /**
     * The slot grid for one day, or null when there is nothing to draw.
     *
     * `holdToken` is the caller's own claim: everyone else's holds read as
     * taken, theirs does not, or they could not book the slot they are sitting
     * on.
     */
    public function availability(
        Clinic $clinic,
        VisitType $visitType,
        string $date,
        ?string $holdToken = null,
    ): ?DayAvailability {
        if (! $this->isWithinPatientWindow($clinic, $date)) {
            return null;
        }

        return $this->slots->for(
            $clinic,
            Carbon::parse($date, $clinic->timezone)->startOfDay(),
            $visitType,
            null,
            $holdToken,
        );
    }

    /**
     * A day-by-day summary for the opening screen: how many times are free and
     * when the first one is.
     *
     * Read-only on purpose. Verification takes a minute or two, and
     * availability moves in that time — carrying a slot chosen before it would
     * promise something we cannot keep.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(Clinic $clinic, ?VisitType $visitType = null): array
    {
        $visitType ??= $this->defaultVisitType($clinic);

        if ($visitType === null) {
            return [];
        }

        return array_map(function (array $day) use ($clinic, $visitType): array {
            $availability = $day['is_open']
                ? $this->availability($clinic, $visitType, $day['date']->toDateString())
                : null;

            $free = $availability === null
                ? []
                : array_values(array_filter(
                    $availability->slots,
                    static fn (Slot $slot): bool => $slot->isAvailable,
                ));

            return $day + [
                'available_count' => count($free),
                'first_free' => $free === [] ? null : $free[0]->startAt,
            ];
        }, $this->days($clinic));
    }

    /*
    |--------------------------------------------------------------------------
    | Proving the phone
    |--------------------------------------------------------------------------
    */

    public function requestCode(Clinic $clinic, string $phone, ?string $ip = null): void
    {
        $this->assertOpen($clinic);

        $this->verification->request($clinic, $phone, $ip);
    }

    /**
     * Checks the code and, on success, remembers the number for this browser.
     *
     * The session is what every later step is gated on — never the component's
     * own idea of which step it is on.
     */
    public function verifyCode(Clinic $clinic, string $phone, string $code): string
    {
        $this->assertOpen($clinic);

        $verification = $this->verification->verify($clinic, $phone, $code);

        $this->verified->remember($clinic, $verification->phone);

        return $verification->phone;
    }

    /**
     * The number this browser proved it owns, or null.
     */
    public function verifiedPhone(Clinic $clinic): ?string
    {
        return $this->verified->phoneFor($clinic);
    }

    /**
     * Drops the proof — the patient wants to book for a different number.
     * Typing another one is a different claim and has to be proved on its own.
     */
    public function forgetVerification(Clinic $clinic): void
    {
        $this->verified->forget($clinic);
    }

    /**
     * The patient behind the verified number, if the clinic already knows them.
     */
    public function verifiedPatient(Clinic $clinic): ?Patient
    {
        $phone = $this->verifiedPhone($clinic);

        // Already E.164 — PhoneVerificationService normalises before it sends,
        // and that is the string the session holds. Matching it straight
        // against the column is what PatientService::findByPhone does anyway,
        // without a round trip back through the parser.
        return $phone === null
            ? null
            : $clinic->patients()->where('phone', $phone)->first();
    }

    /**
     * A visit this patient is already waiting on.
     *
     * Booking a second one while the first is still to come is almost always a
     * mistake, so the page stops and shows them the one they have. Cancelled,
     * no-show, completed and past visits do not block anything.
     */
    public function upcomingBooking(Clinic $clinic): ?Booking
    {
        $patient = $this->verifiedPatient($clinic);

        if ($patient === null) {
            return null;
        }

        return $patient->bookings()
            ->with(['visitType'])
            ->upcoming(Carbon::now($clinic->timezone))
            ->orderBy('visit_date')
            ->orderBy('start_at')
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Holding and booking
    |--------------------------------------------------------------------------
    */

    /**
     * Claims a slot while the patient finishes. Moves the claim when they pick
     * a different time rather than leaving a second one behind.
     */
    public function holdSlot(
        Clinic $clinic,
        int $visitTypeId,
        string $date,
        string $startTime,
        ?string $token = null,
    ): string {
        $this->assertOpen($clinic);
        $this->assertVerified($clinic);
        $this->assertWithinPatientWindow($clinic, $date);

        return $this->holds->hold(
            $clinic,
            $visitTypeId,
            $date,
            $startTime,
            BookingSource::PATIENT_WEB,
            null,
            $token,
        )->token;
    }

    public function releaseSlot(?string $token): void
    {
        $this->holds->release($token);
    }

    /**
     * Writes the booking.
     *
     * Every rule that decided what to render is asked again here, because the
     * render happened on data the browser could since have changed.
     */
    public function confirm(
        Clinic $clinic,
        string $name,
        int $visitTypeId,
        string $date,
        string $startTime,
        ?string $holdToken = null,
    ): Booking {
        $this->assertOpen($clinic);
        $phone = $this->assertVerified($clinic);
        $this->assertWithinPatientWindow($clinic, $date);
        $this->assertSelfBookable($clinic, $visitTypeId);

        $existing = $this->upcomingBooking($clinic);

        if ($existing !== null) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_EDITABLE,
                __('booking.self_booking.already_booked'),
                http: 409,
            );
        }

        return $this->bookings->create(
            $clinic,
            new BookingData(
                patientId: null,
                // The verified number is the identity, not anything typed on
                // the last screen. A name is only ever used to create a patient
                // the clinic does not know yet.
                patientName: trim($name),
                phone: $phone,
                age: null,
                // They asked us to send them a code and typed it back; that is
                // consent to be messaged about this visit.
                whatsappOptIn: true,
                visitTypeId: $visitTypeId,
                date: $date,
                startTime: $startTime,
                source: BookingSource::PATIENT_WEB,
                holdToken: $holdToken,
            ),
            // No account behind a public page.
            actor: null,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The guards
    |--------------------------------------------------------------------------
    */

    public function isWithinPatientWindow(Clinic $clinic, string $date): bool
    {
        $today = Carbon::now($clinic->timezone)->startOfDay();
        $last = $today->copy()->addDays($clinic->patientBookingWindowDays() - 1);
        $day = Carbon::parse($date, $clinic->timezone)->startOfDay()->toDateString();

        return $day >= $today->toDateString() && $day <= $last->toDateString();
    }

    private function assertOpen(Clinic $clinic): void
    {
        if (! $clinic->allowsSelfBooking()) {
            throw ApiException::make(
                ApiErrorCode::SELF_BOOKING_DISABLED,
                __('booking.self_booking.disabled'),
                http: 403,
            );
        }
    }

    private function assertVerified(Clinic $clinic): string
    {
        $phone = $this->verifiedPhone($clinic);

        if ($phone === null) {
            throw ApiException::make(
                ApiErrorCode::PHONE_NOT_VERIFIED,
                __('patient.otp.not_verified'),
                http: 403,
            );
        }

        return $phone;
    }

    /**
     * The patient window is narrower than the clinic's, and
     * SlotAvailabilityService only knows the clinic's — so it would happily
     * offer day 6 of 7. This is the only thing standing between a crafted
     * request and a booking three weeks out.
     */
    private function assertWithinPatientWindow(Clinic $clinic, string $date): void
    {
        if (! $this->isWithinPatientWindow($clinic, $date)) {
            throw ApiException::make(
                ApiErrorCode::SLOT_OUTSIDE_WINDOW,
                __('booking.self_booking.outside_window', [
                    'days' => $clinic->patientBookingWindowDays(),
                ]),
                http: 409,
            );
        }
    }

    private function assertSelfBookable(Clinic $clinic, int $visitTypeId): VisitType
    {
        $visitType = $clinic->visitTypes()->selfBookable()->whereKey($visitTypeId)->first();

        if ($visitType === null) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_INACTIVE,
                __('booking.visit_type_inactive'),
            );
        }

        return $visitType;
    }
}
