<?php

namespace Tests\Feature\Filament;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Enums\BookingStatus;
use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Models\User;
use App\Models\VisitType;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Database\Seeders\MessageTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The super admin's waiting-list settings, and the clinic announcement —
 * which only the panel can send.
 */
class ClinicWaitingListAndAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    private VisitType $visitType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00', 'Africa/Cairo'));

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->seed(MessageTemplateSeeder::class);

        $this->clinic = Clinic::factory()->create(['phone' => '+201001234567', 'slug' => 'dr-test']);
        app(ProvisionClinicAction::class)->execute($this->clinic, 'secret-password');
        Doctor::factory()->create(['clinic_id' => $this->clinic->id, 'name' => 'سهام عبدالعزيز']);
        $this->visitType = VisitType::factory()->create(['clinic_id' => $this->clinic->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_waiting_list_is_off_until_switched_on_with_its_count(): void
    {
        $this->assertFalse($this->clinic->fresh()->offersWaitingList());
        $this->assertSame(config('clinic.waiting_list.max_per_day'), $this->clinic->waitingListMaxPerDay());

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm(['waiting_list_enabled' => true, 'waiting_list_max_per_day' => 4])
            ->call('save')
            ->assertHasNoFormErrors();

        $clinic = $this->clinic->fresh();
        $this->assertTrue($clinic->offersWaitingList());
        $this->assertSame(4, $clinic->waitingListMaxPerDay());
    }

    private function bookedPatient(string $phone, string $date, BookingStatus $status = BookingStatus::BOOKED, bool $optIn = true): Patient
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'phone' => $phone,
            'whatsapp_opt_in_at' => $optIn ? now() : null,
        ]);

        Booking::factory()->create([
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->clinic->doctor->id,
            'patient_id' => $patient->id,
            'visit_type_id' => $this->visitType->id,
            'visit_date' => $date,
            'start_at' => Carbon::parse($date.' 18:00'),
            'end_at' => Carbon::parse($date.' 18:20'),
            'status' => $status,
        ]);

        return $patient;
    }

    public function test_an_announcement_reaches_each_upcoming_patient_once(): void
    {
        $upcoming = $this->bookedPatient('+201011110001', '2026-11-04');
        // A second booking for the same patient: still one message.
        Booking::factory()->create([
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->clinic->doctor->id,
            'patient_id' => $upcoming->id,
            'visit_type_id' => $this->visitType->id,
            'visit_date' => '2026-11-05',
            'start_at' => Carbon::parse('2026-11-05 18:00'),
            'end_at' => Carbon::parse('2026-11-05 18:20'),
        ]);
        $this->bookedPatient('+201011110002', '2026-10-20', BookingStatus::DONE);
        $this->bookedPatient('+201011110003', '2026-11-04', optIn: false);

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->callAction('sendAnnouncement', data: [
                'audience' => 'upcoming',
                'text' => "العيادة هتكون مقفولة يوم الخميس\nبسبب سفر الدكتورة.",
            ])
            ->assertHasNoActionErrors()
            ->assertNotified(__('filament.clinic.announcement_sent', ['sent' => 1, 'skipped' => 1]));

        $message = OutboundMessage::query()
            ->where('template_key', WhatsAppMessagingService::ANNOUNCEMENT_KEY)
            ->sole();

        $this->assertSame($upcoming->id, $message->patient_id);
        $this->assertNull($message->booking_id);
        $this->assertSame([
            'د. سهام عبدالعزيز',
            $upcoming->name,
            // One paragraph: Meta refuses a newline inside a parameter.
            'العيادة هتكون مقفولة يوم الخميس بسبب سفر الدكتورة.',
            '+201001234567',
        ], $message->variables);
    }

    public function test_an_announcement_for_one_day(): void
    {
        $onTheDay = $this->bookedPatient('+201011110001', '2026-11-04');
        $this->bookedPatient('+201011110002', '2026-11-05');

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->callAction('sendAnnouncement', data: [
                'audience' => 'day',
                'date' => '2026-11-04',
                'text' => 'مواعيد العيادة اتغيرت.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            [$onTheDay->id],
            OutboundMessage::query()->where('template_key', WhatsAppMessagingService::ANNOUNCEMENT_KEY)->pluck('patient_id')->all(),
        );
    }

    public function test_nothing_goes_while_the_clinic_whatsapp_is_off(): void
    {
        $this->clinic->update(['whatsapp_enabled' => false]);
        $this->bookedPatient('+201011110001', '2026-11-04');

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->callAction('sendAnnouncement', data: ['audience' => 'all', 'text' => 'خبر'])
            ->assertNotified(__('messages.whatsapp_disabled'));

        $this->assertSame(0, OutboundMessage::query()->count());
    }

    public function test_the_clinic_app_never_offers_the_announcement(): void
    {
        $keys = app(WhatsAppMessagingService::class)->templates()->pluck('key')->all();

        $this->assertNotContains(WhatsAppMessagingService::ANNOUNCEMENT_KEY, $keys);
    }
}
