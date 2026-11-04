<?php

namespace App\Services\V1\Queue;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\PatientResponse;
use App\Enums\PatientResponseSource;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The secretary recording, after a phone call, that a patient who never
 * answered the WhatsApp reminder will come.
 *
 * It stores the same answer a tap on the reminder's Confirm button stores, so
 * every screen that reads `patient_response` treats both alike, and marks it
 * as the clinic's so the card can say who confirmed. Like a WhatsApp
 * confirmation, it leaves the booking `booked` and sends the patient nothing:
 * they have just spoken to the clinic.
 */
class AttendanceConfirmationService
{
    public function confirm(Booking $booking, User $by): Booking
    {
        // Only a visit still ahead can be confirmed. Arrived or later, the
        // patient is already here; cancelled or no-show, there is nothing to
        // confirm.
        if ($booking->status !== BookingStatus::BOOKED) {
            throw ApiException::make(
                ApiErrorCode::BOOKING_NOT_CONFIRMABLE,
                __('booking.not_confirmable', ['status' => $booking->status->label()]),
                details: ['status' => $booking->status->value],
                http: 409,
            );
        }

        // Already confirmed — by the patient on WhatsApp, or by the clinic
        // earlier. Nothing changes, so a WhatsApp confirmation stays recorded
        // as the patient's own and a double tap is harmless.
        if ($booking->patient_response === PatientResponse::CONFIRMED) {
            return $booking;
        }

        $booking->update([
            'patient_response' => PatientResponse::CONFIRMED,
            'patient_responded_at' => Carbon::now($booking->clinic?->timezone ?? config('app.timezone')),
            'patient_response_source' => PatientResponseSource::CLINIC,
            'patient_response_by' => $by->id,
        ]);

        return $booking->refresh();
    }
}
