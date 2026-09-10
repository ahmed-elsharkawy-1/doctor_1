<?php

namespace App\Livewire\App;

use App\DTOs\V1\Booking\BookingData;
use App\Enums\BookingKind;
use App\Enums\PatientLocation;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Patient;
use App\Services\V1\Booking\BookingDaysService;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Booking\DayAvailability;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Patients\PatientSearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Taking a booking: find the patient, pick the visit, pick the time, save.
 *
 * The rules that decide whether a booking is possible — the clinic is open,
 * the slot is free, the visit type is still active — are not asked here. They
 * live in BookingService and SlotAvailabilityService, which is why the same
 * questions get the same answers on the mobile API.
 */
class NewBooking extends ClinicComponent
{
    /** Existing patient, chosen from search. */
    public string $patientSearch = '';

    public ?int $patientId = null;

    /** New patient, typed in. */
    public string $patientName = '';

    public string $phone = '';

    public string $age = '';

    public bool $whatsappOptIn = true;

    public ?int $visitTypeId = null;

    public string $kind = 'normal';

    public ?string $patientLocation = null;

    public string $date = '';

    public ?string $startTime = null;

    public string $notes = '';

    /**
     * Set when the secretary came here from the call list. The replacement
     * booking is linked to the original inside BookingService, which is what
     * takes the patient off the rebooking worklist.
     */
    #[Url(as: 'rebooking_for', except: null)]
    public ?int $rebookingFor = null;

    public ?string $notice = null;

    public bool $failed = false;

    /** Shown after a save, so the secretary can hand the link over. */
    public ?string $trackingUrl = null;

    public function mount(): void
    {
        $clinic = $this->clinic();

        $this->date = Carbon::now($clinic->timezone)->toDateString();
        $this->visitTypeId = $clinic->visitTypes()->active()->value('id');

        $this->prefillFromRebooking($clinic);
    }

    /**
     * Coming from the call list, the patient and the visit are already known —
     * this is the same appointment, on a new day.
     *
     * The id arrives in the query string, so it is checked here rather than
     * trusted: it must be this clinic's, and still awaiting a replacement.
     */
    private function prefillFromRebooking(Clinic $clinic): void
    {
        if ($this->rebookingFor === null) {
            return;
        }

        $original = $clinic->bookings()
            ->awaitingRebooking()
            ->with('patient')
            ->whereKey($this->rebookingFor)
            ->first();

        if ($original === null) {
            // Already rebooked, or never ours. Drop the link and let her book
            // normally rather than failing on save.
            $this->rebookingFor = null;
            $this->notice = __('booking.not_awaiting_rebooking');
            $this->failed = true;

            return;
        }

        $this->patientId = $original->patient_id;
        $this->patientName = (string) $original->patient?->name;
        $this->phone = (string) $original->patient?->phone;
        $this->age = (string) ($original->patient?->age ?? '');

        if ($clinic->visitTypes()->active()->whereKey($original->visit_type_id)->exists()) {
            $this->visitTypeId = $original->visit_type_id;
        }
    }

    public function render(): View
    {
        $availability = $this->availability();

        return view('livewire.app.new-booking', [
            'visitTypes' => $this->clinic()->visitTypes()->active()->orderBy('name')->get(),
            'patients' => $this->patientResults(),
            'days' => app(BookingDaysService::class)->window($this->clinic()),
            'availability' => $availability,
            'locations' => PatientLocation::cases(),
        ])->title(__('app.booking.title'));
    }

    /*
    |--------------------------------------------------------------------------
    | Choosing
    |--------------------------------------------------------------------------
    */

    public function selectPatient(int $patientId): void
    {
        try {
            $patient = app(PatientSearchService::class)->find($this->clinic(), $patientId);
        } catch (ApiException $e) {
            // Another clinic's patient, or one just deleted. ApiException
            // renders itself as JSON, which would break the Livewire response.
            $this->notice = $e->getMessage();
            $this->failed = true;

            return;
        }

        $this->patientId = $patient->id;
        $this->patientName = $patient->name;
        $this->phone = $patient->phone;
        $this->age = (string) ($patient->age ?? '');
        $this->patientSearch = '';
    }

    public function clearPatient(): void
    {
        $this->patientId = null;
        $this->patientName = '';
        $this->phone = '';
        $this->age = '';
    }

    public function selectVisitType(int $visitTypeId): void
    {
        $this->visitTypeId = $visitTypeId;

        // Slots are per visit type, so the chosen one may no longer exist.
        $this->startTime = null;
    }

    public function selectKind(string $kind): void
    {
        $this->kind = $kind;

        if ($this->isEmergency()) {
            // An emergency is seen now: no slot, and today by definition.
            $this->startTime = null;
            $this->date = Carbon::now($this->clinic()->timezone)->toDateString();
            $this->patientLocation ??= PatientLocation::INSIDE_CLINIC->value;
        } else {
            $this->patientLocation = null;
        }
    }

    public function selectDay(string $date): void
    {
        $this->date = $date;
        $this->startTime = null;
    }

    public function selectSlot(string $startTime): void
    {
        $this->startTime = $startTime;
    }

    public function isEmergency(): bool
    {
        return $this->kind === BookingKind::EMERGENCY->value;
    }

    /*
    |--------------------------------------------------------------------------
    | Saving
    |--------------------------------------------------------------------------
    */

    public function save(): void
    {
        $this->validate();

        try {
            $booking = app(BookingService::class)->create(
                $this->clinic(),
                new BookingData(
                    patientId: $this->patientId,
                    patientName: $this->patientId === null ? $this->patientName : null,
                    phone: $this->patientId === null ? $this->phone : null,
                    age: $this->age === '' ? null : (int) $this->age,
                    whatsappOptIn: $this->whatsappOptIn,
                    visitTypeId: (int) $this->visitTypeId,
                    date: $this->date,
                    startTime: $this->isEmergency() ? null : $this->startTime,
                    bookingKind: BookingKind::from($this->kind),
                    patientLocation: $this->patientLocation === null
                        ? null
                        : PatientLocation::from($this->patientLocation),
                    notes: $this->notes === '' ? null : $this->notes,
                    rebookingForBookingId: $this->rebookingFor,
                ),
                auth()->user(),
            );

            $this->afterSave($booking);
        } catch (ApiException $e) {
            // Rendered as JSON on the API; on a form it belongs on the page.
            $this->notice = $e->getMessage();
            $this->failed = true;
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['nullable', 'integer'],
            'patientName' => ['required_without:patientId', 'nullable', 'string', 'max:255'],
            'phone' => ['required_without:patientId', 'nullable', 'string', 'max:32'],
            'age' => ['nullable', 'numeric', 'min:0', 'max:130'],
            'visitTypeId' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', 'in:'.implode(',', BookingKind::values())],
            'patientLocation' => [
                $this->isEmergency() ? 'required' : 'nullable',
                'in:'.implode(',', PatientLocation::values()),
            ],
            'startTime' => [
                $this->isEmergency() ? 'nullable' : 'required',
                'nullable',
                'date_format:H:i',
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'patientName' => __('app.booking.patient_name'),
            'phone' => __('app.booking.phone'),
            'age' => __('app.booking.age'),
            'visitTypeId' => __('app.booking.visit_type'),
            'date' => __('app.booking.day'),
            'startTime' => __('app.booking.slot'),
            'patientLocation' => __('app.booking.patient_location'),
        ];
    }

    private function afterSave(Booking $booking): void
    {
        $this->notice = __('booking.created');
        $this->failed = false;
        $this->trackingUrl = $booking->trackingUrl();

        // Ready for the next patient, but keep the day the secretary is on.
        // The rebooking link is one-shot: the original is now spoken for, so
        // carrying it into the next booking would only fail.
        $this->reset([
            'patientSearch', 'patientId', 'patientName', 'phone', 'age',
            'startTime', 'notes', 'patientLocation', 'rebookingFor',
        ]);

        $this->kind = BookingKind::NORMAL->value;
        $this->whatsappOptIn = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * @return Collection<int, Patient>
     */
    private function patientResults(): Collection
    {
        if ($this->patientId !== null || mb_strlen(trim($this->patientSearch)) < 2) {
            return collect();
        }

        return collect(app(PatientSearchService::class)
            ->search($this->clinic(), $this->patientSearch, 8)
            ->items());
    }

    /**
     * Null for an emergency — it does not take a slot.
     */
    private function availability(): ?DayAvailability
    {
        if ($this->isEmergency() || $this->visitTypeId === null) {
            return null;
        }

        $visitType = $this->clinic()->visitTypes()->active()->whereKey($this->visitTypeId)->first();

        if ($visitType === null) {
            return null;
        }

        return app(SlotAvailabilityService::class)->for(
            $this->clinic(),
            $this->safeDate($this->date),
            $visitType,
        );
    }
}
