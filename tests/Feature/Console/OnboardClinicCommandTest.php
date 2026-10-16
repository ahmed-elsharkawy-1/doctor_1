<?php

namespace Tests\Feature\Console;

use App\Enums\DayOfWeek;
use App\Enums\DoctorSex;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\ClinicSlugRedirect;
use App\Models\User;
use App\Services\V1\Clinic\ClinicOnboardingService;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Adding a real clinic from one description, the same way on staging and on
 * production.
 */
class OnboardClinicCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SpecialtySeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function spec(array $overrides = []): array
    {
        return array_replace_recursive([
            'clinic' => [
                'name' => 'عيادة د. محمد',
                'slug' => 'dr-mohamed',
                'specialty' => 'obstetrics-gynecology',
                'phone' => '01097896263',
                'address' => 'البندر',
                'city' => 'المحلة الكبرى',
                'self_booking_enabled' => true,
                'whatsapp_enabled' => true,
                'reports_enabled' => true,
            ],
            'doctor' => [
                'name' => 'د. محمد الهواري',
                'title' => 'استشاري أمراض النساء والتوليد',
                'sex' => 'male',
            ],
            'accounts' => [
                ['name' => 'عيادة د. محمد', 'email' => 'Doctor@Example.com', 'main' => true],
                ['name' => 'سكرتيرة 1', 'email' => 'sec1@example.com'],
                ['name' => 'سكرتيرة 2', 'email' => 'sec2@example.com'],
            ],
            'schedule' => [
                'saturday' => [['17:00', '23:00']],
                'thursday' => [['13:00', '16:00']],
            ],
        ], $overrides);
    }

    private function file(array $spec): string
    {
        $path = tempnam(sys_get_temp_dir(), 'onboard');
        file_put_contents($path, json_encode($spec, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /*
    |--------------------------------------------------------------------------
    | What it creates
    |--------------------------------------------------------------------------
    */

    public function test_it_creates_the_clinic_as_described(): void
    {
        app(ClinicOnboardingService::class)->onboard($this->spec());

        $clinic = Clinic::where('slug', 'dr-mohamed')->firstOrFail();

        $this->assertSame('عيادة د. محمد', $clinic->name);
        $this->assertSame('obstetrics-gynecology', $clinic->specialty->slug);
        $this->assertSame('+201097896263', $clinic->phone);
        $this->assertSame('المحلة الكبرى', $clinic->city);
        $this->assertTrue($clinic->self_booking_enabled);
        $this->assertTrue($clinic->whatsapp_enabled);
        $this->assertTrue($clinic->reports_enabled);
        $this->assertFalse($clinic->is_test);
        $this->assertTrue($clinic->is_active);
        $this->assertSame(config('clinic.defaults.timezone'), $clinic->timezone);
    }

    public function test_switches_left_out_are_off(): void
    {
        $spec = $this->spec();
        unset($spec['clinic']['self_booking_enabled'], $spec['clinic']['whatsapp_enabled'], $spec['clinic']['reports_enabled']);

        $clinic = app(ClinicOnboardingService::class)->onboard($spec)['clinic'];

        $this->assertFalse($clinic->self_booking_enabled);
        $this->assertFalse($clinic->whatsapp_enabled);
        $this->assertFalse($clinic->reports_enabled);
    }

    public function test_it_is_provisioned_like_a_clinic_made_in_the_panel(): void
    {
        $clinic = app(ClinicOnboardingService::class)->onboard($this->spec())['clinic'];

        $this->assertGreaterThan(0, $clinic->visitTypes()->count());
        $this->assertSame(0.0, (float) $clinic->visitTypes()->sum('price'));
        $this->assertCount(7, $clinic->schedules);
    }

    public function test_the_starting_week_is_opened_and_the_rest_stays_closed(): void
    {
        $clinic = app(ClinicOnboardingService::class)->onboard($this->spec())['clinic'];

        $saturday = $clinic->scheduleFor(DayOfWeek::SATURDAY);
        $this->assertTrue($saturday->is_open);
        $this->assertSame([['17:00', '23:00']], $saturday->periods->map(fn ($p) => [substr($p->start_time, 0, 5), substr($p->end_time, 0, 5)])->all());

        $this->assertTrue($clinic->scheduleFor(DayOfWeek::THURSDAY)->is_open);
        $this->assertFalse($clinic->scheduleFor(DayOfWeek::FRIDAY)->is_open);
        $this->assertSame(0, $clinic->scheduleFor(DayOfWeek::FRIDAY)->periods()->count());
    }

    public function test_it_creates_the_doctor(): void
    {
        $clinic = app(ClinicOnboardingService::class)->onboard($this->spec())['clinic'];

        $doctor = $clinic->doctor;

        $this->assertSame('د. محمد الهواري', $doctor->name);
        $this->assertSame('استشاري أمراض النساء والتوليد', $doctor->title);
        $this->assertSame(DoctorSex::MALE, $doctor->sex);
        // Without its own number the doctor carries the clinic's.
        $this->assertSame('+201097896263', $doctor->phone);
    }

    public function test_the_main_account_is_the_doctors_own_login(): void
    {
        $result = app(ClinicOnboardingService::class)->onboard($this->spec());

        $main = User::where('email', 'doctor@example.com')->firstOrFail();

        $this->assertSame(UserRole::CLINIC, $main->role);
        $this->assertSame($result['clinic']->doctor->id, $main->doctor_id);
        $this->assertSame('+201097896263', $main->phone);
        $this->assertTrue($main->can_access_reports);
        $this->assertTrue($main->is_active);
        $this->assertTrue($main->clinics->contains($result['clinic']));
    }

    public function test_secretaries_belong_to_the_clinic_without_reports(): void
    {
        $clinic = app(ClinicOnboardingService::class)->onboard($this->spec())['clinic'];

        foreach (['sec1@example.com', 'sec2@example.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->assertSame(UserRole::CLINIC, $user->role);
            $this->assertNull($user->doctor_id);
            $this->assertNull($user->phone);
            $this->assertFalse($user->can_access_reports);
            $this->assertTrue($user->clinics->contains($clinic));
        }
    }

    public function test_each_login_gets_its_own_password_and_it_works(): void
    {
        $accounts = app(ClinicOnboardingService::class)->onboard($this->spec())['accounts'];

        $this->assertCount(3, array_unique(array_column($accounts, 'password')));

        foreach ($accounts as $account) {
            $this->assertGreaterThanOrEqual(12, strlen($account['password']));
            $this->assertTrue(Hash::check($account['password'], User::where('email', $account['email'])->value('password')));
        }
    }

    public function test_it_fills_the_doctors_overview(): void
    {
        $clinic = app(ClinicOnboardingService::class)->onboard($this->spec(['doctor' => [
            'bio' => 'استشاري أمراض النساء والتوليد.',
            'treatment_areas' => [
                ['title' => 'متابعة الحمل والولادة', 'icon' => 'baby', 'description' => 'متابعة دورية.'],
                ['title' => 'تأخر الإنجاب', 'icon' => 'heart'],
            ],
        ]]))['clinic'];

        $doctor = $clinic->doctor;

        $this->assertSame('استشاري أمراض النساء والتوليد.', $doctor->bio);
        $this->assertSame(
            [['متابعة الحمل والولادة', 'baby', 'متابعة دورية.', 1], ['تأخر الإنجاب', 'heart', null, 2]],
            $doctor->treatmentAreas()->orderBy('sort_order')->get()
                ->map(fn ($area) => [$area->title, $area->icon, $area->description, $area->sort_order])->all(),
        );

        $this->get('/dr-mohamed')->assertOk()->assertSee('متابعة الحمل والولادة')->assertSee('استشاري أمراض النساء والتوليد.');
    }

    public function test_it_saves_the_doctors_photo_where_the_panel_would(): void
    {
        Storage::fake(config('clinic.public.disk'));

        $jpeg = UploadedFile::fake()->image('portrait.jpg', 300, 300)->getContent();

        $clinic = app(ClinicOnboardingService::class)->onboard($this->spec(['doctor' => [
            'photo' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
        ]]))['clinic'];

        $doctor = $clinic->doctor;

        $this->assertSame("doctors/{$doctor->id}/portrait.jpg", $doctor->photo_path);
        Storage::disk(config('clinic.public.disk'))->assertExists($doctor->photo_path);
    }

    public function test_passwords_given_in_the_file_are_used(): void
    {
        $spec = $this->spec();
        $spec['accounts'][0]['password'] = 'mhawary000';
        $spec['accounts'][1]['password'] = 'mhawary001';

        $accounts = app(ClinicOnboardingService::class)->onboard($spec)['accounts'];

        $this->assertSame(['mhawary000', 'mhawary001'], [$accounts[0]['password'], $accounts[1]['password']]);
        $this->assertTrue(Hash::check('mhawary000', User::where('email', 'doctor@example.com')->value('password')));
        // The one left out is still generated.
        $this->assertSame(12, strlen($accounts[2]['password']));
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses — and that a refusal writes nothing
    |--------------------------------------------------------------------------
    */

    private function assertRefused(array $spec, string $field): void
    {
        try {
            app(ClinicOnboardingService::class)->onboard($spec);
            $this->fail("Expected the description to be refused on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_an_existing_clinic_is_never_overwritten(): void
    {
        app(ClinicOnboardingService::class)->onboard($this->spec());

        $this->assertRefused($this->spec(['accounts' => [['email' => 'a@example.com', 'name' => 'a', 'main' => true]]]), 'clinic.slug');
        $this->assertSame(1, Clinic::where('slug', 'dr-mohamed')->count());
    }

    public function test_an_address_another_clinic_gave_up_is_refused(): void
    {
        $other = Clinic::factory()->create(['slug' => 'dr-other']);
        ClinicSlugRedirect::create(['clinic_id' => $other->id, 'slug' => 'dr-mohamed']);

        $this->assertRefused($this->spec(), 'clinic.slug');
    }

    public function test_a_reserved_or_malformed_slug_is_refused(): void
    {
        $this->assertRefused($this->spec(['clinic' => ['slug' => 'admin']]), 'clinic.slug');
        $this->assertRefused($this->spec(['clinic' => ['slug' => 'Dr Mohamed']]), 'clinic.slug');
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        User::factory()->create(['email' => 'sec1@example.com']);

        $this->assertRefused($this->spec(), 'accounts.1.email');
        $this->assertDatabaseMissing('clinics', ['slug' => 'dr-mohamed']);
    }

    public function test_a_phone_another_login_uses_is_refused(): void
    {
        User::factory()->create(['phone' => '+201097896263']);

        $this->assertRefused($this->spec(), 'clinic.phone');
    }

    public function test_an_unknown_specialty_or_bad_phone_is_refused(): void
    {
        $this->assertRefused($this->spec(['clinic' => ['specialty' => 'astrology']]), 'clinic.specialty');
        $this->assertRefused($this->spec(['clinic' => ['phone' => '123']]), 'clinic.phone');
    }

    public function test_there_must_be_exactly_one_main_account(): void
    {
        $spec = $this->spec();
        $spec['accounts'][0]['main'] = false;
        $this->assertRefused($spec, 'accounts');

        $spec['accounts'][0]['main'] = true;
        $spec['accounts'][1]['main'] = true;
        $this->assertRefused($spec, 'accounts');
    }

    public function test_an_unknown_day_is_refused(): void
    {
        $this->assertRefused($this->spec(['schedule' => ['friyay' => [['10:00', '12:00']]]]), 'schedule');
    }

    public function test_a_photo_that_is_not_an_image_is_refused(): void
    {
        $this->assertRefused($this->spec(['doctor' => ['photo' => base64_encode('not an image')]]), 'doctor.photo');
        $this->assertRefused($this->spec(['doctor' => ['photo' => '***']]), 'doctor.photo');
    }

    public function test_a_photo_over_the_panels_limit_is_refused(): void
    {
        config(['clinic.public.photo_max_kb' => 1]);

        $big = UploadedFile::fake()->image('portrait.jpg', 600, 600)->getContent();

        $this->assertRefused($this->spec(['doctor' => ['photo' => base64_encode($big)]]), 'doctor.photo');
    }

    public function test_a_short_password_or_unknown_icon_is_refused(): void
    {
        $spec = $this->spec();
        $spec['accounts'][0]['password'] = 'short';
        $this->assertRefused($spec, 'accounts.0.password');

        $this->assertRefused($this->spec(['doctor' => ['treatment_areas' => [['title' => 'x', 'icon' => 'rocket']]]]), 'doctor.treatment_areas.0.icon');
    }

    /** The app's own schedule rules apply, and a late failure rolls everything back. */
    public function test_impossible_hours_write_nothing_at_all(): void
    {
        try {
            app(ClinicOnboardingService::class)->onboard($this->spec(['schedule' => ['sunday' => [['18:00', '17:00']]]]));
            $this->fail('Expected impossible hours to be refused.');
        } catch (ApiException) {
        }

        $this->assertDatabaseMissing('clinics', ['slug' => 'dr-mohamed']);
        $this->assertDatabaseMissing('users', ['email' => 'doctor@example.com']);
        $this->assertDatabaseCount('doctors', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | The command
    |--------------------------------------------------------------------------
    */

    public function test_a_dry_run_shows_the_plan_and_writes_nothing(): void
    {
        $this->artisan('clinic:onboard', ['file' => $this->file($this->spec()), '--dry-run' => true])
            ->expectsOutputToContain('dr-mohamed')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_it_asks_before_writing(): void
    {
        $this->artisan('clinic:onboard', ['file' => $this->file($this->spec())])
            ->expectsConfirmation('Create this clinic on '.config('app.env').'?', 'no')
            ->assertFailed();

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_it_creates_the_clinic_and_shows_the_passwords_once(): void
    {
        $this->artisan('clinic:onboard', ['file' => $this->file($this->spec()), '--force' => true])
            ->expectsOutputToContain('Passwords are shown once')
            ->expectsOutputToContain('doctor@example.com')
            ->assertSuccessful();

        $this->assertDatabaseHas('clinics', ['slug' => 'dr-mohamed']);
    }

    public function test_a_refused_description_explains_why(): void
    {
        $this->artisan('clinic:onboard', ['file' => $this->file($this->spec(['clinic' => ['slug' => 'admin']])), '--force' => true])
            ->expectsOutputToContain('nothing was written')
            ->assertFailed();

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_a_missing_file_or_bad_json_is_reported(): void
    {
        $this->artisan('clinic:onboard', ['file' => '/no/such/file.json'])->assertFailed();

        $bad = tempnam(sys_get_temp_dir(), 'onboard');
        file_put_contents($bad, '{not json');
        $this->artisan('clinic:onboard', ['file' => $bad])->assertFailed();
    }
}
