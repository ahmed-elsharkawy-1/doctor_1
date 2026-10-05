<?php

namespace Database\Seeders;

use App\Actions\Clinic\ProvisionClinicAction;
use App\DTOs\V1\Booking\BookingData;
use App\Enums\BookingKind;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Enums\DoctorSex;
use App\Enums\ReviewRating;
use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Models\VisitType;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Patients\PatientService;
use App\Services\V1\Queue\BookingStatusService;
use App\Support\PhoneNumber;
use App\Support\TestClinic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The test clinic, "د. سارة أحمد", with half a year of varied data — local
 * and staging only.
 *
 * Its identity and logins come from App\Support\TestClinic, the same on every
 * environment. This seeder adds the data: six months of visits across every
 * status, visit type and booking source, new and returning patients, reviews,
 * a weekly day off, a past and an upcoming holiday, today's queue and the
 * coming days — enough that every screen and every report section has
 * something real to show.
 *
 * Today's bookings go through the real BookingService and
 * BookingStatusService; history is written directly, because the booking
 * window quite correctly refuses appointments in the past.
 *
 * Re-running refreshes the test clinic's data to the current dates: its
 * patients, bookings, reviews and holidays are rebuilt. Nothing outside the
 * test clinic is touched, and it never runs in production.
 */
class DemoClinicSeeder extends Seeder
{
    /** Fixed, so every environment gets the same story. */
    private const SEED = 20261005;

    private const HISTORY_DAYS = 180;

    /**
     * @var list<array{0: string, 1: string}>
     */
    private const PATIENTS = [
        ['منى عبد الله', '01012345678'], ['هدى سمير', '01223334432'], ['ريم خالد', '01098887791'],
        ['ياسمين علي', '01555556634'], ['فاطمة الزهراء', '01011112222'], ['أمل حسن', '01022223333'],
        ['دينا مصطفى', '01233334444'], ['شيماء إبراهيم', '01144445555'], ['مريم طارق', '01055556666'],
        ['نهى فؤاد', '01266667777'], ['رانيا عادل', '01177778888'], ['إيمان صلاح', '01088889999'],
        ['هبة ياسر', '01299990000'], ['سلمى وليد', '01100001111'], ['نادية كمال', '01011223344'],
        ['سماح رأفت', '01022334455'], ['عبير منصور', '01233445566'], ['غادة سليم', '01144556677'],
        ['لمياء فتحي', '01055667788'], ['نرمين جمال', '01266778899'], ['داليا حمدي', '01177889900'],
        ['بسمة عادل', '01088990011'], ['ولاء محمود', '01299001122'], ['إسراء نبيل', '01100112233'],
        ['آية حسام', '01010203040'], ['سارة مجدي', '01020304050'], ['منة الله أشرف', '01230405060'],
        ['حبيبة عمرو', '01140506070'], ['جنى وائل', '01050607080'], ['رحمة سعيد', '01260708090'],
    ];

    /** [name => [price, share of visits]] */
    private const VISIT_TYPES = [
        'كشف' => [400, 45],
        'إعادة' => [200, 30],
        'سونار' => [350, 15],
        'متابعة حمل' => [300, 10],
    ];

    public function run(): void
    {
        // It deletes the test clinic's data before it builds. Nothing it makes
        // belongs on a host with real patients.
        if (app()->isProduction()) {
            throw new RuntimeException('DemoClinicSeeder never runs in production.');
        }

        // Self-sufficient on purpose: this seeder alone leaves a usable system,
        // including the super admin needed to reach the panel.
        $this->call(DatabaseSeeder::class);

        mt_srand(self::SEED);

        $clinic = $this->testClinic();
        TestClinic::apply($clinic);
        $clinic->refresh();

        $doctor = $clinic->doctor;
        $owner = User::where('email', TestClinic::DOCTOR_EMAIL)->firstOrFail();
        $assistant = User::where('email', TestClinic::ASSISTANT_EMAIL)->firstOrFail();

        $this->priceVisitTypes($clinic);
        $this->openTheWeek($clinic);
        $this->addHolidays($clinic);

        $patients = $this->patients($clinic);
        $this->backfillHistory($clinic, $doctor, $patients, $assistant);
        $this->todaysBookings($clinic, $patients, $assistant);
        $this->comingDays($clinic, $doctor, $patients, $assistant);
        $this->awaitingRebooking($clinic, $doctor, $patients);

        $this->report($clinic, $owner, $assistant);
    }

    /**
     * The existing test clinic, emptied of its data; or a new one. Only ever
     * the clinic TestClinic identifies — never a clinic found by name.
     */
    private function testClinic(): Clinic
    {
        $clinic = TestClinic::find();

        if ($clinic !== null) {
            // Cascades through bookings, reviews and messages.
            $clinic->patients()->get()->each->delete();
            $clinic->bookings()->delete();
            $clinic->holidays()->delete();

            if ($clinic->doctor === null) {
                $this->doctor($clinic);
            }

            return $clinic;
        }

        $clinic = Clinic::create([
            'specialty_id' => Specialty::where('slug', 'obstetrics-gynecology')->value('id'),
            'name' => TestClinic::NAME,
            'slug' => TestClinic::SLUG,
            'address' => '١٢ شارع مصدق، الدقي، الجيزة',
            'city' => 'الجيزة',
            'phone' => '+201001234567',
            'timezone' => config('clinic.defaults.timezone'),
            'country_code' => config('clinic.phone.default_country'),
            'booking_window_days' => config('clinic.defaults.booking_window_days'),
            'patient_booking_window_days' => 5,
            'first_visit_only_days' => config('clinic.defaults.first_visit_only_days'),
            'slot_step_minutes' => config('clinic.defaults.slot_step_minutes'),
            'patient_arrival_lead_minutes' => config('clinic.defaults.patient_arrival_lead_minutes'),
            'latitude' => 30.0384,
            'longitude' => 31.2108,
            'self_booking_enabled' => true,
            'is_active' => true,
        ]);

        app(ProvisionClinicAction::class)->execute($clinic);
        $this->doctor($clinic->refresh());

        return $clinic->refresh();
    }

    private function doctor(Clinic $clinic): Doctor
    {
        $doctor = $clinic->doctors()->create([
            'name' => TestClinic::DOCTOR_NAME,
            'sex' => DoctorSex::FEMALE,
            'title' => 'أخصائية النساء والتوليد',
            'bio' => 'عيادة تجريبية للاختبار. أخصائية نساء وتوليد، متابعة الحمل وحالات تأخر الإنجاب.',
            'is_active' => true,
        ]);

        foreach ([
            ['متابعة الحمل', 'متابعة دورية من أول الحمل حتى الولادة بالسونار.', 'baby'],
            ['تأخر الإنجاب', 'تقييم الحالة ووضع خطة علاج مناسبة للزوجين.', 'heart'],
            ['أمراض النساء', 'تشخيص وعلاج الالتهابات واضطرابات الدورة.', 'stethoscope'],
        ] as $index => [$title, $description, $icon]) {
            $doctor->treatmentAreas()->create([
                'title' => $title,
                'description' => $description,
                'icon' => $icon,
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }

        return $doctor;
    }

    /** The four visit types every report breaks down by, with their prices. */
    private function priceVisitTypes(Clinic $clinic): void
    {
        foreach (self::VISIT_TYPES as $name => [$price]) {
            $type = $clinic->visitTypes()->firstOrCreate(
                ['name' => $name],
                ['duration_minutes' => $name === 'كشف' ? 20 : 15, 'price' => $price, 'is_active' => true],
            );

            $type->update(['price' => $price, 'is_active' => true, 'is_self_bookable' => true]);
        }

        $clinic->load('visitTypes');
    }

    /**
     * Saturday runs a split day; Friday is the weekly day off — unless today
     * is Friday, in which case today's queue would have nowhere to go.
     */
    private function openTheWeek(Clinic $clinic): void
    {
        $today = DayOfWeek::fromDate(Carbon::now($clinic->timezone));

        $hours = $this->hours();

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

    /**
     * @return array<int, list<array{0: string, 1: string}>>
     */
    private function hours(): array
    {
        return [
            DayOfWeek::SATURDAY->value => [['13:00', '15:00'], ['17:00', '21:00']],
            DayOfWeek::SUNDAY->value => [['09:00', '14:00']],
            DayOfWeek::MONDAY->value => [['09:00', '14:00']],
            DayOfWeek::TUESDAY->value => [['09:00', '14:00']],
            DayOfWeek::WEDNESDAY->value => [['09:00', '14:00']],
            DayOfWeek::THURSDAY->value => [['10:00', '13:00']],
        ];
    }

    /** One holiday behind us, one ahead — both show on the reports. */
    private function addHolidays(Clinic $clinic): void
    {
        $today = Carbon::now($clinic->timezone);

        $clinic->holidays()->create(['date' => $this->openDay($today->copy()->subDays(10), -1)->toDateString(), 'note' => 'مؤتمر طبي']);
        $clinic->holidays()->create(['date' => $this->openDay($today->copy()->addDays(4), 1)->toDateString(), 'note' => 'سفر']);
    }

    /**
     * Created through PatientService so the ID codes are generated by the real
     * action rather than made up here. Most agreed to WhatsApp; a few did not.
     *
     * @return list<Patient>
     */
    private function patients(Clinic $clinic): array
    {
        $service = app(PatientService::class);
        $patients = [];

        foreach (self::PATIENTS as $index => [$name, $phone]) {
            $patient = $service->findOrCreate($clinic, $name, PhoneNumber::parse($phone, $clinic->country_code));
            $patient->update(['whatsapp_opt_in_at' => $index % 7 === 6 ? null : now()]);
            $patients[] = $patient;
        }

        return $patients;
    }

    /**
     * Six months of visits on every open day. Patients join over time — the
     * pool grows as the months pass — so "new" and "returning" both mean
     * something in every period, and every status, type, source and kind
     * appears.
     *
     * @param  list<Patient>  $patients
     */
    private function backfillHistory(Clinic $clinic, Doctor $doctor, array $patients, User $actor): void
    {
        $today = Carbon::now($clinic->timezone)->startOfDay();
        $holidays = $clinic->holidays()->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $hours = $this->hours();

        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 1; $daysAgo--) {
            $day = $today->copy()->subDays($daysAgo);
            $periods = $hours[DayOfWeek::fromDate($day)->value] ?? null;

            if ($periods === null || in_array($day->toDateString(), $holidays, true)) {
                continue;
            }

            // A quiet day now and then, a busy one sometimes.
            $count = [0, 2, 3, 3, 4, 4, 5, 6][mt_rand(0, 7)];
            // Patients arrive over the months: early on, only the first few exist.
            $pool = max(4, (int) ceil(count($patients) * (1 - $daysAgo / (self::HISTORY_DAYS + 20))));
            [$start] = $periods[0];

            for ($slot = 0; $slot < $count; $slot++) {
                $startAt = Carbon::parse($day->toDateString().' '.$start, $clinic->timezone)->addMinutes($slot * 25);
                $this->pastBooking($clinic, $doctor, $patients[mt_rand(0, $pool - 1)], $startAt, $actor);
            }
        }
    }

    private function pastBooking(Clinic $clinic, Doctor $doctor, Patient $patient, Carbon $startAt, User $actor): void
    {
        $visitType = $this->pickVisitType($clinic);
        $roll = mt_rand(1, 100);

        [$status, $reason] = match (true) {
            $roll <= 72 => [BookingStatus::DONE, null],
            $roll <= 84 => [BookingStatus::NO_SHOW, null],
            $roll <= 94 => [BookingStatus::CANCELLED, CancelReason::PATIENT_CANCELLED],
            $roll <= 97 => [BookingStatus::CANCELLED, CancelReason::EMERGENCY],
            default => [BookingStatus::CANCELLED, CancelReason::INCOMPLETE],
        };

        $done = $status === BookingStatus::DONE;
        $selfBooked = mt_rand(1, 100) <= 25;

        $booking = Booking::create([
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'visit_type_id' => $visitType->id,
            'visit_date' => $startAt->toDateString(),
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addMinutes($visitType->duration_minutes),
            'duration_minutes' => $visitType->duration_minutes,
            'price' => $visitType->price,
            'status' => $status,
            'cancel_reason' => $reason,
            'booking_kind' => mt_rand(1, 100) <= 4 ? BookingKind::EMERGENCY : BookingKind::NORMAL,
            'source' => $selfBooked ? BookingSource::PATIENT_WEB : BookingSource::CLINIC,
            'arrived_at' => $done ? $startAt->copy()->addMinutes(mt_rand(-10, 10)) : null,
            'called_in_at' => $done ? $startAt->copy()->addMinutes(mt_rand(5, 25)) : null,
            'completed_at' => $done ? $startAt->copy()->addMinutes(mt_rand(25, 45)) : null,
            'cancelled_at' => $done ? null : $startAt->copy()->subHours(mt_rand(1, 30)),
            'created_by' => $selfBooked ? null : $actor->id,
        ]);

        // A day cancelled by emergency in the past was rebooked by the
        // clinic — only the recent ones are left on the call list.
        if ($reason === CancelReason::EMERGENCY) {
            $this->rebook($booking, $actor);
        }

        // Some patients say how it went.
        if ($done && mt_rand(1, 100) <= 40) {
            $rating = mt_rand(1, 100);

            BookingReview::create([
                'booking_id' => $booking->id,
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'patient_id' => $patient->id,
                'rating' => $rating <= 70 ? ReviewRating::VERY_GOOD : ($rating <= 92 ? ReviewRating::GOOD : ReviewRating::BAD),
                'comment' => $rating <= 70 ? 'الدكتورة ممتازة والتنظيم ممتاز' : ($rating <= 92 ? 'كويس بس الانتظار كان طويل شوية' : 'استنيت كتير قوي'),
                'submitted_at' => $startAt->copy()->addHours(mt_rand(2, 30)),
            ]);
        }
    }

    private function rebook(Booking $cancelled, User $actor): void
    {
        $startAt = $cancelled->start_at->copy()->addDays(3);

        if ($startAt->isFuture()) {
            return;
        }

        $rebooked = $cancelled->replicate(['cancel_reason', 'cancelled_at', 'rebooked_booking_id', 'tracking_token', 'contacted_at']);
        $rebooked->fill([
            'visit_date' => $startAt->toDateString(),
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addMinutes($cancelled->duration_minutes),
            'status' => BookingStatus::DONE,
            'arrived_at' => $startAt->copy(),
            'called_in_at' => $startAt->copy()->addMinutes(10),
            'completed_at' => $startAt->copy()->addMinutes(30),
            'created_by' => $actor->id,
        ])->save();

        $cancelled->update(['rebooked_booking_id' => $rebooked->id, 'contacted_at' => $startAt->copy()->subDays(2)]);
    }

    private function pickVisitType(Clinic $clinic): VisitType
    {
        $roll = mt_rand(1, 100);
        $sum = 0;

        foreach (self::VISIT_TYPES as $name => [, $share]) {
            $sum += $share;

            if ($roll <= $sum) {
                return $clinic->visitTypes->firstWhere('name', $name);
            }
        }

        return $clinic->visitTypes->firstWhere('name', 'كشف');
    }

    /**
     * Today, built through the API's own services: one finished, one with the
     * doctor, one waiting, and the rest not arrived yet.
     *
     * @param  list<Patient>  $patients
     */
    private function todaysBookings(Clinic $clinic, array $patients, User $assistant): void
    {
        $bookings = app(BookingService::class);
        $status = app(BookingStatusService::class);

        $plan = [
            [0, 'كشف', 'done'],
            [1, 'إعادة', 'with_doctor'],
            [2, 'كشف', 'arrived'],
            [3, 'سونار', 'booked'],
            [4, 'كشف', 'booked'],
            [27, 'كشف', 'booked'],
        ];

        $today = Carbon::now($clinic->timezone)->toDateString();

        foreach ($plan as [$patientIndex, $typeName, $target]) {
            $visitType = $clinic->visitTypes->firstWhere('name', $typeName);
            $startTime = $visitType ? $this->nextAvailableTime($clinic, $visitType, $today) : null;
            $patient = $patients[$patientIndex];

            // Late in the day every slot is in the past and the booking
            // service rightly refuses them — so write the queue directly.
            if ($startTime === null) {
                $this->todaysBookingDirectly($clinic, $patient, $visitType, $target, $assistant);

                continue;
            }

            $booking = $bookings->create($clinic, BookingData::fromArray([
                'patient_name' => $patient->name,
                'phone' => $patient->phone,
                'visit_type_id' => $visitType->id,
                'date' => $today,
                'start_time' => $startTime,
            ]), $assistant);

            match ($target) {
                'done' => $status->complete($status->callIn($status->arrive($booking))),
                'with_doctor' => $status->callIn($status->arrive($booking)),
                'arrived' => $status->arrive($booking),
                default => null,
            };
        }
    }

    private function todaysBookingDirectly(Clinic $clinic, Patient $patient, VisitType $visitType, string $target, User $assistant): void
    {
        $today = Carbon::now($clinic->timezone)->startOfDay();
        $periods = $this->hours()[DayOfWeek::fromDate($today)->value] ?? [['09:00', '14:00']];
        $taken = $clinic->bookings()->whereDate('visit_date', $today->toDateString())->count();
        $startAt = Carbon::parse($today->toDateString().' '.$periods[0][0], $clinic->timezone)->addMinutes($taken * 25);

        $at = fn (int $minutes) => $startAt->copy()->addMinutes($minutes);

        Booking::create([
            'clinic_id' => $clinic->id,
            'doctor_id' => $clinic->doctor->id,
            'patient_id' => $patient->id,
            'visit_type_id' => $visitType->id,
            'visit_date' => $today->toDateString(),
            'start_at' => $startAt,
            'end_at' => $at($visitType->duration_minutes),
            'duration_minutes' => $visitType->duration_minutes,
            'price' => $visitType->price,
            'status' => match ($target) {
                'done' => BookingStatus::DONE,
                'with_doctor' => BookingStatus::WITH_DOCTOR,
                'arrived' => BookingStatus::ARRIVED,
                default => BookingStatus::BOOKED,
            },
            'booking_kind' => BookingKind::NORMAL,
            'source' => BookingSource::CLINIC,
            'arrived_at' => $target === 'booked' ? null : $at(-5),
            'queue_entered_at' => $target === 'booked' ? null : $at(-5),
            'called_in_at' => in_array($target, ['done', 'with_doctor'], true) ? $at(5) : null,
            'completed_at' => $target === 'done' ? $at(25) : null,
            'created_by' => $assistant->id,
        ]);
    }

    /**
     * The next few open days already have bookings — so "the next day's
     * bookings" and the patient booking page both show a real picture.
     *
     * @param  list<Patient>  $patients
     */
    private function comingDays(Clinic $clinic, Doctor $doctor, array $patients, User $assistant): void
    {
        $holidays = $clinic->holidays()->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $hours = $this->hours();
        $day = Carbon::now($clinic->timezone)->startOfDay();

        for ($ahead = 1; $ahead <= 6; $ahead++) {
            $date = $day->copy()->addDays($ahead);
            $periods = $hours[DayOfWeek::fromDate($date)->value] ?? null;

            if ($periods === null || in_array($date->toDateString(), $holidays, true)) {
                continue;
            }

            [$start] = $periods[0];
            $count = mt_rand(2, 4);

            for ($slot = 0; $slot < $count; $slot++) {
                $visitType = $this->pickVisitType($clinic);
                $startAt = Carbon::parse($date->toDateString().' '.$start, $clinic->timezone)->addMinutes($slot * 25);
                $selfBooked = $slot === 0;

                Booking::create([
                    'clinic_id' => $clinic->id,
                    'doctor_id' => $doctor->id,
                    'patient_id' => $patients[mt_rand(5, count($patients) - 1)]->id,
                    'visit_type_id' => $visitType->id,
                    'visit_date' => $date->toDateString(),
                    'start_at' => $startAt,
                    'end_at' => $startAt->copy()->addMinutes($visitType->duration_minutes),
                    'duration_minutes' => $visitType->duration_minutes,
                    'price' => $visitType->price,
                    'status' => BookingStatus::BOOKED,
                    'booking_kind' => BookingKind::NORMAL,
                    'source' => $selfBooked ? BookingSource::PATIENT_WEB : BookingSource::CLINIC,
                    'created_by' => $selfBooked ? null : $assistant->id,
                ]);
            }
        }
    }

    private function nextAvailableTime(Clinic $clinic, VisitType $visitType, string $date): ?string
    {
        $availability = app(SlotAvailabilityService::class)
            ->for($clinic, Carbon::parse($date, $clinic->timezone), $visitType);

        foreach ($availability->slots as $slot) {
            if ($slot->isAvailable) {
                return $slot->startAt->format('H:i');
            }
        }

        return null;
    }

    /**
     * Two patients postponed by an emergency and not yet rebooked, so the call
     * list and the home-screen banner have something in them.
     *
     * @param  list<Patient>  $patients
     */
    private function awaitingRebooking(Clinic $clinic, Doctor $doctor, array $patients): void
    {
        $visitType = $clinic->visitTypes->firstWhere('name', 'كشف');
        $yesterday = $this->openDay(Carbon::now($clinic->timezone)->subDay(), -1);

        foreach ([6, 7] as $offset => $patientIndex) {
            $startAt = $yesterday->copy()->setTime(10 + $offset, 30);

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

    /** The nearest day the clinic is open, stepping forward (1) or back (-1). */
    private function openDay(Carbon $from, int $step): Carbon
    {
        $hours = $this->hours();
        $day = $from->copy()->startOfDay();

        while (! isset($hours[DayOfWeek::fromDate($day)->value])) {
            $day->addDays($step);
        }

        return $day;
    }

    private function report(Clinic $clinic, User $owner, User $assistant): void
    {
        $this->command?->newLine();
        $this->command?->info('Test clinic ready: '.$clinic->name.' — /'.$clinic->slug);
        $this->command?->table(
            ['', 'Value'],
            [
                ['Clinic id', $clinic->id],
                ['Patients', $clinic->patients()->count()],
                ['Bookings', $clinic->bookings()->count()],
                ['Reviews', BookingReview::where('clinic_id', $clinic->id)->count()],
                ['Today', $clinic->bookings()->whereDate('visit_date', Carbon::now($clinic->timezone)->toDateString())->count()],
                ['Awaiting rebooking', $clinic->bookings()->awaitingRebooking()->count()],
                ['Doctor login', $owner->email.' / '.TestClinic::DOCTOR_PASSWORD],
                ['Assistant login', $assistant->email.' / '.TestClinic::ASSISTANT_PASSWORD],
            ],
        );
    }
}
