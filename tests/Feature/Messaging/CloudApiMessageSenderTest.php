<?php

namespace Tests\Feature\Messaging;

use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Services\Messaging\CloudApiMessageSender;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * What actually reaches Meta.
 *
 * The templates are approved and immutable: a body parameter count that does
 * not match is rejected whole, and so is a language code that names a
 * translation the template does not have. Both are pinned here against the
 * definitions read back from the Graph API, because neither is visible from
 * anywhere else in the codebase.
 */
class CloudApiMessageSenderTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const ENDPOINT = 'https://graph.facebook.com/v25.0/PHONE_ID/messages';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-03 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $schedule = $this->clinic->scheduleFor(DayOfWeek::THURSDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        $this->clinic->update([
            'address' => '12 شارع مصدق، الدقي',
            'phone' => '+201012223344',
            'patient_arrival_lead_minutes' => 15,
        ]);

        // Nothing must reach a driver except through the explicit call each
        // test makes.
        Queue::fake();

        config([
            'services.whatsapp.token' => 'test-token',
            'services.whatsapp.phone_number_id' => 'PHONE_ID',
            'services.whatsapp.api_version' => 'v25.0',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private int $patientSeq = 0;

    private function booking(): Booking
    {
        // A clinic cannot hold two patients on one number, and some tests queue
        // several messages in a row.
        $phone = '+2010123456'.str_pad((string) (++$this->patientSeq), 2, '0', STR_PAD_LEFT);

        $patient = Patient::factory()->for($this->clinic)->create([
            'name' => 'سارة أحمد',
            'phone' => $phone,
            'whatsapp_opt_in_at' => now(),
        ]);

        return Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 18:30', $this->clinic->timezone))
            ->create(['patient_id' => $patient->id]);
    }

    /**
     * Queues one message the way the app does, then hands it back unsent —
     * the queue is faked, so nothing has gone through a driver yet.
     */
    private function queueMessage(string $templateKey): OutboundMessage
    {
        $booking = $this->booking();
        $messaging = app(WhatsAppMessagingService::class);

        match ($templateKey) {
            WhatsAppMessagingService::CONFIRMATION_KEY => $messaging->sendConfirmation($this->clinic, $booking),
            WhatsAppMessagingService::VISIT_COMPLETED_KEY => $messaging->sendVisitCompleted($this->clinic, $booking),
            default => $messaging->sendForBooking($this->clinic, $booking->id, $templateKey),
        };

        return OutboundMessage::latest('id')->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | The confirmation — 6 body params and a URL button
    |--------------------------------------------------------------------------
    */

    public function test_the_confirmation_is_posted_in_the_approved_shape(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['messages' => [['id' => 'wamid.TEST']]])]);

        $message = $this->queueMessage(WhatsAppMessagingService::CONFIRMATION_KEY);

        app(CloudApiMessageSender::class)->send($message);

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('whatsapp', $body['messaging_product']);
            // International, digits only — no leading plus.
            $this->assertSame('201012345601', $body['to']);
            $this->assertSame('template', $body['type']);
            $this->assertSame('appointment_booking_confirmation', $body['template']['name']);
            $this->assertSame('ar', $body['template']['language']['code']);

            [$bodyComponent, $buttonComponent] = $body['template']['components'];

            $this->assertSame('body', $bodyComponent['type']);
            $this->assertCount(6, $bodyComponent['parameters']);
            $this->assertSame('سارة أحمد', $bodyComponent['parameters'][0]['text']);
            $this->assertSame('15 دقيقة', $bodyComponent['parameters'][5]['text']);

            $this->assertSame('button', $buttonComponent['type']);
            $this->assertSame('url', $buttonComponent['sub_type']);
            $this->assertSame('0', $buttonComponent['index']);
            $this->assertStringStartsWith('booking/', $buttonComponent['parameters'][0]['text']);

            return true;
        });

        $message->refresh();

        $this->assertSame('sent', $message->status);
        $this->assertSame('wamid.TEST', $message->provider_message_id);
        $this->assertNotNull($message->sent_at);
        $this->assertNull($message->error);
    }

    /*
    |--------------------------------------------------------------------------
    | The rating — no body params, en_US, button only
    |--------------------------------------------------------------------------
    */

    public function test_the_rating_is_posted_with_a_button_and_no_body(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['messages' => [['id' => 'wamid.RATE']]])]);

        $message = $this->queueMessage(WhatsAppMessagingService::VISIT_COMPLETED_KEY);

        app(CloudApiMessageSender::class)->send($message);

        Http::assertSent(function ($request) {
            $template = $request->data()['template'];

            $this->assertSame('appointment_rating', $template['name']);
            // Registered at Meta as en_US even though the text is Arabic.
            // Sending 'ar' fails outright.
            $this->assertSame('en_US', $template['language']['code']);

            // A body component with no parameters would be rejected, so there
            // must be exactly one component and it must be the button.
            $this->assertCount(1, $template['components']);
            $this->assertSame('button', $template['components'][0]['type']);
            $this->assertStringStartsWith('review/', $template['components'][0]['parameters'][0]['text']);

            return true;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The cancellation — 4 body params, no button
    |--------------------------------------------------------------------------
    */

    public function test_the_cancellation_is_posted_with_four_params_and_no_button(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['messages' => [['id' => 'wamid.CANCEL']]])]);

        $message = $this->queueMessage('day_cancelled');

        app(CloudApiMessageSender::class)->send($message);

        Http::assertSent(function ($request) {
            $template = $request->data()['template'];

            $this->assertSame('booking_cancellation', $template['name']);
            $this->assertSame('ar', $template['language']['code']);
            $this->assertCount(1, $template['components']);

            $parameters = $template['components'][0]['parameters'];

            $this->assertCount(4, $parameters);
            $this->assertSame('سارة أحمد', $parameters[0]['text']);
            // Bare: the template prints "مع الدكتور" itself.
            $this->assertSame(
                preg_replace('/^(?:ال)?(?:د\.|دكتورة|دكتور)\s*/u', '', $this->clinic->doctor->name),
                $parameters[1]['text'],
            );
            $this->assertSame('+201012223344', $parameters[3]['text']);

            return true;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Failure
    |--------------------------------------------------------------------------
    */

    public function test_metas_own_error_is_recorded_rather_than_a_status_code(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'error' => ['message' => 'Template name does not exist in the translation'],
        ], 400)]);

        $message = $this->queueMessage(WhatsAppMessagingService::CONFIRMATION_KEY);

        try {
            app(CloudApiMessageSender::class)->send($message);
            $this->fail('A failed send must throw so the job can retry.');
        } catch (RuntimeException $e) {
            $this->assertSame('Template name does not exist in the translation', $e->getMessage());
        }

        // The job's handler is what records the failure, so the row is
        // untouched here — it must not have been marked sent.
        $this->assertNotSame('sent', $message->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | How the values read on a phone
    |--------------------------------------------------------------------------
    */

    /**
     * booking_cancellation prints "مع الدكتور {{2}}" itself. A name stored with
     * the honorific already in it produced "الدكتور د. سارة النجار".
     */
    public function test_the_cancellation_never_repeats_the_doctors_honorific(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['messages' => [['id' => 'x']]])]);

        $this->clinic->doctor->update(['name' => 'د. سارة النجار']);

        $message = $this->queueMessage('day_cancelled');

        $this->assertSame('سارة النجار', $message->variables[1]);

        // And a clinic that stored the name without one reads the same.
        $this->clinic->doctor->update(['name' => 'سارة النجار']);

        $this->assertSame('سارة النجار', $this->queueMessage('day_cancelled')->variables[1]);
    }

    /**
     * The confirmation prints the name under its own label and expects the
     * honorific with it — whichever way the clinic typed it.
     */
    public function test_the_confirmation_always_carries_one_honorific(): void
    {
        foreach (['د. سارة النجار', 'سارة النجار', 'الدكتورة سارة النجار'] as $stored) {
            $this->clinic->doctor->update(['name' => $stored]);

            $this->assertSame(
                'د. سارة النجار',
                $this->queueMessage(WhatsAppMessagingService::CONFIRMATION_KEY)->variables[3],
                "Stored as [{$stored}]",
            );
        }
    }

    /**
     * Dates, times and minutes are generated in Western digits. An address is
     * typed by hand and may not be, and one message carrying both looks broken.
     */
    public function test_arabic_indic_digits_in_stored_text_are_brought_into_line(): void
    {
        $this->clinic->update(['address' => '١٢ شارع مصدق، الدقي، الجيزة']);

        $variables = $this->queueMessage(WhatsAppMessagingService::CONFIRMATION_KEY)->variables;

        $this->assertSame('12 شارع مصدق، الدقي، الجيزة', $variables[4]);

        foreach ($variables as $value) {
            $this->assertDoesNotMatchRegularExpression('/[٠-٩۰-۹]/u', (string) $value);
        }
    }

    public function test_it_refuses_to_send_without_credentials(): void
    {
        Http::fake();

        config(['services.whatsapp.token' => '', 'services.whatsapp.phone_number_id' => '']);

        $message = $this->queueMessage(WhatsAppMessagingService::CONFIRMATION_KEY);

        $this->expectException(RuntimeException::class);

        app(CloudApiMessageSender::class)->send($message);
    }

    public function test_an_unmapped_template_is_refused_before_anything_is_queued(): void
    {
        MessageTemplate::create([
            'key' => 'something_new',
            'category' => 'utility',
            'is_broadcast' => true,
            'provider_template_name' => 'something_new',
            'language_code' => 'ar',
            'body_ar' => 'مرحباً {{1}}',
            'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);

        // Better to fail loudly here than to post a body Meta will reject.
        $this->queueMessage('something_new');
    }
}
