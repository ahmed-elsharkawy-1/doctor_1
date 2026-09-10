<?php

namespace Tests\Feature\Web;

use App\Livewire\App\PatientProfile;
use App\Livewire\App\Patients;
use App\Models\Booking;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ClinicAppPatientsTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function patient(array $attributes = []): Patient
    {
        return Patient::factory()->create($attributes + ['clinic_id' => $this->clinic->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | The shell
    |--------------------------------------------------------------------------
    */

    public function test_every_screen_is_reachable_from_the_navigation(): void
    {
        $page = $this->actingAs($this->owner)->get(route('app.queue'))->assertOk();

        $page->assertSee(route('app.queue'), escape: false)
            ->assertSee(route('app.bookings.new'), escape: false)
            ->assertSee(route('app.patients'), escape: false)
            ->assertSee($this->clinic->name)
            ->assertSee($this->owner->name);
    }

    public function test_the_navigation_marks_where_you_are(): void
    {
        $this->actingAs($this->owner)
            ->get(route('app.patients'))
            ->assertOk()
            ->assertSee('aria-current="page"', escape: false);
    }

    /*
    |--------------------------------------------------------------------------
    | Searching
    |--------------------------------------------------------------------------
    */

    public function test_the_list_needs_a_signed_in_account(): void
    {
        $this->get(route('app.patients'))->assertRedirect(route('app.login'));
    }

    public function test_it_finds_a_patient_by_name(): void
    {
        $this->patient(['name' => 'نادية فؤاد']);
        $this->patient(['name' => 'سمية رأفت']);

        Livewire::actingAs($this->owner)
            ->test(Patients::class)
            ->set('term', 'نادية')
            ->assertSee('نادية فؤاد')
            ->assertDontSee('سمية رأفت');
    }

    public function test_it_finds_a_patient_by_code(): void
    {
        $patient = $this->patient(['name' => 'هالة منصور']);

        Livewire::actingAs($this->owner)
            ->test(Patients::class)
            ->set('term', $patient->code)
            ->assertSee('هالة منصور');
    }

    public function test_it_finds_a_patient_by_the_tail_of_a_phone(): void
    {
        $this->patient(['name' => 'إيناس رشدي', 'phone' => '+201009998877']);

        // She types the last four digits she can see on screen.
        Livewire::actingAs($this->owner)
            ->test(Patients::class)
            ->set('term', '8877')
            ->assertSee('إيناس رشدي');
    }

    public function test_another_clinics_patients_are_never_listed(): void
    {
        $other = $this->otherClinic();
        $theirs = Patient::factory()->create(['clinic_id' => $other->id, 'name' => 'مريضة تانية']);

        Livewire::actingAs($this->owner)
            ->test(Patients::class)
            ->assertDontSee($theirs->name);
    }

    public function test_searching_returns_to_the_first_page(): void
    {
        Patient::factory()->count(20)->create(['clinic_id' => $this->clinic->id]);

        Livewire::actingAs($this->owner)
            ->test(Patients::class)
            ->call('setPage', 2)
            ->set('term', 'a')
            ->assertSet('paginators.page', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | The profile
    |--------------------------------------------------------------------------
    */

    public function test_it_shows_a_patients_visit_history(): void
    {
        $patient = $this->patient(['name' => 'ريهام سعيد']);

        Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-01 09:00', $this->clinic->timezone))
            ->done()->create(['patient_id' => $patient->id]);

        Livewire::actingAs($this->owner)
            ->test(PatientProfile::class, ['patient' => $patient->id])
            ->assertOk()
            ->assertSee('ريهام سعيد')
            ->assertSee(__('app.patients.history'))
            ->assertSee('2026-09-01');
    }

    public function test_the_summary_counts_visits_no_shows_and_cancellations(): void
    {
        $patient = $this->patient();
        $at = fn (string $time) => Carbon::parse('2026-09-0'.$time, $this->clinic->timezone);

        Booking::factory()->forClinic($this->clinic)->at($at('1 09:00'))->done()->create(['patient_id' => $patient->id]);
        Booking::factory()->forClinic($this->clinic)->at($at('2 09:00'))->noShow()->create(['patient_id' => $patient->id]);
        Booking::factory()->forClinic($this->clinic)->at($at('3 09:00'))->cancelled()->create(['patient_id' => $patient->id]);

        $html = Livewire::actingAs($this->owner)
            ->test(PatientProfile::class, ['patient' => $patient->id])
            ->assertOk()
            ->html();

        // A pattern of no-shows is exactly what she needs to see.
        $this->assertStringContainsString(__('booking.status.no_show'), $html);
        $this->assertStringContainsString(__('booking.status.cancelled'), $html);
    }

    public function test_the_history_shows_the_snapshotted_price_not_the_current_one(): void
    {
        $patient = $this->patient();
        $visitType = $this->clinic->visitTypes()->active()->first();

        $booking = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-01 09:00', $this->clinic->timezone))
            ->done()->create(['patient_id' => $patient->id, 'price' => 300]);

        // The visit type is repriced afterwards; history must not follow.
        $visitType->update(['price' => 900]);

        Livewire::actingAs($this->owner)
            ->test(PatientProfile::class, ['patient' => $patient->id])
            ->assertSee('300 '.__('messages.currency'))
            ->assertDontSee('900 '.__('messages.currency'));

        $this->assertSame('300.00', $booking->fresh()->price);
    }

    public function test_another_clinics_patient_is_not_found(): void
    {
        $other = $this->otherClinic();
        $theirs = Patient::factory()->create(['clinic_id' => $other->id]);

        Livewire::actingAs($this->owner)
            ->test(PatientProfile::class, ['patient' => $theirs->id])
            ->assertNotFound();
    }

    public function test_a_patient_with_no_visits_still_opens(): void
    {
        $patient = $this->patient(['name' => 'مريضة جديدة']);

        Livewire::actingAs($this->owner)
            ->test(PatientProfile::class, ['patient' => $patient->id])
            ->assertOk()
            ->assertSee(__('app.patients.no_visits'));
    }
}
