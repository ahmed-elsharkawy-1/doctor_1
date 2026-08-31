<?php

namespace App\Console\Commands;

use App\Actions\Clinic\ProvisionClinicAction;
use App\DTOs\V1\Booking\BookingData;
use App\Enums\DayOfWeek;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Models\VisitType;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Patients\PatientService;
use App\Support\PhoneNumber;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SeedPilotDoctorCommand extends Command
{
    protected $signature = 'clinic:seed-pilot-doctor
                            {email=drseham@gmail.com : Mobile login email}
                            {--password=password : Temporary mobile login password}
                            {--name=سهام عبدالعزيز : Doctor/account display name}
                            {--clinic=عيادة د. سهام عبدالعزيز : Clinic display name}
                            {--phone=+201001111222 : Clinic and doctor phone}
                            {--account-phone=+201001111223 : Unique mobile number for the login account}
                            {--address=القاهرة، مصر : Clinic address}
                            {--specialty=obstetrics-gynecology : Specialty slug}
                            {--bookings=20 : Number of future test bookings}
                            {--tomorrow=10 : Number of bookings to place tomorrow}';

    protected $description = 'Seed a real pilot doctor clinic with future dummy bookings';

    /**
     * @var list<array{name: string, phone: string, age: int}>
     */
    private const PATIENTS = [
        ['name' => 'مريم أحمد', 'phone' => '01077000001', 'age' => 29],
        ['name' => 'نورهان محمود', 'phone' => '01077000002', 'age' => 34],
        ['name' => 'سارة خالد', 'phone' => '01077000003', 'age' => 25],
        ['name' => 'أسماء علي', 'phone' => '01077000004', 'age' => 31],
        ['name' => 'هدى سمير', 'phone' => '01077000005', 'age' => 38],
        ['name' => 'ريم مصطفى', 'phone' => '01077000006', 'age' => 27],
        ['name' => 'ياسمين حسن', 'phone' => '01077000007', 'age' => 33],
        ['name' => 'دينا صلاح', 'phone' => '01077000008', 'age' => 41],
        ['name' => 'منة وليد', 'phone' => '01077000009', 'age' => 24],
        ['name' => 'آية محمد', 'phone' => '01077000010', 'age' => 36],
        ['name' => 'بسمة عادل', 'phone' => '01077000011', 'age' => 30],
        ['name' => 'إيمان طارق', 'phone' => '01077000012', 'age' => 39],
        ['name' => 'شيماء فؤاد', 'phone' => '01077000013', 'age' => 28],
        ['name' => 'أمل إبراهيم', 'phone' => '01077000014', 'age' => 35],
        ['name' => 'رانيا جمال', 'phone' => '01077000015', 'age' => 32],
        ['name' => 'نهى ياسر', 'phone' => '01077000016', 'age' => 37],
        ['name' => 'سلمى عاطف', 'phone' => '01077000017', 'age' => 26],
        ['name' => 'فاطمة سعيد', 'phone' => '01077000018', 'age' => 40],
        ['name' => 'هبة عبد الله', 'phone' => '01077000019', 'age' => 29],
        ['name' => 'منى فتحي', 'phone' => '01077000020', 'age' => 34],
    ];

    public function handle(
        ProvisionClinicAction $provision,
        PatientService $patients,
        BookingService $bookings,
        SlotAvailabilityService $slots,
    ): int {
        app(SpecialtySeeder::class)->run();

        $email = strtolower((string) $this->argument('email'));
        $bookingCount = max(1, min((int) $this->option('bookings'), count(self::PATIENTS)));
        $tomorrowCount = max(0, min((int) $this->option('tomorrow'), $bookingCount));

        $specialty = Specialty::where('slug', (string) $this->option('specialty'))->first();

        if ($specialty === null) {
            $this->error('Specialty not found: '.$this->option('specialty'));

            return self::FAILURE;
        }

        $clinic = $this->clinic($specialty);
        $provision->execute($clinic);
        $clinic->refresh()->load(['schedules.periods', 'visitTypes']);

        $doctor = $this->doctor($clinic);
        $user = $this->account($clinic, $doctor, $email);

        $this->priceVisitTypes($clinic);
        $this->openTestDays($clinic);
        $clinic->refresh()->load(['schedules.periods', 'visitTypes']);

        $createdBookings = $this->bookFutureAppointments(
            $clinic,
            $user,
            $patients,
            $bookings,
            $slots,
            $bookingCount,
            $tomorrowCount,
        );

        $clinic->refresh();

        $this->newLine();
        $this->info('Pilot doctor clinic ready.');
        $this->table(
            ['Field', 'Value'],
            [
                ['Clinic id', $clinic->id],
                ['Clinic', $clinic->name],
                ['Doctor', $doctor->name],
                ['Login', $user->email.' / '.$this->option('password')],
                ['Patients', $clinic->patients()->count()],
                ['Future booked appointments', $clinic->bookings()->where('status', 'booked')->whereDate('visit_date', '>=', $this->today($clinic)->toDateString())->count()],
                ['Created this run', $createdBookings],
            ],
        );

        return self::SUCCESS;
    }

    private function clinic(Specialty $specialty): Clinic
    {
        return Clinic::updateOrCreate(
            ['name' => (string) $this->option('clinic')],
            [
                'specialty_id' => $specialty->id,
                'address' => (string) $this->option('address'),
                'phone' => (string) $this->option('phone'),
                'timezone' => config('clinic.defaults.timezone'),
                'country_code' => config('clinic.phone.default_country'),
                'booking_window_days' => max(7, (int) config('clinic.defaults.booking_window_days')),
                'first_visit_only_days' => config('clinic.defaults.first_visit_only_days'),
                'slot_step_minutes' => config('clinic.defaults.slot_step_minutes'),
                'patient_arrival_lead_minutes' => config('clinic.defaults.patient_arrival_lead_minutes'),
                'is_active' => true,
            ],
        );
    }

    private function doctor(Clinic $clinic): Doctor
    {
        $clinic->doctors()
            ->where('name', '!=', (string) $this->option('name'))
            ->update(['is_active' => false]);

        return $clinic->doctors()->updateOrCreate(
            ['name' => (string) $this->option('name')],
            [
                'phone' => (string) $this->option('phone'),
                'is_active' => true,
            ],
        );
    }

    private function account(Clinic $clinic, Doctor $doctor, string $email): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) $this->option('name'),
                'password' => (string) $this->option('password'),
                'role' => UserRole::CLINIC,
                'doctor_id' => $doctor->id,
                'phone' => (string) $this->option('account-phone'),
                'locale' => 'ar',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $user->clinics()->sync([$clinic->id]);

        return $user;
    }

    private function priceVisitTypes(Clinic $clinic): void
    {
        $prices = [
            'كشف' => 750,
            'إعادة' => 250,
            'سونار' => 500,
            'متابعة حمل' => 400,
        ];

        foreach ($clinic->visitTypes as $visitType) {
            $visitType->update(['price' => $prices[$visitType->name] ?? 300]);
        }
    }

    private function openTestDays(Clinic $clinic): void
    {
        foreach (DayOfWeek::week() as $day) {
            $schedule = $clinic->scheduleFor($day);

            if ($schedule === null) {
                continue;
            }

            $schedule->periods()->delete();
            $schedule->update(['is_open' => true]);
            $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '14:00']);
        }
    }

    private function bookFutureAppointments(
        Clinic $clinic,
        User $actor,
        PatientService $patients,
        BookingService $bookings,
        SlotAvailabilityService $slots,
        int $bookingCount,
        int $tomorrowCount,
    ): int {
        $visitTypes = $clinic->visitTypes->values();

        if ($visitTypes->isEmpty()) {
            $this->warn('No visit types were available, so no bookings were created.');

            return 0;
        }

        $created = 0;
        $dates = $this->bookingDates($clinic, $bookingCount, $tomorrowCount);

        foreach (array_slice(self::PATIENTS, 0, $bookingCount) as $index => $definition) {
            $patient = $patients->findOrCreate(
                $clinic,
                $definition['name'],
                PhoneNumber::parse($definition['phone'], $clinic->country_code),
                $definition['age'],
                true,
            );

            $date = $dates[$index];

            if ($this->patientAlreadyBookedOn($patient, $date)) {
                continue;
            }

            $visitType = $visitTypes[$index % $visitTypes->count()];
            $startTime = $this->nextAvailableTime($slots, $clinic, $visitType, $date);

            if ($startTime === null) {
                $this->warn("No available {$visitType->name} slot on {$date}; skipped {$patient->name}.");

                continue;
            }

            $bookings->create($clinic, BookingData::fromArray([
                'patient_id' => $patient->id,
                'visit_type_id' => $visitType->id,
                'date' => $date,
                'start_time' => $startTime,
                'booking_kind' => 'normal',
                'whatsapp_opt_in' => true,
                'notes' => 'بيانات اختبار للطبيبة',
            ]), $actor);

            $created++;
        }

        return $created;
    }

    /**
     * @return list<string>
     */
    private function bookingDates(Clinic $clinic, int $bookingCount, int $tomorrowCount): array
    {
        $tomorrow = $this->today($clinic)->addDay();
        $dates = array_fill(0, $tomorrowCount, $tomorrow->toDateString());

        for ($index = $tomorrowCount; $index < $bookingCount; $index++) {
            $offset = 2 + (($index - $tomorrowCount) % 3);
            $dates[] = $this->today($clinic)->addDays($offset)->toDateString();
        }

        return $dates;
    }

    private function today(Clinic $clinic): Carbon
    {
        return Carbon::now($clinic->timezone)->startOfDay();
    }

    private function patientAlreadyBookedOn(Patient $patient, string $date): bool
    {
        return Booking::query()
            ->where('patient_id', $patient->id)
            ->whereDate('visit_date', $date)
            ->exists();
    }

    private function nextAvailableTime(
        SlotAvailabilityService $slots,
        Clinic $clinic,
        VisitType $visitType,
        string $date,
    ): ?string {
        $availability = $slots->for($clinic, Carbon::parse($date, $clinic->timezone), $visitType);

        foreach ($availability->slots as $slot) {
            if ($slot->isAvailable) {
                return $slot->startAt->format('H:i');
            }
        }

        return null;
    }
}
