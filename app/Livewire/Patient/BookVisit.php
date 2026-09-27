<?php

namespace App\Livewire\Patient;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\VisitType;
use App\Services\V1\Booking\PatientBookingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The public booking page — the one screen a patient uses to book themselves.
 *
 * **Nothing on this class may be trusted.** Livewire's public properties are
 * whatever the browser last sent, and this page has no login at all: anybody
 * can post `step: 4` straight at `/livewire/update`. So `$step` chooses only
 * between the three screens that come *before* verification, all of which are
 * harmless, and the real stage is derived from the session on every render —
 * see stage().
 *
 * The component orchestrates and renders. Every rule lives in
 * PatientBookingService, which re-asks each one before it writes, because the
 * render that offered the choice happened on data the browser could since have
 * changed.
 */
class BookVisit extends Component
{
    public string $slug = '';

    /**
     * Which of the pre-verification screens to show: 1 the week, 2 the
     * details, 3 the code. It can never select the appointment screen —
     * stage() decides that from the session.
     */
    public int $step = 1;

    public string $name = '';

    public string $phone = '';

    public string $code = '';

    public ?int $visitTypeId = null;

    public string $date = '';

    public ?string $startTime = null;

    /** The slot this browser is sitting on. */
    public ?string $holdToken = null;

    /**
     * Set after a successful booking. Re-checked against the verified phone on
     * every render, so pointing it at somebody else's booking shows nothing.
     */
    public ?int $confirmedBookingId = null;

    public ?string $notice = null;

    public bool $failed = false;

    private ?Clinic $resolved = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $clinic = $this->clinic();

        $this->date = Carbon::now($clinic->timezone)->toDateString();

        // The proof of the phone is in the session and survives a reload; the
        // name is a property on this class and does not. Without this, coming
        // back inside the verified window lands on the appointment screen with
        // no name — and confirm() then refuses on a field that screen does not
        // show, which is a dead end with nothing to press.
        //
        // The clinic's record wins over anything typed, which is also what
        // PatientService does: a name only ever creates a patient, it never
        // renames one.
        $patient = $this->service()->verifiedPatient($clinic);

        if ($patient !== null) {
            $this->name = $patient->name;
        }

        // Prefilled either way, so the details screen is never blank for
        // somebody whose number this browser has already proved.
        $this->phone = $this->service()->verifiedPhone($clinic) ?? '';

        $this->visitTypeId = $this->service()->defaultVisitType($clinic, $patient)?->id;
    }

    public function render(): View
    {
        $clinic = $this->clinic();
        $stage = $this->stage();

        return view('livewire.patient.book-visit', [
            'clinic' => $clinic,
            'doctor' => $clinic->doctor,
            'stage' => $stage,
            // The same day list either way, counted against whichever visit
            // type is in play — how long a visit takes changes how many of
            // them fit, so the dot on each day has to follow the choice.
            'days' => in_array($stage, ['overview', 'appointment'], true)
                ? $this->service()->overview($clinic, $this->selectedVisitType())
                : [],
            'visitTypes' => $this->service()->visitTypes($clinic),
            'availability' => $stage === 'appointment' ? $this->availability() : null,
            'upcoming' => $stage === 'upcoming' ? $this->upcoming() : null,
            'confirmed' => $stage === 'done' ? $this->confirmed() : null,
            'verifiedPhone' => $this->service()->verifiedPhone($clinic),
            'whatsappUrl' => $this->service()->clinicWhatsAppUrl($clinic),
            'stepNumber' => match ($stage) {
                'details' => 1,
                'code' => 2,
                'appointment' => 3,
                default => null,
            },
            'stepCount' => 3,
            'holdMinutes' => (int) config('clinic.self_booking.hold_ttl_minutes'),
            'showPrice' => (bool) config('clinic.self_booking.show_price'),
            'codeLength' => (int) config('clinic.self_booking.otp.length'),
            'codeMinutes' => (int) config('clinic.self_booking.otp.ttl_minutes'),
            'resendSeconds' => (int) config('clinic.self_booking.otp.resend_cooldown'),
        ])
            ->layout('components.layouts.patient')
            ->title(__('booking.self_booking.title', [
                'doctor' => $clinic->doctor?->name ?? $clinic->name,
            ]));
    }

    /*
    |--------------------------------------------------------------------------
    | Where we actually are
    |--------------------------------------------------------------------------
    */

    /**
     * The stage, derived — never taken from the request.
     *
     * Before the phone is proved, `$step` picks among three screens that ask
     * for nothing and write nothing. After it is proved, the page goes where
     * the patient's own situation says it should. There is no arrangement of
     * properties that reaches 'appointment' without a verified session.
     */
    private function stage(): string
    {
        $clinic = $this->clinic();

        if ($this->service()->verifiedPhone($clinic) === null) {
            return match ($this->step) {
                2 => 'details',
                3 => 'code',
                default => 'overview',
            };
        }

        if ($this->confirmed() !== null) {
            return 'done';
        }

        // Verified, but we have no name to book under: a first-time patient
        // who proved their number and then reloaded before finishing. There is
        // no record to recover it from, so ask again rather than show a screen
        // whose only button refuses.
        if (trim($this->name) === '') {
            return 'details';
        }

        if ($this->upcoming() !== null) {
            return 'upcoming';
        }

        return 'appointment';
    }

    /*
    |--------------------------------------------------------------------------
    | Moving through it
    |--------------------------------------------------------------------------
    */

    /**
     * Somebody else's move changed this day.
     *
     * The body is empty on purpose: the work is the re-render this triggers,
     * which re-reads availability through the same service the first render
     * used. There is no separate "refresh" path to keep in step.
     *
     * A slot this browser is holding stays held — availability is asked with
     * our own hold token, so our claim survives the redraw.
     */
    #[On('slots-changed')]
    public function slotsChanged(): void {}

    public function start(): void
    {
        $this->step = 2;
        $this->clearNotice();
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
        $this->clearNotice();
    }

    public function sendCode(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $this->run(function (): void {
            $this->service()->requestCode(
                $this->clinic(),
                $this->phone,
                request()->ip(),
            );

            $this->code = '';
            $this->step = 3;
        }, __('patient.otp.sent'));
    }

    public function resendCode(): void
    {
        $this->run(function (): void {
            $this->service()->requestCode($this->clinic(), $this->phone, request()->ip());
            $this->code = '';
        }, __('patient.otp.sent'));
    }

    public function verifyCode(): void
    {
        $this->validate(['code' => ['required', 'string']]);

        $this->run(function (): void {
            $clinic = $this->clinic();

            $this->service()->verifyCode($clinic, $this->phone, $this->code);

            // The appointment screen needs a starting point, and a returning
            // patient usually wants what they had last time.
            $this->visitTypeId = $this->service()
                ->defaultVisitType($clinic, $this->service()->verifiedPatient($clinic))?->id;
            $this->date = Carbon::now($clinic->timezone)->toDateString();
            $this->code = '';
        }, __('patient.otp.verified'));
    }

    /** Back to the details screen with the proof dropped. */
    public function changeNumber(): void
    {
        $this->releaseHold();
        $this->service()->forgetVerification($this->clinic());

        $this->step = 2;
        $this->code = '';
        $this->clearNotice();
    }

    /*
    |--------------------------------------------------------------------------
    | Choosing a time
    |--------------------------------------------------------------------------
    */

    public function selectVisitType(int $visitTypeId): void
    {
        $this->visitTypeId = $visitTypeId;
        $this->startTime = null;
        $this->releaseHold();
        $this->clearNotice();
    }

    public function selectDay(string $date): void
    {
        $this->date = $date;
        $this->startTime = null;
        $this->releaseHold();
        $this->clearNotice();
    }

    /**
     * Claims the slot as it is tapped, so everybody else sees it go. If
     * somebody was faster the page says so now, while re-picking costs a tap.
     */
    public function selectSlot(string $startTime): void
    {
        $this->run(function () use ($startTime): void {
            $this->holdToken = $this->service()->holdSlot(
                $this->clinic(),
                (int) $this->visitTypeId,
                $this->date,
                $startTime,
                $this->holdToken,
            );

            $this->startTime = $startTime;
        }, null, function (): void {
            $this->startTime = null;
        });
    }

    public function confirm(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'visitTypeId' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'startTime' => ['required', 'date_format:H:i'],
        ]);

        $this->run(function (): void {
            $booking = $this->service()->confirm(
                $this->clinic(),
                $this->name,
                (int) $this->visitTypeId,
                $this->date,
                (string) $this->startTime,
                $this->holdToken,
            );

            $this->confirmedBookingId = $booking->id;
            $this->holdToken = null;
            $this->startTime = null;
        }, null);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    public function clinic(): Clinic
    {
        // Re-resolved from the URL each request. A slug sent in the payload
        // would be a way to book at a clinic this page never opened.
        return $this->resolved ??= $this->service()->clinicFor($this->slug);
    }

    private function availability(): mixed
    {
        $visitType = $this->selectedVisitType();

        return $visitType === null
            ? null
            : $this->service()->availability($this->clinic(), $visitType, $this->date, $this->holdToken);
    }

    public function selectedVisitType(): ?VisitType
    {
        return $this->service()->visitTypes($this->clinic())->firstWhere('id', $this->visitTypeId);
    }

    private function upcoming(): ?Booking
    {
        return $this->service()->upcomingBooking($this->clinic());
    }

    /**
     * The booking just made — matched against the verified phone, so a tampered
     * id shows nothing rather than somebody else's appointment.
     */
    private function confirmed(): ?Booking
    {
        $phone = $this->service()->verifiedPhone($this->clinic());

        if ($this->confirmedBookingId === null || $phone === null) {
            return null;
        }

        return $this->clinic()->bookings()
            ->with(['patient', 'visitType'])
            ->whereKey($this->confirmedBookingId)
            ->whereHas('patient', fn ($query) => $query->where('phone', $phone))
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    private function service(): PatientBookingService
    {
        return app(PatientBookingService::class);
    }

    private function releaseHold(): void
    {
        $this->service()->releaseSlot($this->holdToken);

        $this->holdToken = null;
    }

    private function clearNotice(): void
    {
        $this->notice = null;
        $this->failed = false;
    }

    /**
     * ApiException renders itself as a JSON envelope, which would break the
     * Livewire response — so it is caught and shown on the page instead. The
     * message is already translated.
     *
     * @param  callable|null  $onFailure  tidy-up when the action was refused
     */
    private function run(callable $action, ?string $success, ?callable $onFailure = null): void
    {
        try {
            $action();

            $this->notice = $success;
            $this->failed = false;
        } catch (ApiException $e) {
            $this->notice = $e->getMessage();
            $this->failed = true;

            if ($onFailure !== null) {
                $onFailure();
            }
        }
    }
}
