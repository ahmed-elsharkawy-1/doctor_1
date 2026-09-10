<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Jobs\SendWhatsAppMessage;
use App\Livewire\App\Messages;
use App\Models\Booking;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ClinicAppMessagesTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->today = Carbon::now($this->clinic->timezone)->startOfDay();

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  bool  $optedIn  whether the patient agreed to WhatsApp
     */
    private function bookingAt(string $time, bool $optedIn = true): Booking
    {
        $patient = Patient::factory()->for($this->clinic)->create([
            'whatsapp_opt_in_at' => $optedIn ? now() : null,
        ]);

        return Booking::factory()->forClinic($this->clinic)
            ->at($this->today->copy()->setTimeFromTimeString($time))
            ->create(['patient_id' => $patient->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | What may be sent
    |--------------------------------------------------------------------------
    */

    public function test_only_broadcast_templates_are_offered(): void
    {
        $keys = Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->viewData('templates')
            ->pluck('key')
            ->all();

        // Both of these are sent for one booking, automatically. Offering
        // either here would send it to a roomful of people.
        $this->assertNotContains(WhatsAppMessagingService::CONFIRMATION_KEY, $keys);
        $this->assertNotContains(WhatsAppMessagingService::VISIT_COMPLETED_KEY, $keys);

        $this->assertContains('day_cancelled', $keys);
        $this->assertContains('appointment_delayed', $keys);
    }

    public function test_a_per_booking_template_is_refused_even_if_asked_for(): void
    {
        $this->bookingAt('11:00');

        Livewire::actingAs($this->owner)
            ->test(Messages::class)
            // Not reachable through the UI; the service is the one that refuses.
            ->set('templateKey', WhatsAppMessagingService::CONFIRMATION_KEY)
            ->call('send')
            ->assertSet('failed', true);

        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_nothing_is_sent_before_a_template_is_chosen(): void
    {
        $this->bookingAt('11:00');

        Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->call('confirm')
            ->assertSet('confirming', false)
            ->call('send');

        $this->assertSame(0, OutboundMessage::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Who receives it
    |--------------------------------------------------------------------------
    */

    public function test_the_screen_counts_who_will_be_reached_and_who_will_be_skipped(): void
    {
        $this->bookingAt('11:00');
        $this->bookingAt('12:00', optedIn: false);

        $page = Livewire::actingAs($this->owner)->test(Messages::class);

        $this->assertCount(2, $page->viewData('recipients'));
        $this->assertCount(1, $page->viewData('willSend'));
        $this->assertCount(1, $page->viewData('willSkip'));
    }

    public function test_opted_out_patients_are_skipped_and_reported(): void
    {
        $this->bookingAt('11:00');
        $optedOut = $this->bookingAt('12:00', optedIn: false);

        $page = Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->call('selectTemplate', 'appointment_delayed')
            ->call('send')
            ->assertSet('failed', false);

        $result = $page->get('result');

        $this->assertSame(1, $result['sent_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame($optedOut->id, $result['skipped'][0]['booking_id']);
        $this->assertSame('whatsapp_not_opted_in', $result['skipped'][0]['reason']);
    }

    public function test_only_the_picked_patients_receive_it(): void
    {
        $picked = $this->bookingAt('11:00');
        $spared = $this->bookingAt('12:00');

        Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->call('selectTemplate', 'appointment_delayed')
            ->call('toggle', $picked->id)
            ->call('send')
            ->assertSet('failed', false);

        $this->assertSame(1, OutboundMessage::count());
        $this->assertSame($picked->id, OutboundMessage::first()->booking_id);
        $this->assertSame(0, OutboundMessage::where('booking_id', $spared->id)->count());
    }

    public function test_each_message_is_queued_for_delivery(): void
    {
        $this->bookingAt('11:00');

        Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->call('selectTemplate', 'appointment_delayed')
            ->call('send');

        Queue::assertPushed(SendWhatsAppMessage::class, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | The template that cancels
    |--------------------------------------------------------------------------
    */

    public function test_day_cancelled_is_flagged_as_destructive_before_sending(): void
    {
        $this->bookingAt('11:00');

        $page = Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->call('selectTemplate', 'day_cancelled')
            ->call('confirm')
            ->assertSet('confirming', true);

        $this->assertTrue($page->instance()->cancelsTheDay());

        // Confirming alone must not have sent or cancelled anything.
        $this->assertSame(0, OutboundMessage::count());
        $this->assertSame(BookingStatus::BOOKED, Booking::first()->status);
    }

    public function test_day_cancelled_cancels_the_day_and_reports_the_count(): void
    {
        $first = $this->bookingAt('11:00');
        $second = $this->bookingAt('12:00');

        $page = Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->call('selectTemplate', 'day_cancelled')
            ->call('confirm')
            ->call('send')
            ->assertSet('failed', false);

        $this->assertSame(2, $page->get('result')['cancelled_count']);

        foreach ([$first, $second] as $booking) {
            $this->assertSame(BookingStatus::CANCELLED, $booking->fresh()->status);
        }
    }

    public function test_another_template_never_cancels_anything(): void
    {
        $booking = $this->bookingAt('11:00');

        $page = Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->call('selectTemplate', 'appointment_delayed')
            ->call('send');

        $this->assertSame(0, $page->get('result')['cancelled_count']);
        $this->assertSame(BookingStatus::BOOKED, $booking->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Boundaries
    |--------------------------------------------------------------------------
    */

    public function test_another_clinics_patients_are_never_reachable(): void
    {
        $other = $this->otherClinic();

        Booking::factory()->forClinic($other)
            ->at($this->today->copy()->setTimeFromTimeString('11:00'))
            ->create();

        $page = Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->call('selectTemplate', 'appointment_delayed')
            ->call('send');

        $this->assertCount(0, $page->viewData('recipients'));
        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_moving_off_the_day_clears_the_selection(): void
    {
        $booking = $this->bookingAt('11:00');

        Livewire::actingAs($this->owner)
            ->test(Messages::class)
            ->call('selectTemplate', 'appointment_delayed')
            ->call('toggle', $booking->id)
            ->call('goToDay', 1)
            ->assertSet('selected', [])
            ->assertSet('confirming', false);
    }

    public function test_messages_need_the_ability(): void
    {
        $this->get(route('app.messages'))->assertRedirect(route('app.login'));
    }
}
