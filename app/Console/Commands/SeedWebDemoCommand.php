<?php

namespace App\Console\Commands;

use App\DTOs\V1\Booking\BookingData;
use App\Enums\BookingKind;
use App\Enums\PatientLocation;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\User;
use App\Models\VisitType;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Booking\Slot;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Queue\BookingStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Puts a clinic into a state worth looking at: today's queue with patients in
 * every status, and a tracking link printed for each one.
 *
 * Everything is created through the real services, so running this exercises
 * slot availability, the queue ordering and the confirmation send against the
 * actual database rather than fixtures.
 */
class SeedWebDemoCommand extends Command
{
    protected $signature = 'clinic:seed-web-demo
                            {--clinic= : Clinic id (defaults to the first one)}
                            {--fresh : Delete today\'s existing bookings first}';

    protected $description = "Set up today's queue for testing the web flow, and print every link to open";

    /** @var list<array{name: string, phone: string, state: string}> */
    private const PATIENTS = [
        ['name' => 'سلمى محمود', 'phone' => '01099000001', 'state' => 'with_doctor'],
        ['name' => 'هدى عادل', 'phone' => '01099000002', 'state' => 'arrived'],
        ['name' => 'منى سيد', 'phone' => '01099000003', 'state' => 'booked'],
        ['name' => 'نورا فتحي', 'phone' => '01099000004', 'state' => 'booked'],
        ['name' => 'رانيا سمير', 'phone' => '01099000005', 'state' => 'done'],
    ];

    public function handle(
        BookingService $bookings,
        BookingStatusService $statuses,
        SlotAvailabilityService $slots,
        WhatsAppMessagingService $messaging,
    ): int {
        $clinic = $this->option('clinic')
            ? Clinic::find($this->option('clinic'))
            : Clinic::first();

        if ($clinic === null) {
            $this->error('No clinic found. Run: php artisan db:seed --class=DemoClinicSeeder');

            return self::FAILURE;
        }

        $actor = $this->actorFor($clinic);
        $visitType = $clinic->visitTypes()->active()->first();
        $today = Carbon::now($clinic->timezone);

        if ($actor === null || $visitType === null) {
            $this->error('That clinic has no staff account or no active visit type.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $deleted = $clinic->bookings()->onDate($today->toDateString())->delete();
            $this->line("Removed {$deleted} booking(s) from today.");
        }

        $this->newLine();
        $this->info("Clinic: {$clinic->name}");
        $this->line('Today:  '.$today->format('Y-m-d (l) H:i').'   free slots: '.$this->freeSlots($slots, $clinic, $today, $visitType)->count());
        $this->newLine();

        $rows = [];

        foreach (self::PATIENTS as $index => $patient) {
            // Recomputed each time: the slot step is shorter than a visit, so
            // booking one slot closes the ones that overlap it.
            // Falls back to an emergency when the day has no slot left — a real
            // booking either way, just without a time.
            $slot = $this->freeSlots($slots, $clinic, $today, $visitType)->first();

            $booking = $bookings->create($clinic, new BookingData(
                patientId: null,
                patientName: $patient['name'],
                phone: $patient['phone'],
                age: 30 + $index,
                whatsappOptIn: true,
                visitTypeId: $visitType->id,
                date: $today->toDateString(),
                startTime: $slot?->startAt->format('H:i'),
                bookingKind: $slot === null ? BookingKind::EMERGENCY : BookingKind::NORMAL,
                patientLocation: $slot === null ? PatientLocation::ON_WAY : null,
            ), $actor);

            $this->advanceTo($statuses, $booking, $patient['state']);
            $messaging->sendConfirmation($clinic, $booking);

            $rows[] = [
                $booking->fresh()->status->value,
                $patient['name'],
                $slot?->startAt->format('H:i') ?? '—',
                url(config('clinic.tracking.path').'/'.$booking->tracking_token),
            ];
        }

        $this->table(['status', 'patient', 'time', 'tracking link'], $rows);

        $this->newLine();
        $this->line('  Landing page   '.url($clinic->slug));
        $this->line('  Clinic app     '.route('app.login'));
        $this->line('  Admin panel    '.url(config('clinic.panel.path')));
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Slot>
     */
    private function freeSlots(
        SlotAvailabilityService $slots,
        Clinic $clinic,
        Carbon $date,
        VisitType $visitType,
    ): Collection {
        return collect($slots->for($clinic, $date, $visitType)->slots)
            ->filter(fn ($slot): bool => $slot->isAvailable)
            ->values();
    }

    /**
     * Walks a booking to the state we want it in, through the real transitions
     * rather than by writing the column.
     */
    private function advanceTo(BookingStatusService $statuses, Booking $booking, string $state): void
    {
        if ($state === 'booked') {
            return;
        }

        $statuses->arrive($booking);

        if (in_array($state, ['with_doctor', 'done'], true)) {
            $statuses->callIn($booking);
        }

        if ($state === 'done') {
            $statuses->complete($booking);
        }
    }

    private function actorFor(Clinic $clinic): ?User
    {
        return User::query()
            ->whereIn('id', $clinic->staff()->pluck('users.id'))
            ->first();
    }
}
