<?php

namespace Tests\Feature\Filament;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Models\User;
use App\Models\VisitType;
use Database\Seeders\MessageTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ELA-22: the clinic announcement sent to some of a doctor's branches or all
 * of them — the name line says which branch for some, the doctor alone for
 * all, and the phone is always the patient's branch's.
 */
class BranchAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-04';

    private Clinic $clinic;

    private Branch $gedeila;

    private Branch $tura;

    private VisitType $visitType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00', 'Africa/Cairo'));
        config(['clinic.branches.multi_enabled' => true]);
        Queue::fake();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->seed(MessageTemplateSeeder::class);

        $this->clinic = Clinic::factory()->create(['phone' => '+201001234567', 'slug' => 'dr-test']);
        app(ProvisionClinicAction::class)->execute($this->clinic, 'secret-password');
        Doctor::factory()->create(['clinic_id' => $this->clinic->id, 'name' => 'سهام عبدالعزيز']);
        $this->visitType = VisitType::factory()->create(['clinic_id' => $this->clinic->id]);

        $this->gedeila = $this->clinic->firstBranch();
        $this->gedeila->update(['name' => 'عيادة جديلة']);
        $this->tura = $this->clinic->branches()->create([
            'name' => 'عيادة الترعة',
            'address' => 'الترعة',
            'phone' => '+201009998887',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function bookedAt(Branch $branch, string $phone, string $time = '18:00', ?Patient $patient = null): Patient
    {
        $patient ??= Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => $phone,
            'whatsapp_opt_in_at' => now(),
        ]);

        Booking::factory()->create([
            'clinic_id' => $this->clinic->id,
            'branch_id' => $branch->id,
            'doctor_id' => $this->clinic->doctor->id,
            'patient_id' => $patient->id,
            'visit_type_id' => $this->visitType->id,
            'visit_date' => self::DAY,
            'start_at' => Carbon::parse(self::DAY.' '.$time),
            'end_at' => Carbon::parse(self::DAY.' '.$time)->addMinutes(20),
        ]);

        return $patient;
    }

    /** @return array<int, list<string>> patient id => variables */
    private function sent(): array
    {
        return OutboundMessage::query()->get()->mapWithKeys(fn ($m) => [$m->patient_id => $m->variables])->all();
    }

    public function test_one_branch_names_it_after_the_doctor_and_reaches_only_its_patients(): void
    {
        $atGedeila = $this->bookedAt($this->gedeila, '+201011110001');
        $atTura = $this->bookedAt($this->tura, '+201011110002');

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->callAction('sendAnnouncement', data: ['date' => self::DAY, 'branches' => [$this->tura->id], 'text' => 'الدكتورة هتتأخر ساعة.'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('filament.clinic.announcement_sent', ['sent' => 1, 'skipped' => 0]));

        $sent = $this->sent();
        $this->assertSame([$atTura->id], array_keys($sent));
        $this->assertSame('د. سهام عبدالعزيز – عيادة الترعة', $sent[$atTura->id][0]);
        $this->assertSame('+201009998887', $sent[$atTura->id][3]);
        $this->assertArrayNotHasKey($atGedeila->id, $sent);
    }

    public function test_all_branches_names_the_doctor_alone_with_each_patients_branch_phone(): void
    {
        $atGedeila = $this->bookedAt($this->gedeila, '+201011110001');
        $atTura = $this->bookedAt($this->tura, '+201011110002');

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->mountAction('sendAnnouncement')
            // All branches ticked to begin with.
            ->assertMountedActionModalSee('عيادة الترعة')
            ->fillForm(['date' => self::DAY, 'text' => 'خبر'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $sent = $this->sent();
        $this->assertSame('د. سهام عبدالعزيز', $sent[$atGedeila->id][0]);
        $this->assertSame('د. سهام عبدالعزيز', $sent[$atTura->id][0]);
        // Gedeila has no number of its own: the clinic's.
        $this->assertSame('+201001234567', $sent[$atGedeila->id][3]);
        $this->assertSame('+201009998887', $sent[$atTura->id][3]);
    }

    public function test_a_patient_booked_at_two_branches_gets_one_message_from_the_first(): void
    {
        $patient = $this->bookedAt($this->tura, '+201011110001', '13:00');
        $this->bookedAt($this->gedeila, '+201011110001', '19:00', $patient);

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->callAction('sendAnnouncement', data: ['date' => self::DAY, 'text' => 'خبر'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, OutboundMessage::query()->count());
        $this->assertSame('+201009998887', $this->sent()[$patient->id][3]);
    }

    public function test_the_count_follows_the_branches_ticked(): void
    {
        $this->bookedAt($this->gedeila, '+201011110001');
        $this->bookedAt($this->tura, '+201011110002');
        $this->bookedAt($this->tura, '+201011110003');

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->mountAction('sendAnnouncement')
            ->fillForm(['date' => self::DAY])
            ->assertMountedActionModalSee(__('filament.clinic.announcement_will_send', ['sent' => 3, 'skipped' => 0]))
            ->fillForm(['branches' => [$this->gedeila->id]])
            ->assertMountedActionModalSee(__('filament.clinic.announcement_will_send', ['sent' => 1, 'skipped' => 0]));

        $this->assertSame(0, OutboundMessage::query()->count());
    }

    public function test_no_branch_ticked_sends_nothing(): void
    {
        $this->bookedAt($this->gedeila, '+201011110001');

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->callAction('sendAnnouncement', data: ['date' => self::DAY, 'branches' => [], 'text' => 'خبر'])
            ->assertHasActionErrors(['branches' => 'required']);

        $this->assertSame(0, OutboundMessage::query()->count());
    }

    public function test_a_one_branch_clinic_sees_no_branch_choice(): void
    {
        $this->tura->delete();
        $patient = $this->bookedAt($this->gedeila, '+201011110001');

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->mountAction('sendAnnouncement')
            ->assertMountedActionModalDontSee(__('filament.clinic.announcement_branches'))
            ->fillForm(['date' => self::DAY, 'text' => 'خبر'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(['د. سهام عبدالعزيز', $patient->name, 'خبر', '+201001234567'], $this->sent()[$patient->id]);
    }
}
