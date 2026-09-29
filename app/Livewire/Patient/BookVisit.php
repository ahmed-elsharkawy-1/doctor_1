<?php

namespace App\Livewire\Patient;

use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\VisitType;
use App\Services\V1\Booking\PatientBookingService;
use App\Services\V1\Booking\SlotGroup;
use App\Support\HeldSlotSession;
use Illuminate\Contracts\View\View;
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

    /**
     * Which stretch of the day is open, if any.
     *
     * The first one with anything free opens by itself. A page where every
     * stretch is shut shows no slots at all, which reads as a finished screen
     * rather than one waiting to be opened — the patient came to pick a time
     * and cannot see a single one. One stretch open says what these rows are
     * and that the others behave the same way, at the cost of about three rows
     * of scrolling.
     */
    public ?int $openGroup = null;

    /**
     * Whether the patient has worked the accordion themselves.
     *
     * Only to tell "not opened yet" from "deliberately closed": without it,
     * closing the stretch that opened by itself would reopen on the very next
     * render.
     */
    public bool $pickedGroup = false;

    /**
     * Set after a successful booking. Re-checked against the verified phone on
     * every render, so pointing it at somebody else's booking shows nothing.
     */
    public ?int $confirmedBookingId = null;

    public ?string $notice = null;

    public bool $failed = false;

    private ?Clinic $resolved = null;

    /**
     * The day strip, built once per request.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $days = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $clinic = $this->clinic();

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

        // The first day with room, not today.
        //
        // startPickingATime() does this too, but only on the way *into* the
        // appointment screen. A reload never crosses that line — the session
        // is already verified, so stage() lands straight on 'appointment' —
        // and today, which may well be full or closed, would be selected with
        // nothing under it. After the visit type, because how long a visit
        // takes decides how many fit and therefore which day is the first with
        // room.
        $this->date = $this->service()->firstBookableDate(
            $clinic,
            $this->selectedVisitType(),
            $this->days($clinic),
        );
    }

    /**
     * The window, counted against the visit type in play.
     *
     * Memoised because a full page load needs it twice — once in mount() to
     * pick the opening day, once to render the strip — and building it walks
     * every day in the window working out how many visits still fit.
     *
     * Nothing clears it, and nothing needs to. A Livewire component is built
     * fresh for every request and this property is private, so it never
     * outlives one; and inside a request the two things that would change the
     * answer — the visit type, and writing a booking — are both settled by an
     * action before render() asks. Call this before either and the strip goes
     * stale.
     *
     * @return list<array<string, mixed>>
     */
    private function days(Clinic $clinic): array
    {
        return $this->days ??= $this->service()->overview($clinic, $this->selectedVisitType());
    }

    public function render(): View
    {
        $clinic = $this->clinic();
        $stage = $this->stage();
        $availability = $stage === 'appointment' ? $this->availability() : null;

        $slotGroups = $availability === null
            ? []
            : $this->service()->slotGroups($clinic, $availability);

        // Here rather than in the day and visit-type handlers: this is the one
        // place the groups are already built, and working out which stretch
        // has a free slot anywhere else would mean querying the day twice.
        $this->openFirstFreeGroup($slotGroups);

        return view('livewire.patient.book-visit', [
            'clinic' => $clinic,
            'doctor' => $clinic->doctor,
            'stage' => $stage,
            // The same day list either way, counted against whichever visit
            // type is in play — how long a visit takes changes how many of
            // them fit, so the dot on each day has to follow the choice.
            'days' => in_array($stage, ['overview', 'appointment'], true)
                ? $this->days($clinic)
                : [],
            'visitTypes' => $this->service()->visitTypes($clinic),
            'availability' => $stage === 'appointment' ? $availability : null,
            'slotGroups' => $slotGroups,
            'upcoming' => $stage === 'upcoming' ? $this->upcoming() : null,
            'confirmed' => $stage === 'done' ? $this->confirmed() : null,
            'verifiedPhone' => $this->service()->verifiedPhone($clinic),
            'whatsappUrl' => $this->service()->clinicWhatsAppUrl($clinic),
            // One fewer step when there is no code to confirm, so the counter
            // never promises a screen the patient will not see.
            'stepNumber' => match ($stage) {
                'details' => 1,
                'code' => 2,
                'appointment' => $this->service()->requiresOtp() ? 3 : 2,
                default => null,
            },
            'stepCount' => $this->service()->requiresOtp() ? 3 : 2,
            'requiresOtp' => $this->service()->requiresOtp(),
            'otpChannel' => $this->service()->otpChannel(),
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
                // Never the code screen when there is no code to ask for —
                // $step is client-editable, so this is a guard, not a branch.
                3 => $this->service()->requiresOtp() ? 'code' : 'details',
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
        $this->clearCode();
        $this->clearNotice();
    }

    public function sendCode(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $this->run(function (): void {
            $clinic = $this->clinic();

            // Verification switched off: the number is parsed and believed,
            // and the patient goes straight to choosing a time. Same session,
            // same guards after this point — only the proof is missing.
            if (! $this->service()->requiresOtp()) {
                $this->service()->acceptPhoneUnverified($clinic, $this->phone);
                $this->startPickingATime($clinic);

                return;
            }

            $this->service()->requestCode($clinic, $this->phone, request()->ip());

            $this->clearCode();
            $this->step = 3;
        }, $this->service()->requiresOtp() ? __('patient.otp.sent') : null);
    }

    public function resendCode(): void
    {
        $this->run(function (): void {
            $this->service()->requestCode($this->clinic(), $this->phone, request()->ip());
            $this->clearCode();
        }, __('patient.otp.sent'));
    }

    public function verifyCode(): void
    {
        $this->validate(['code' => ['required', 'string']]);

        $this->run(function (): void {
            $clinic = $this->clinic();

            $this->service()->verifyCode($clinic, $this->phone, $this->code);

            $this->startPickingATime($clinic);
            $this->clearCode();
        }, __('patient.otp.verified'), function (): void {
            // Refused. The digits on screen are known to be wrong, and the
            // field behind the boxes cannot be edited a character at a time —
            // so leaving them there only invites the same code again.
            $this->clearCode();
        });
    }

    /**
     * Empties the code, on the server and in the browser.
     *
     * Both halves are needed. `wire:model` here is deferred, so after a
     * re-render the input still holds whatever was typed into it — and the
     * script that mirrors it into the boxes reads that value back and paints
     * the old code straight over the cleared one. Asking a patient for a new
     * code while the previous one is still sitting in the boxes is how they
     * submit the expired one again.
     */
    private function clearCode(): void
    {
        $this->code = '';
        $this->dispatch('code-cleared');
    }

    /**
     * Everything the slot screen needs on arrival, however the patient got
     * there. A returning patient usually wants the visit type they had last
     * time, and the day starts at the first one with room.
     *
     * mount() settles both as well, for a reload that lands here without
     * passing through. This runs when verification has just told us who the
     * patient is, which can change the visit type and so the day.
     */
    private function startPickingATime(Clinic $clinic): void
    {
        $this->visitTypeId = $this->service()
            ->defaultVisitType($clinic, $this->service()->verifiedPatient($clinic))?->id;

        $this->date = $this->service()->firstBookableDate(
            $clinic,
            $this->selectedVisitType(),
            $this->days($clinic),
        );
    }

    /** Back to the details screen with the proof dropped. */
    public function changeNumber(): void
    {
        $this->releaseHold();
        $this->service()->forgetVerification($this->clinic());

        $this->step = 2;
        $this->clearCode();
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
        $this->forgetOpenGroup();
        $this->startTime = null;
        $this->releaseHold();
        $this->clearNotice();
    }

    /** Opens a stretch, or closes the one already open. */
    public function toggleGroup(int $index): void
    {
        $this->pickedGroup = true;
        $this->openGroup = $this->openGroup === $index ? null : $index;
    }

    public function selectDay(string $date): void
    {
        $this->date = $date;
        $this->forgetOpenGroup();
        $this->startTime = null;
        $this->releaseHold();
        $this->clearNotice();
    }

    /**
     * A different day is a different set of stretches, so the choice made on
     * the old one means nothing here and the first free stretch opens again.
     */
    private function forgetOpenGroup(): void
    {
        $this->openGroup = null;
        $this->pickedGroup = false;
    }

    /**
     * Opens the first stretch with a free slot, once.
     *
     * Deliberately not re-run against a stretch that is already open: a slot
     * taken elsewhere can empty the open stretch mid-visit, and moving the
     * page out from under somebody reading it is worse than leaving them on a
     * stretch whose rows have gone. Day and visit type reset it; nothing else
     * does.
     *
     * @param  list<SlotGroup>  $groups
     */
    private function openFirstFreeGroup(array $groups): void
    {
        if ($this->pickedGroup || $this->openGroup !== null) {
            return;
        }

        foreach ($groups as $group) {
            if ($group->hasAnythingFree()) {
                $this->openGroup = $group->index;

                return;
            }
        }
    }

    /**
     * Claims the slot as it is tapped, so everybody else sees it go. If
     * somebody was faster the page says so now, while re-picking costs a tap.
     */
    public function selectSlot(string $startTime): void
    {
        $this->run(function () use ($startTime): void {
            // Its own token goes in, so the claim moves rather than a second
            // one appearing beside it.
            $token = $this->service()->holdSlot(
                $this->clinic(),
                (int) $this->visitTypeId,
                $this->date,
                $startTime,
                $this->holdToken(),
            );

            $this->heldSlot()->remember($this->clinic(), $token);

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
                $this->holdToken(),
            );

            $this->confirmedBookingId = $booking->id;
            $this->heldSlot()->forget($this->clinic());
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
            : $this->service()->availability($this->clinic(), $visitType, $this->date, $this->holdToken());
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

    private function heldSlot(): HeldSlotSession
    {
        return app(HeldSlotSession::class);
    }

    /**
     * The slot this browser is sitting on.
     *
     * Read from the session rather than held on this class. As a public
     * property it was rebuilt from the browser on every request and lost on a
     * reload — and a request carrying no token is told, correctly, that every
     * live hold belongs to somebody else, so the patient's own claim struck
     * out their own slot until it lapsed.
     */
    private function holdToken(): ?string
    {
        return $this->heldSlot()->tokenFor($this->clinic());
    }

    private function releaseHold(): void
    {
        $this->service()->releaseSlot($this->holdToken());

        $this->heldSlot()->forget($this->clinic());
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
