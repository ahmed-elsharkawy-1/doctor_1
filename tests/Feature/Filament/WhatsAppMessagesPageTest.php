<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ListOutboundMessages;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Every WhatsApp message the platform sent, and what Meta said became of it —
 * so the team can check a message reached a patient without the server.
 */
class WhatsAppMessagesPageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();

        $this->admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(array $attributes = [], ?Clinic $clinic = null): OutboundMessage
    {
        $booking = Booking::factory()->forClinic($clinic ?? $this->clinic)->create();

        return OutboundMessage::create($attributes + [
            'clinic_id' => $booking->clinic_id,
            'patient_id' => $booking->patient_id,
            'booking_id' => $booking->id,
            'template_key' => WhatsAppMessagingService::CONFIRMATION_KEY,
            'rendered_body' => 'تم تأكيد حجز موعدك بنجاح',
            'status' => 'sent',
            'provider_message_id' => 'wamid.'.uniqid(),
            'sent_at' => now(),
        ]);
    }

    public function test_the_platform_operator_sees_each_message_with_its_phone_and_text(): void
    {
        $message = $this->message();

        Livewire::actingAs($this->admin)
            ->test(ListOutboundMessages::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$message])
            ->assertSee($message->patient->phone)
            ->assertSee('تم تأكيد حجز موعدك بنجاح')
            ->assertSee(__('filament.outbound_message.types.booking_confirmed'));
    }

    public function test_a_clinic_account_cannot_reach_the_page(): void
    {
        $this->actingAs($this->owner)
            ->get('/'.config('clinic.panel.path').'/outbound-messages')
            ->assertForbidden();

        // The panel already turns clinics away; the page refuses on its own
        // too, so a change to the panel's rule cannot open it.
        $this->assertFalse(OutboundMessageResource::canViewAny());

        $this->actingAs($this->admin);
        $this->assertTrue(OutboundMessageResource::canViewAny());
    }

    public function test_messages_can_be_filtered_by_status(): void
    {
        $sent = $this->message();
        $failed = $this->message(['status' => 'failed', 'error' => '131049 — not delivered']);

        Livewire::actingAs($this->admin)
            ->test(ListOutboundMessages::class)
            ->filterTable('status', 'failed')
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$sent]);
    }

    public function test_messages_can_be_filtered_by_clinic_and_type(): void
    {
        $here = $this->message();
        $elsewhere = $this->message(clinic: $this->otherClinic());
        $reminder = $this->message(['template_key' => WhatsAppMessagingService::REMINDER_KEY]);

        Livewire::actingAs($this->admin)
            ->test(ListOutboundMessages::class)
            ->filterTable('clinic_id', $this->clinic->id)
            ->assertCanSeeTableRecords([$here, $reminder])
            ->assertCanNotSeeTableRecords([$elsewhere])
            ->resetTableFilters()
            ->filterTable('template_key', WhatsAppMessagingService::REMINDER_KEY)
            ->assertCanSeeTableRecords([$reminder])
            ->assertCanNotSeeTableRecords([$here, $elsewhere]);
    }

    public function test_messages_can_be_filtered_by_date(): void
    {
        $old = $this->message();
        $old->forceFill(['created_at' => now()->subDays(10)])->save();
        $recent = $this->message();

        Livewire::actingAs($this->admin)
            ->test(ListOutboundMessages::class)
            ->filterTable('created_at', ['from' => now()->subDay()->toDateString()])
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_a_message_can_be_found_by_the_patients_phone_or_metas_id(): void
    {
        $wanted = $this->message(['provider_message_id' => 'wamid.WANTED']);
        $other = $this->message();

        Livewire::actingAs($this->admin)
            ->test(ListOutboundMessages::class)
            ->searchTable($wanted->patient->phone)
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other])
            ->searchTable('wamid.WANTED')
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_page_counts_the_last_day_by_status(): void
    {
        $this->message(['status' => 'read']);
        $this->message(['status' => 'read']);
        $this->message(['status' => 'failed']);
        $this->message(['status' => 'delivered'])->forceFill(['created_at' => now()->subDays(2)])->save();

        $subheading = Livewire::actingAs($this->admin)
            ->test(ListOutboundMessages::class)
            ->instance()
            ->getSubheading();

        $this->assertStringContainsString(__('filament.outbound_message.statuses.read').': 2', $subheading);
        $this->assertStringContainsString(__('filament.outbound_message.statuses.failed').': 1', $subheading);
        $this->assertStringNotContainsString(__('filament.outbound_message.statuses.delivered'), $subheading);
    }

    /**
     * A record of what was sent is only worth anything if nobody here can
     * rewrite or remove it.
     */
    public function test_messages_cannot_be_created_edited_or_deleted_from_the_panel(): void
    {
        $message = $this->message();

        $this->assertFalse(OutboundMessageResource::canCreate());
        $this->assertFalse(OutboundMessageResource::canEdit($message));
        $this->assertFalse(OutboundMessageResource::canDelete($message));
    }

    public function test_the_details_show_every_time_and_metas_reason(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 16:00:00', 'Africa/Cairo'));

        $message = $this->message([
            'status' => 'failed',
            'error' => '131049 — This message was not delivered',
            'provider_message_id' => 'wamid.DETAILS',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListOutboundMessages::class)
            ->mountAction(TestAction::make('view')->table($message))
            ->assertActionMounted(TestAction::make('view')->table($message))
            ->assertMountedActionModalSee([
                '131049 — This message was not delivered',
                'wamid.DETAILS',
                '2026-09-12 16:00',
                'تم تأكيد حجز موعدك بنجاح',
            ]);

        Carbon::setTestNow();
    }
}
