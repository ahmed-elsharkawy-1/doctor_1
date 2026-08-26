<?php

namespace App\Http\Requests\Api\V1\Booking;

use App\Enums\BookingKind;
use App\Enums\PatientLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['nullable', 'integer'],
            'patient_name' => ['required_without:patient_id', 'nullable', 'string', 'max:255'],
            'phone' => ['required_without:patient_id', 'nullable', 'string', 'max:32'],
            'age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'whatsapp_opt_in' => ['nullable', 'boolean'],
            'visit_type_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'booking_kind' => ['nullable', Rule::in(BookingKind::values())],
            'patient_location' => [
                Rule::requiredIf(fn (): bool => $this->input('booking_kind', BookingKind::NORMAL->value) === BookingKind::EMERGENCY->value),
                'nullable',
                Rule::in(PatientLocation::values()),
            ],
            'start_time' => [
                Rule::requiredIf(fn (): bool => $this->input('booking_kind', BookingKind::NORMAL->value) === BookingKind::NORMAL->value),
                'nullable',
                'date_format:H:i',
                'prohibited_if:booking_kind,'.BookingKind::EMERGENCY->value,
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Overbooking override — books past a full day or outside hours.
            'force' => ['nullable', 'boolean', 'prohibited_if:booking_kind,'.BookingKind::EMERGENCY->value],
            // Confirms replacing the stored name when the phone is known.
            'update_patient_name' => ['nullable', 'boolean'],
            // Set when booking from the call list, so the postponed booking is
            // linked to its replacement and drops off the worklist.
            'rebooking_for_booking_id' => ['nullable', 'integer'],
        ];
    }
}
