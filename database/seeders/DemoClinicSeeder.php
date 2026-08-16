<?php

namespace Database\Seeders;

use App\Actions\Clinic\ProvisionClinicAction;
use App\DTOs\V1\Booking\BookingData;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Patients\PatientService;
use App\Services\V1\Queue\BookingStatusService;
use App\Support\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A realistic clinic to develop and demo against.
 *
 * Today's bookings are created through the **real** BookingService and
 * BookingStatusService rather than by inserting rows, so running this seeder
 * exercises patient codes, phone normalisation, slot availability, the write
 * lock and the price snapshot against the actual database.
 *
 * History is backfilled directly, because the booking window quite correctly
 * refuses to accept appointments in the past.
 *
 * Safe to re-run: the demo clinic is removed and rebuilt each time.
 */
class DemoClinicSeeder extends Seeder
{
    private const CLINIC_NAME = 'عيادة د. سارة النجار';

    private const OWNER_EMAIL = 'doctor@doctor1.test';

    private const SECRETARY_EMAIL = 'nour@doctor1.test';

    private const PASSWORD = 'password';

    /**
     * @var list<array{0: string, 1: string}>
     */
    private const PATIENTS = [
        ['سارة أحمد', '01012225521'],
        ['منى عبد الله', '01012345678'],
        ['هدى سمير', '01223334432'],
        ['ريم خالد', '01098887791'],
        ['نور محمد', '01111112210'],
        ['ياسمين علي', '01555556634'],
        ['فاطمة الزهراء', '01011112222'],
        ['أمل حسن', '01022223333'],
        ['دينا مصطفى', '01233334444'],
        ['شيماء إبراهيم', '01144445555'],
        ['مريم طارق', '01055556666'],
        ['نهى فؤاد', '01266667777'],
        ['رانيا عادل', '01177778888'],
        ['إيمان صلاح', '01088889999'],
        ['هبة ياسر', '01299990000'],
        ['سلمى وليد', '01100001111'],
    ];

    public function run(): void
    {
        // Self-sufficient on purpose: this seeder alone leaves a usable system,
        // including the super admin needed to reach the panel.
        $this->call(DatabaseSeeder::class);

        $clinic = $this->freshClinic();
        $doctor = $this->doctor($clinic);
        [$owner, $secretary] = $this->staff($clinic, $doctor);

        $this->priceVisitTypes($clinic);
        $this->openTheWeek($clinic);
        $this->addHoliday($clinic);

        $patients = $this->patients($clinic);
        $this->backfillHistory($clinic, $doctor, $patients, $owner);
        $this->todaysBookings($clinic, $patients, $secretary);
        $this->awaitingRebooking($clinic, $doctor, $patients);

        $this->report($clinic, $owner, $secretary);
    }

    private function freshClinic(): Clinic
    {
        // Cascades through doctors, visit types, schedules, patients, bookings.
        Clinic::where('name', self::CLINIC_NAME)->get()->each->delete();

        $clinic = Clinic::create([
            'specialty_id' => Specialty::where('slug', 'obstetrics-gynecology')->value('id'),
            'name' => self::CLINIC_NAME,
            'address' => '١٢ شارع مصدق، الدقي، الجيزة',
            'phone' => '+201001234567',
            'timezone' => config('clinic.defaults.timezone'),
            'country_code' => config('clinic.phone.default_country'),
            'booking_window_days' => config('clinic.defaults.booking_window_days'),
            'first_visit_only_days' => config('clinic.defaults.first_visit_only_days'),
            'slot_step_minutes' => config('clinic.defaults.slot_step_minutes'),
            'patient_arrival_lead_minutes' => config('clinic.defaults.patient_arrival_lead_minutes'),
            'is_active' => true,
        ]);

        app(ProvisionClinicAction::class)->execute($clinic);

        return $clinic->refresh();
    }

    private function doctor(Clinic $clinic): Doctor
    {
        return $clinic->doctors()->create([
            'name' => 'د. سارة النجار',
            'phone' => '+201001234567',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function staff(Clinic $clinic, Doctor $doctor): array
    {
        $owner = User::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'name' => 'د. سارة النجار',
                'password' => Hash::make(self::PASSWORD),
                'role' => UserRole::CLINIC,
                'doctor_id' => $doctor->id,
                'phone' => '+201001234567',
                'locale' => 'ar',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $secretary = User::updateOrCreate(
            ['email' => self::SECRETARY_EMAIL],
            [
                'name' => 'نور محمد',
                'password' => Hash::make(self::PASSWORD),
                'role' => UserRole::CLINIC,
                'phone' => '+201009876543',
                'locale' => 'ar',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $owner->clinics()->sync([$clinic->id]);
        $secretary->clinics()->sync([$clinic->id]);

        return [$owner, $secretary];
    }

    private function priceVisitTypes(Clinic $clinic): void
    {
        $prices = [
            'كشف' => 400,
            'إعادة' => 200,
            'سونار' => 350,
            'متابعة حمل' => 300,
        ];

        foreach ($clinic->visitTypes as $visitType) {
            $visitType->update(['price' => $prices[$visitType->name] ?? 250]);
        }
    }

    /**
     * Saturday runs a split day, matching the mockup. Friday is closed —
     * unless today is Friday, in which case the demo would have nothing to
     * show.
     */
    private function openTheWeek(Clinic $clinic): void
    {
        $today = DayOfWeek::fromDate(Carbon::now($clinic->timezone));

        $hours = [
            DayOfWeek::SATURDAY->value => [['13:00', '15:00'], ['17:00', '21:00']],
            DayOfWeek::SUNDAY->value => [['09:00', '14:00']],
            DayOfWeek::MONDAY->value => [['09:00', '14:00']],
            DayOfWeek::TUESDAY->value => [['09:00', '14:00']],
            DayOfWeek::WEDNESDAY->value => [['09:00', '14:00']],
            DayOfWeek::THURSDAY->value => [['10:00', '13:00']],
        ];

        if (! isset($hours[$today->value])) {
            $hours[$today->value] = [['09:00', '14:00']];
        }

        foreach ($clinic->schedules as $schedule) {
            $periods = $hours[$schedule->day_of_week->value] ?? null;

            $schedule->periods()->delete();
            $schedule->update(['is_open' => $periods !== null]);

            foreach ($periods ?? [] as [$start, $end]) {
                $schedule->periods()->create(['start_time' => $start, 'end_time' => $end]);
            }
        }

        $clinic->load('schedules.periods');
    }

    private function addHoliday(Clinic $clinic): void
    {
        $clinic->holidays()->create([
            'date' => Carbon::now($clinic->timezone)->addDays(4)->toDateString(),
            'note' => 'سفر',
        ]);
    }

    /**
     * Created through PatientService so the ID codes are generated by the real
     * action rather than made up here.
     *
     * @return list<Patient>
     */
    private function patients(Clinic $clinic): array
    {
        $service = app(PatientService::class);
        $patients = [];

        foreach (self::PATIENTS as [$name, $phone]) {
            $patients[] = $service->findOrCreate(
                $clinic,
                $name,
                PhoneNumber::parse($phone, $clinic->country_code),
            );
        }

        return $patients;
    }

    /**
     * Five months of completed visits, weighted so retention has something to
     * say: some patients returned, some came once and never again, and a few
     * are too recent to judge.
     *
     * @param  list<Patient>  $patients
     */
    private function backfillHistory(Clinic $clinic, Doctor $doctor, array $patients, User $actor): void
    {
        $now = Carbon::now($clinic->timezone);
        $visitTypes = $clinic->visitTypes->keyBy('name');

        // [patient index, days ago, visit type, status]
        $plan = [
            // Loyal: first visit long ago, came back repeatedly.
            [0, 150, 'كشف', BookingStatus::DONE],
            [0, 120, 'إعادة', BookingStatus::DONE],
            [0, 40, 'سونار', BookingStatus::DONE],
            [1, 140, 'كشف', BookingStatus::DONE],
            [1, 100, 'متابعة حمل', BookingStatus::DONE],
            [1, 60, 'متابعة حمل', BookingStatus::DONE],
            [2, 130, 'كشف', BookingStatus::DONE],
            [2, 70, 'إعادة', BookingStatus::DONE],

            // Came once, long enough ago to count as never returned.
            [3, 125, 'كشف', BookingStatus::DONE],
            [4, 110, 'كشف', BookingStatus::DONE],
            [5, 95, 'كشف', BookingStatus::DONE],

            // A no-show and a cancellation, so the history screen has both.
            [6, 90, 'كشف', BookingStatus::NO_SHOW],
            [7, 85, 'إعادة', BookingStatus::CANCELLED],

            // This month, so revenue has a running total.
            [8, 6, 'كشف', BookingStatus::DONE],
            [9, 5, 'سونار', BookingStatus::DONE],
            [10, 4, 'كشف', BookingStatus::DONE],
            [11, 3, 'متابعة حمل', BookingStatus::DONE],
            [12, 2, 'إعادة', BookingStatus::DONE],
            [8, 1, 'إعادة', BookingStatus::DONE],

            // Last month, so the month-over-month comparison is not against zero.
            [13, 38, 'كشف', BookingStatus::DONE],
            [14, 36, 'سونار', BookingStatus::DONE],
            [15, 34, 'كشف', BookingStatus::DONE],
        ];

        foreach ($plan as $index => [$patientIndex, $daysAgo, $typeName, $status]) {
            $visitType = $visitTypes[$typeName];
            $startAt = $now->copy()->subDays($daysAgo)->setTime(9 + ($index % 4), ($index % 3) * 20);

            $cancelReason = $status === BookingStatus::CANCELLED
                ? CancelReason::PATIENT_CANCELLED
                : null;

            Booking::create([
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'patient_id' => $patients[$patientIndex]->id,
                'visit_type_id' => $visitType->id,
                'visit_date' => $startAt->toDateString(),
                'start_at' => $startAt,
                'end_at' => $startAt->copy()->addMinutes($visitType->duration_minutes),
                'duration_minutes' => $visitType->duration_minutes,
                'price' => $visitType->price,
                'status' => $status,
                'cancel_reason' => $cancelReason,
                'arrived_at' => $status === BookingStatus::DONE ? $startAt->copy() : null,
                'called_in_at' => $status === BookingStatus::DONE ? $startAt->copy()->addMinutes(5) : null,
                'completed_at' => $status === BookingStatus::DONE
                    ? $startAt->copy()->addMinutes($visitType->duration_minutes)
                    : null,
                'cancelled_at' => in_array($status, [BookingStatus::CANCELLED, BookingStatus::NO_SHOW], true)
                    ? $startAt->copy()
                    : null,
                'created_by' => $actor->id,
            ]);
        }
    }

    /**
     * Today, built through the API's own services: one finished, one with the
     * doctor, one waiting, and two who have not arrived yet.
     *
     * @param  list<Patient>  $patients
     */
    private function todaysBookings(Clinic $clinic, array $patients, User $secretary): void
    {
        $bookings = app(BookingService::class);
        $status = app(BookingStatusService::class);

        $slots = $this->todaysSlots($clinic);

        if ($slots === []) {
            $this->command?->warn('The clinic has no open slots today, so no bookings were seeded.');

            return;
        }

        $checkup = $clinic->visitTypes->firstWhere('name', 'كشف');
        $followUp = $clinic->visitTypes->firstWhere('name', 'إعادة');

        $plan = [
            [0, $checkup, 'done'],
            [1, $followUp, 'with_doctor'],
            [2, $checkup, 'arrived'],
            [3, $followUp, 'booked'],
            [4, $checkup, 'booked'],
        ];

        $today = Carbon::now($clinic->timezone)->toDateString();
        $slotIndex = 0;

        foreach ($plan as [$patientIndex, $visitType, $target]) {
            if (! isset($slots[$slotIndex])) {
                break;
            }

            $patient = $patients[$patientIndex];

            $booking = $bookings->create($clinic, BookingData::fromArray([
                'patient_name' => $patient->name,
                'phone' => $patient->phone,
                'visit_type_id' => $visitType->id,
                'date' => $today,
                'start_time' => $slots[$slotIndex],
                'force' => true,
            ]), $secretary);

            match ($target) {
                'done' => $status->complete($status->callIn($status->arrive($booking))),
                'with_doctor' => $status->callIn($status->arrive($booking)),
                'arrived' => $status->arrive($booking),
                default => null,
            };

            // Leave a gap so the next visit type still fits.
            $slotIndex += 3;
        }
    }

    /**
     * @return list<string>
     */
    private function todaysSlots(Clinic $clinic): array
    {
        $schedule = $clinic->scheduleFor(DayOfWeek::fromDate(Carbon::now($clinic->timezone)));

        if ($schedule === null || ! $schedule->is_open) {
            return [];
        }

        $slots = [];

        foreach ($schedule->periods as $period) {
            $cursor = Carbon::parse($period->startTime());
            $end = Carbon::parse($period->endTime());

            while ($cursor->copy()->addMinutes(30)->lessThanOrEqualTo($end)) {
                $slots[] = $cursor->format('H:i');
                $cursor->addMinutes($clinic->slot_step_minutes);
            }
        }

        return $slots;
    }

    /**
     * Two patients postponed by an emergency and not yet rebooked, so the call
     * list and the home-screen banner have something in them.
     *
     * @param  list<Patient>  $patients
     */
    private function awaitingRebooking(Clinic $clinic, Doctor $doctor, array $patients): void
    {
        $now = Carbon::now($clinic->timezone);
        $visitType = $clinic->visitTypes->firstWhere('name', 'كشف');

        foreach ([6, 7] as $offset => $patientIndex) {
            $startAt = $now->copy()->subDay()->setTime(10 + $offset, 0);

            Booking::create([
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'patient_id' => $patients[$patientIndex]->id,
                'visit_type_id' => $visitType->id,
                'visit_date' => $startAt->toDateString(),
                'start_at' => $startAt,
                'end_at' => $startAt->copy()->addMinutes($visitType->duration_minutes),
                'duration_minutes' => $visitType->duration_minutes,
                'price' => $visitType->price,
                'status' => BookingStatus::CANCELLED,
                'cancel_reason' => CancelReason::EMERGENCY,
                'cancelled_at' => $startAt->copy()->subHour(),
            ]);
        }
    }

    private function report(Clinic $clinic, User $owner, User $secretary): void
    {
        $clinic->refresh()->load('bookings');

        $this->command?->newLine();
        $this->command?->info('Demo clinic ready: '.$clinic->name);
        $this->command?->table(
            ['', 'Value'],
            [
                ['Clinic id', $clinic->id],
                ['Patients', $clinic->patients()->count()],
                ['Bookings', $clinic->bookings()->count()],
                ['Today', $clinic->bookings()->whereDate('visit_date', Carbon::now($clinic->timezone)->toDateString())->count()],
                ['Awaiting rebooking', $clinic->bookings()->awaitingRebooking()->count()],
                ['Owner login', $owner->email.' / '.self::PASSWORD],
                ['Secretary login', $secretary->email.' / '.self::PASSWORD],
            ],
        );
    }
}
