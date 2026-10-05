<?php

namespace Tests\Feature\Console;

use App\Models\Booking;
use App\Models\Clinic;
use App\Models\User;
use App\Support\TestClinic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * One test clinic, the same everywhere: local, staging and production.
 *
 * `clinic:test-clinic` turns whichever clinic already plays that part into
 * "د. سارة أحمد" with the shared logins. It renames and sets flags only — it
 * never generates or deletes data, so it is safe on production.
 */
class TestClinicCommandTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['name' => 'عيادة د. سارة النجار', 'slug' => 'aayad-d-sar-alngar']);
        $this->owner->update(['email' => 'doctor@doctor1.test']);
        $this->secretary->update(['email' => 'nour@doctor1.test']);
    }

    public function test_the_existing_mock_clinic_becomes_the_test_clinic(): void
    {
        $booking = Booking::factory()->forClinic($this->clinic)->create();

        $this->artisan('clinic:test-clinic', ['--force' => true])->assertSuccessful();

        $clinic = $this->clinic->fresh();
        $this->assertSame(TestClinic::NAME, $clinic->name);
        $this->assertSame(TestClinic::SLUG, $clinic->slug);
        $this->assertTrue($clinic->is_test);
        $this->assertTrue($clinic->reports_enabled);
        $this->assertSame(TestClinic::DOCTOR_NAME, $clinic->doctor->name);

        // Renamed, not rebuilt: its history is still there.
        $this->assertModelExists($booking);
        $this->assertSame(1, Clinic::count());
    }

    public function test_the_shared_logins_are_set(): void
    {
        $this->artisan('clinic:test-clinic', ['--force' => true])->assertSuccessful();

        $doctor = $this->owner->fresh();
        $this->assertSame(TestClinic::DOCTOR_EMAIL, $doctor->email);
        $this->assertTrue(Hash::check(TestClinic::DOCTOR_PASSWORD, $doctor->password));
        $this->assertTrue($doctor->can_access_reports);

        $assistant = $this->secretary->fresh();
        $this->assertSame(TestClinic::ASSISTANT_EMAIL, $assistant->email);
        $this->assertTrue(Hash::check(TestClinic::ASSISTANT_PASSWORD, $assistant->password));
        $this->assertFalse($assistant->can_access_reports);
    }

    public function test_it_creates_the_assistant_when_there_is_none(): void
    {
        $this->secretary->delete();

        $this->artisan('clinic:test-clinic', ['--force' => true])->assertSuccessful();

        $assistant = User::where('email', TestClinic::ASSISTANT_EMAIL)->sole();
        $this->assertSame($this->clinic->id, $assistant->activeClinic()->id);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->artisan('clinic:test-clinic', ['--force' => true])->assertSuccessful();
        $this->artisan('clinic:test-clinic', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, Clinic::where('is_test', true)->count());
        $this->assertSame(1, User::where('email', TestClinic::DOCTOR_EMAIL)->count());
    }

    /** Only the clinic that plays the part — a real clinic is never touched. */
    public function test_a_real_clinic_is_left_alone(): void
    {
        $real = $this->otherClinic();
        $real->update(['name' => 'عيادة حقيقية', 'slug' => 'real-clinic']);

        $this->artisan('clinic:test-clinic', ['--force' => true])->assertSuccessful();

        $this->assertSame('عيادة حقيقية', $real->fresh()->name);
        $this->assertFalse($real->fresh()->is_test);
    }

    public function test_with_no_candidate_it_says_so_and_changes_nothing(): void
    {
        $this->owner->update(['email' => 'someone@example.test']);

        $this->artisan('clinic:test-clinic', ['--force' => true])->assertFailed();

        $this->assertSame('عيادة د. سارة النجار', $this->clinic->fresh()->name);
    }
}
