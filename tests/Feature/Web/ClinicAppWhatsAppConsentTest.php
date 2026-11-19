<?php

namespace Tests\Feature\Web;

use App\Enums\WhatsAppOptOutSource;
use App\Livewire\App\PatientProfile;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The same WhatsApp switch on the web app's patient page as on the API.
 */
class ClinicAppWhatsAppConsentTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
    }

    public function test_the_page_shows_messages_on_with_the_stop_button(): void
    {
        $this->actingAs($this->secretary)
            ->get(route('app.patients.show', $this->patient))
            ->assertOk()
            ->assertSee(__('patient.whatsapp.on'))
            ->assertSee(__('patient.whatsapp.stop'));
    }

    public function test_the_page_shows_who_stopped_them_and_when(): void
    {
        $this->patient->update([
            'whatsapp_opt_in_at' => null,
            'whatsapp_opted_out_at' => Carbon::parse('2026-11-19 18:40'),
            'whatsapp_opt_out_source' => WhatsAppOptOutSource::PATIENT,
        ]);

        $this->actingAs($this->secretary)
            ->get(route('app.patients.show', $this->patient))
            ->assertOk()
            ->assertSee(__('patient.whatsapp.off'))
            ->assertSee('2026-11-19')
            ->assertSee(__('patient.whatsapp.source.patient'))
            ->assertSee(__('patient.whatsapp.resume'));
    }

    public function test_the_secretary_stops_and_turns_them_back_on(): void
    {
        $this->actingAs($this->secretary);

        Livewire::test(PatientProfile::class, ['patient' => $this->patient->id])
            ->call('stopWhatsApp')
            ->assertSet('notice', __('patient.whatsapp.stopped_ok'));

        $this->assertSame(WhatsAppOptOutSource::CLINIC, $this->patient->fresh()->whatsapp_opt_out_source);
        $this->assertNull($this->patient->fresh()->whatsapp_opt_in_at);

        Livewire::test(PatientProfile::class, ['patient' => $this->patient->id])
            ->call('resumeWhatsApp')
            ->assertSet('notice', __('patient.whatsapp.resumed_ok'));

        $this->assertNotNull($this->patient->fresh()->whatsapp_opt_in_at);
        $this->assertNull($this->patient->fresh()->whatsapp_opted_out_at);
    }
}
