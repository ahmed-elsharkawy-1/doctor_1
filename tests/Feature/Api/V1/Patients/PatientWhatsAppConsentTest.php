<?php

namespace Tests\Feature\Api\V1\Patients;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Clinic;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Services\Messaging\MessageSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingMessageSender;
use Tests\TestCase;

/**
 * The clinic stops, or turns back on, a patient's WhatsApp messages — the
 * promise the privacy policy makes.
 */
class PatientWhatsAppConsentTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-19 18:40:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $this->patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);

        Sanctum::actingAs($this->secretary);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function switch(bool $enabled, ?Patient $patient = null)
    {
        return $this->postJson(route('api.v1.patients.whatsapp', $patient ?? $this->patient), ['enabled' => $enabled]);
    }

    public function test_the_patients_page_shows_messages_on(): void
    {
        $whatsapp = $this->getJson(route('api.v1.patients.show', $this->patient))->assertOk()->json('data.whatsapp');

        $this->assertSame(['enabled' => true, 'stopped' => false, 'stopped_at' => null, 'stopped_by' => null], $whatsapp);
    }

    public function test_never_agreeing_is_not_the_same_as_stopped(): void
    {
        $this->patient->update(['whatsapp_opt_in_at' => null]);

        $whatsapp = $this->getJson(route('api.v1.patients.show', $this->patient))->json('data.whatsapp');

        $this->assertFalse($whatsapp['enabled']);
        $this->assertFalse($whatsapp['stopped']);
    }

    public function test_the_clinic_stops_messages(): void
    {
        $whatsapp = $this->switch(false)->assertOk()
            ->assertJsonPath('message', __('patient.whatsapp.stopped_ok'))
            ->json('data.whatsapp');

        $this->assertFalse($whatsapp['enabled']);
        $this->assertTrue($whatsapp['stopped']);
        $this->assertSame('clinic', $whatsapp['stopped_by']['value']);
        $this->assertNotNull($whatsapp['stopped_at']);

        $this->assertNull($this->patient->fresh()->whatsapp_opt_in_at);
    }

    public function test_the_clinic_turns_them_back_on(): void
    {
        $this->switch(false);

        $whatsapp = $this->switch(true)->assertOk()->json('data.whatsapp');

        $this->assertSame(['enabled' => true, 'stopped' => false, 'stopped_at' => null, 'stopped_by' => null], $whatsapp);
    }

    /** Stopping twice keeps the first stop: who asked, and when. */
    public function test_a_second_stop_keeps_the_first(): void
    {
        $this->switch(false);
        $first = $this->patient->fresh()->whatsapp_opted_out_at;

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->switch(false);

        $this->assertEquals($first, $this->patient->fresh()->whatsapp_opted_out_at);
    }

    public function test_another_clinics_patient_is_not_found(): void
    {
        $other = Patient::factory()->create(['clinic_id' => Clinic::factory()->create()->id]);

        $this->switch(false, $other)->assertNotFound();

        $this->assertNotNull($other->fresh()->whatsapp_opt_in_at);
    }

    public function test_the_switch_needs_a_value(): void
    {
        $this->postJson(route('api.v1.patients.whatsapp', $this->patient), [])->assertUnprocessable();
    }

    /** A message queued before the stop does not go out after it. */
    public function test_a_message_already_queued_is_skipped_after_a_stop(): void
    {
        $message = OutboundMessage::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'template_key' => 'appointment_booking_confirmation',
            'type' => 'template',
            'rendered_body' => 'confirmation',
            'status' => 'queued',
        ]);

        $this->switch(false);

        $sender = new RecordingMessageSender;
        (new SendWhatsAppMessage($message->id))->handle($sender);

        $this->assertSame(0, $sender->sendAttempts);
        $this->assertSame('skipped', $message->fresh()->status);
    }

    public function test_a_queued_message_still_goes_while_messages_are_on(): void
    {
        $message = OutboundMessage::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'template_key' => 'appointment_booking_confirmation',
            'type' => 'template',
            'rendered_body' => 'confirmation',
            'status' => 'queued',
        ]);

        $sender = new RecordingMessageSender;
        $this->app->instance(MessageSender::class, $sender);
        (new SendWhatsAppMessage($message->id))->handle($sender);

        $this->assertSame(1, $sender->sendAttempts);
    }
}
