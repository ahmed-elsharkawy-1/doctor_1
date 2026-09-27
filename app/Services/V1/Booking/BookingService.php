<?php

namespace App\Services\V1\Booking;

use App\DTOs\V1\Booking\BookingData;
use App\Enums\ApiErrorCode;
use App\Enums\BookingKind;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PatientLocation;
use App\Events\SelfBookingReceived;
use App\Events\SlotsChanged;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Models\VisitType;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Patients\PatientService;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;

class BookingService
{
    public function __construct(
        private readonly PatientService $patients,
        private readonly WhatsAppMessagingService $messaging,
        private readonly SlotGuard $guard,
        private readonly ClinicDayLock $lock,
        private readonly SlotHoldService $holds,
    ) {}

    /**
     * @param  User|null  $actor  null when the patient booked themselves — there
     *                            is no account behind a public page, and
     *                            `created_by` is nullable for exactly that.
     */
    public function create(Clinic $clinic, BookingData $data, ?User $actor = null): Booking
    {
        $visitType = $this->activeVisitType($clinic, $data->visitTypeId);
        $startAt = $data->bookingKind === BookingKind::NORMAL
            ? $this->startAt($clinic, $data->date, (string) $data->startTime)
            : null;
        $doctor = $this->doctor($clinic);

        $phone = $data->phone === null ? null : $this->patients->parsePhone($clinic, $data->phone);

        $booking = $this->claimingTheDay($clinic, $this->clinicDate($clinic, $data->date), function () use (
            $clinic, $data, $visitType, $startAt, $doctor, $actor, $phone
        ) {
            if ($data->bookingKind === BookingKind::NORMAL) {
                // Asked with this caller's own hold, so the slot they have
                // been sitting on is free to them and to nobody else.
                $this->guard->ensureFree($clinic, $startAt, $visitType, holdToken: $data->holdToken);
            }

            $patient = $this->patientFor($clinic, $data, $phone);
            $now = Carbon::now($clinic->timezone);
            $startsInsideClinic = $data->bookingKind === BookingKind::EMERGENCY
                && $data->patientLocation === PatientLocation::INSIDE_CLINIC;

            $booking = $clinic->bookings()->create([
                'doctor_id' => $doctor->id,
                'patient_id' => $patient->id,
                'visit_type_id' => $visitType->id,
                'visit_date' => $this->clinicDate($clinic, $data->date)->toDateString(),
                'start_at' => $startAt,
                'end_at' => $startAt?->copy()->addMinutes($visitType->duration_minutes),
                // Frozen at creation — a later price or duration change must
                // not rewrite this booking (SPEC §3.3).
                'duration_minutes' => $visitType->duration_minutes,
                'price' => $visitType->price,
                'status' => $startsInsideClinic ? BookingStatus::ARRIVED : BookingStatus::BOOKED,
                'booking_kind' => $data->bookingKind,
                'patient_location' => $data->bookingKind === BookingKind::EMERGENCY ? $data->patientLocation : null,
                // Which door this came through. Never read from the request —
                // the caller decides (see BookingData::fromArray).
                'source' => $data->source,
                'arrived_at' => $startsInsideClinic ? $now : null,
                'queue_entered_at' => $startsInsideClinic ? $now : null,
                'notes' => $data->notes,
                'created_by' => $actor?->id,
            ]);

            $this->linkRebooking($clinic, $data->rebookingForBookingId, $booking);

            // The hold has done its job. Released inside the transaction so a
            // rolled-back booking never leaves its slot unguarded, and without
            // re-taking the day lock we are already inside.
            $this->holds->consume($data->holdToken);

            return $booking;
        });

        // Sent here so every caller behaves the same: a booking taken on the
        // mobile app reaches the patient exactly as one taken on the web does,
        // with no button for anyone to forget. Deliberately outside the day
        // lock — a queued message must never outlive a rolled-back booking.
        $this->messaging->sendConfirmation($clinic, $booking);

        // Outside the lock, for the same reason the message is: everyone
        // watching this day is about to re-read it, and none of them should
        // wait on us to do so.
        SlotsChanged::dispatch($clinic, $booking->visit_date->toDateString());

        // The secretary is not necessarily looking at the queue. The badge on
        // the card only works for somebody who already is.
        if ($booking->source === BookingSource::PATIENT_WEB) {
            SelfBookingReceived::dispatch($booking);
        }

        return $booking;
    }

    public function update(Clinic $clinic, int $bookingId, BookingData $data): Booking
    {
        $booking = $this->find($clinic, $bookingId);

        if (! $booking->isEditable()) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_EDITABLE,
                __('booking.not_editable', ['status' => $booking->status->label()]),
                details: ['status' => $booking->status->value],
            );
        }

        $visitType = $this->activeVisitType($clinic, $data->visitTypeId);
        $startAt = $data->bookingKind === BookingKind::NORMAL
            ? $this->startAt($clinic, $data->date, (string) $data->startTime)
            : null;
        $phone = $data->phone === null ? null : $this->patients->parsePhone($clinic, $data->phone);

        // Read before the write. A reschedule frees the day it left as surely
        // as it fills the one it lands on, and afterwards there is nothing
        // left on the row to say where it came from.
        $movedFrom = $booking->visit_date->toDateString();

        $updated = $this->claimingTheDay($clinic, $this->clinicDate($clinic, $data->date), function () use (
            $clinic, $booking, $data, $visitType, $startAt, $phone
        ) {
            // The booking must not collide with itself.
            if ($data->bookingKind === BookingKind::NORMAL) {
                $this->guard->ensureFree($clinic, $startAt, $visitType, $booking->id, $data->holdToken);
            }

            $patient = $this->patientFor($clinic, $data, $phone);
            $now = Carbon::now($clinic->timezone);
            $startsInsideClinic = $booking->status === BookingStatus::BOOKED
                && $data->bookingKind === BookingKind::EMERGENCY
                && $data->patientLocation === PatientLocation::INSIDE_CLINIC;

            $this->holds->consume($data->holdToken);

            $booking->update([
                'patient_id' => $patient->id,
                'visit_type_id' => $visitType->id,
                'visit_date' => $this->clinicDate($clinic, $data->date)->toDateString(),
                'start_at' => $startAt,
                'end_at' => $startAt?->copy()->addMinutes($visitType->duration_minutes),
                'duration_minutes' => $visitType->duration_minutes,
                'price' => $visitType->price,
                'status' => $startsInsideClinic ? BookingStatus::ARRIVED : $booking->status,
                'booking_kind' => $data->bookingKind,
                'patient_location' => $data->bookingKind === BookingKind::EMERGENCY ? $data->patientLocation : null,
                'arrived_at' => $startsInsideClinic ? $now : $booking->arrived_at,
                'queue_entered_at' => $startsInsideClinic ? $now : $booking->queue_entered_at,
                'notes' => $data->notes,
            ]);

            return $booking->refresh();
        });

        foreach (array_unique([$movedFrom, $updated->visit_date->toDateString()]) as $date) {
            SlotsChanged::dispatch($clinic, $date);
        }

        return $updated;
    }

    public function find(Clinic $clinic, int $bookingId): Booking
    {
        $booking = $clinic->bookings()
            ->with(['patient', 'visitType', 'doctor'])
            ->whereKey($bookingId)
            ->first();

        if ($booking === null) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_FOUND,
                __('booking.not_found'),
                http: 404,
            );
        }

        return $booking;
    }

    /**
     * Two tabs, or a double-tap, must not claim the same time.
     *
     * The key lives in ClinicDayLock because slot holds queue behind the same
     * one — see the note there on why there is only ever one copy of it.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function claimingTheDay(Clinic $clinic, Carbon $date, \Closure $callback): mixed
    {
        return $this->lock->claim($clinic, $date, $callback);
    }

    private function activeVisitType(Clinic $clinic, int $visitTypeId): VisitType
    {
        $visitType = $clinic->visitTypes()->whereKey($visitTypeId)->first();

        if ($visitType === null) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_NOT_FOUND,
                __('settings.visit_type.not_found'),
                http: 404,
            );
        }

        if (! $visitType->is_active) {
            throw ApiException::make(
                ApiErrorCode::VISIT_TYPE_INACTIVE,
                __('booking.visit_type_inactive'),
            );
        }

        return $visitType;
    }

    private function patientFor(Clinic $clinic, BookingData $data, ?PhoneNumber $phone): Patient
    {
        if ($data->patientId !== null) {
            return $this->patients->findById($clinic, $data->patientId);
        }

        if ($phone === null || $data->patientName === null) {
            throw ApiException::make(
                ApiErrorCode::PATIENT_NOT_FOUND,
                __('patient.not_found'),
                http: 404,
            );
        }

        return $this->patients->findOrCreate(
            $clinic,
            (string) $data->patientName,
            $phone,
            $data->age,
            $data->whatsappOptIn,
            $data->updatePatientName,
        );
    }

    /**
     * Booked from the call list: point the postponed booking at its
     * replacement so the patient drops off the rebooking worklist (SPEC §4.5).
     */
    private function linkRebooking(Clinic $clinic, ?int $originalId, Booking $replacement): void
    {
        if ($originalId === null) {
            return;
        }

        $original = $clinic->bookings()->awaitingRebooking()->whereKey($originalId)->first();

        if ($original === null) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_FOUND,
                __('booking.not_awaiting_rebooking'),
                http: 404,
            );
        }

        $original->update(['rebooked_booking_id' => $replacement->id]);
    }

    private function doctor(Clinic $clinic): Doctor
    {
        $doctor = $clinic->doctor;

        if ($doctor === null) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_FOUND,
                __('booking.no_doctor'),
                http: 409,
            );
        }

        return $doctor;
    }

    private function startAt(Clinic $clinic, string $date, string $time): Carbon
    {
        return Carbon::parse("{$date} {$time}", $clinic->timezone);
    }

    private function clinicDate(Clinic $clinic, string $date): Carbon
    {
        return Carbon::parse($date, $clinic->timezone)->startOfDay();
    }
}
