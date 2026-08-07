<?php

namespace App\Services\V1\Booking;

use App\DTOs\V1\Booking\BookingData;
use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use App\Models\VisitType;
use App\Services\V1\Patients\PatientService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(
        private readonly SlotAvailabilityService $slots,
        private readonly PatientService $patients,
    ) {}

    public function create(Clinic $clinic, BookingData $data, User $actor): Booking
    {
        $visitType = $this->activeVisitType($clinic, $data->visitTypeId);
        $startAt = $this->startAt($clinic, $data->date, $data->startTime);
        $doctor = $this->doctor($clinic);

        $phone = $this->patients->parsePhone($clinic, $data->phone);

        return $this->claimingTheDay($clinic, $startAt, function () use (
            $clinic, $data, $visitType, $startAt, $doctor, $actor, $phone
        ) {
            $this->guardSlot($clinic, $startAt, $visitType, $data->force);

            $patient = $this->patients->findOrCreate(
                $clinic,
                $data->patientName,
                $phone,
                $data->updatePatientName,
            );

            $booking = $clinic->bookings()->create([
                'doctor_id' => $doctor->id,
                'patient_id' => $patient->id,
                'visit_type_id' => $visitType->id,
                'visit_date' => $startAt->toDateString(),
                'start_at' => $startAt,
                'end_at' => $startAt->copy()->addMinutes($visitType->duration_minutes),
                // Frozen at creation — a later price or duration change must
                // not rewrite this booking (SPEC §3.3).
                'duration_minutes' => $visitType->duration_minutes,
                'price' => $visitType->price,
                'status' => BookingStatus::BOOKED,
                'is_overbooked' => $data->force,
                'notes' => $data->notes,
                'created_by' => $actor->id,
            ]);

            $this->linkRebooking($clinic, $data->rebookingForBookingId, $booking);

            return $booking;
        });
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
        $startAt = $this->startAt($clinic, $data->date, $data->startTime);
        $phone = $this->patients->parsePhone($clinic, $data->phone);

        return $this->claimingTheDay($clinic, $startAt, function () use (
            $clinic, $booking, $data, $visitType, $startAt, $phone
        ) {
            // The booking must not collide with itself.
            $this->guardSlot($clinic, $startAt, $visitType, $data->force, $booking->id);

            $patient = $this->patients->findOrCreate(
                $clinic,
                $data->patientName,
                $phone,
                $data->updatePatientName,
            );

            $booking->update([
                'patient_id' => $patient->id,
                'visit_type_id' => $visitType->id,
                'visit_date' => $startAt->toDateString(),
                'start_at' => $startAt,
                'end_at' => $startAt->copy()->addMinutes($visitType->duration_minutes),
                'duration_minutes' => $visitType->duration_minutes,
                'price' => $visitType->price,
                'is_overbooked' => $data->force ? true : $booking->is_overbooked,
                'notes' => $data->notes,
            ]);

            return $booking->refresh();
        });
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
     * Two tabs, or a double-tap, must not claim the same time. The lock
     * serialises writes for one clinic-day; the transaction keeps the
     * availability check and the insert atomic.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function claimingTheDay(Clinic $clinic, Carbon $startAt, \Closure $callback): mixed
    {
        $lock = Cache::lock(
            "booking_lock_{$clinic->id}_{$startAt->toDateString()}",
            seconds: 10,
        );

        return $lock->block(5, fn () => DB::transaction($callback));
    }

    /**
     * @throws ApiException unless the slot is free, or the secretary forced it
     */
    private function guardSlot(
        Clinic $clinic,
        Carbon $startAt,
        VisitType $visitType,
        bool $force,
        ?int $ignoreBookingId = null,
    ): void {
        if ($force) {
            return;
        }

        $availability = $this->slots->for($clinic, $startAt->copy()->startOfDay(), $visitType, $ignoreBookingId);

        if (! $availability->isOpen) {
            throw ApiException::make(
                match ($availability->closedReason) {
                    ClosedReason::OUTSIDE_WINDOW => ApiErrorCode::SLOT_OUTSIDE_WINDOW,
                    default => ApiErrorCode::CLINIC_CLOSED_THAT_DAY,
                },
                $availability->closedReason?->label() ?? __('booking.clinic_closed'),
                details: ['reason' => $availability->closedReason?->value],
                http: 409,
            );
        }

        foreach ($availability->slots as $slot) {
            if ($slot->startAt->equalTo($startAt)) {
                if ($slot->isAvailable) {
                    return;
                }

                throw ApiException::make(
                    ApiErrorCode::SLOT_UNAVAILABLE,
                    __('booking.slot_unavailable'),
                    details: ['start_time' => $startAt->format('H:i')],
                    http: 409,
                );
            }
        }

        // A time the clinic never offers for this visit type — off-grid, or
        // the visit would not finish before the period ends.
        throw ApiException::make(
            ApiErrorCode::SLOT_OUTSIDE_WORKING_HOURS,
            __('booking.slot_outside_hours'),
            details: ['start_time' => $startAt->format('H:i')],
            http: 409,
        );
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
}
