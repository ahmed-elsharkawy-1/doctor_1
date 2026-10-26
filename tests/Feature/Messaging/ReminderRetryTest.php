<?php

namespace Tests\Feature\Messaging;

use App\Enums\BookingStatus;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Booking;
use App\Models\OutboundMessage;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingMessageSender;
use Tests\TestCase;

/**
 * A reminder may be attempted twice. Never a third time.
 *
 * WhatsApp is the cost, and a broken template or an invalid number will not
 * heal on the fourth try — so the product rule is one retry, then a failure
 * the secretary can see.
 */
class ReminderRetryTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private RecordingMessageSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-03 11:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->sender = new RecordingMessageSender;

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function reminder(): OutboundMessage
    {
        $booking = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 15:00', 'Africa/Cairo'))
            ->create();

        $message = app(WhatsAppMessagingService::class)->sendReminder($this->clinic, $booking);

        $this->assertNotNull($message);

        return $message->refresh();
    }

    public function test_a_reminder_that_fails_once_is_sent_on_the_retry(): void
    {
        $message = $this->reminder();
        $this->sender->failFirst = 1;

        (new SendWhatsAppMessage($message->id))->handle($this->sender);

        $message->refresh();

        $this->assertSame(2, $this->sender->sendAttempts);
        $this->assertSame(2, $message->attempts);
        $this->assertSame('sent', $message->status);
        $this->assertNull($message->error);
    }

    public function test_two_failures_mark_it_failed_and_stop(): void
    {
        $message = $this->reminder();
        $this->sender->failWith = 'Template not approved';

        (new SendWhatsAppMessage($message->id))->handle($this->sender);

        $message->refresh();

        $this->assertSame(2, $this->sender->sendAttempts);
        $this->assertSame(2, $message->attempts);
        $this->assertSame('failed', $message->status);
        $this->assertSame('Template not approved', $message->error);

        // A worker that somehow picks the job up again must not call Meta.
        (new SendWhatsAppMessage($message->id))->handle($this->sender);

        $this->assertSame(2, $this->sender->sendAttempts);
        $this->assertSame('failed', $message->fresh()->status);
    }

    /** Cancelled between being queued and the worker reaching it: never sent. */
    public function test_a_reminder_for_a_booking_cancelled_meanwhile_is_skipped(): void
    {
        $message = $this->reminder();
        Booking::findOrFail($message->booking_id)->update(['status' => BookingStatus::CANCELLED]);

        (new SendWhatsAppMessage($message->id))->handle($this->sender);

        $this->assertSame(0, $this->sender->sendAttempts);
        $this->assertSame('skipped', $message->fresh()->status);
        $this->assertSame('not_sent', Booking::findOrFail($message->booking_id)->reminderStatus());
    }

    public function test_the_daily_reminder_gets_the_same_single_retry(): void
    {
        $booking = Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 15:00', 'Africa/Cairo'))
            ->create();

        $message = app(WhatsAppMessagingService::class)->sendDailyReminder($this->clinic, $booking);
        $this->assertNotNull($message);
        $this->sender->failFirst = 1;

        (new SendWhatsAppMessage($message->id))->handle($this->sender);

        $message->refresh();

        $this->assertSame(2, $this->sender->sendAttempts);
        $this->assertSame(2, $message->attempts);
        $this->assertSame('sent', $message->status);
    }

    public function test_the_card_reads_a_failed_reminder_as_failed(): void
    {
        $message = $this->reminder();
        $this->sender->failWith = 'Invalid number';

        (new SendWhatsAppMessage($message->id))->handle($this->sender);

        $booking = Booking::findOrFail($message->booking_id);

        $this->assertSame('failed', $booking->reminderStatus());
    }
}
