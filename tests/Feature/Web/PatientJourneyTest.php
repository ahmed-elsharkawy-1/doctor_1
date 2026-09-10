<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Enums\ReviewRating;
use App\Models\Booking;
use App\Models\BookingReview;
use App\Models\OutboundMessage;
use App\Models\VisitType;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * One patient, start to finish, through the doors everyone actually uses.
 *
 * The per-screen tests each prove a part. This one proves the joins between
 * them: that the link in the confirmation message is the link the tracking
 * page answers on, that finishing the visit through the mobile API's own
 * status endpoint is what produces the review message, and that the token in
 * that message opens a review page which stores a row the dashboard reads.
 *
 * Every clinic-side step goes through the HTTP API, because that is what the
 * secretary's app does. Every patient-side step is a plain unauthenticated
 * GET or POST, because that is what a phone opening a WhatsApp link does.
 */
class PatientJourneyTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private VisitType $visitType;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday, before the clinic opens.
        Carbon::setTestNow(Carbon::parse('2026-09-03 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::THURSDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        // The factory leaves the slug blank, and the public page is addressed
        // by it.
        $this->clinic->update(['slug' => 'dr-sara']);

        $this->visitType = $this->clinic->visitTypes()->active()->firstOrFail();

        $this->seed(MessageTemplateSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_patient_goes_from_booking_to_review(): void
    {
        /*
        | 1. The secretary takes the booking on the mobile app.
        */
        Sanctum::actingAs($this->owner);

        $created = $this->postJson(route('api.v1.bookings.store'), [
            'patient_name' => 'منى عبد الرحمن',
            'phone' => '01012345678',
            'age' => 34,
            'whatsapp_opt_in' => true,
            'visit_type_id' => $this->visitType->id,
            'date' => '2026-09-03',
            'start_time' => $this->firstFreeSlot(),
            'booking_kind' => 'normal',
        ])->assertCreated()->json('data');

        $booking = Booking::findOrFail($created['id']);

        $this->assertSame(BookingStatus::BOOKED, $booking->status);
        // Price and duration are frozen onto the booking at creation.
        $this->assertSame($this->visitType->price, $booking->price);

        /*
        | 2. The confirmation goes out by itself, carrying the tracking link.
        |    Nobody pressed anything.
        */
        $confirmation = OutboundMessage::where('booking_id', $booking->id)
            ->where('template_key', WhatsAppMessagingService::CONFIRMATION_KEY)
            ->sole();

        // The approved template puts the link on its URL button; Meta appends
        // the suffix to a fixed base, so the whole path travels there.
        $this->assertSame('booking/'.$booking->tracking_token, $confirmation->button_suffix);
        $this->assertStringEndsWith($confirmation->button_suffix, $booking->trackingUrl());
        $this->assertSame('منى عبد الرحمن', $confirmation->variables[0]);
        $this->assertSame($booking->patient_id, $confirmation->patient_id);

        /*
        | 3. The patient opens that exact link. No login, no token of their own.
        */
        $trackingUrl = $booking->trackingUrl();

        $this->get($trackingUrl)
            ->assertOk()
            ->assertSee('منى عبد الرحمن')
            ->assertSee($this->clinic->name);

        // The token is the whole secret, so a wrong one is a 404, not a hint.
        $this->get(str_replace($booking->tracking_token, 'not-a-real-token', $trackingUrl))
            ->assertNotFound();

        /*
        | 4. The visit happens. Each step is the mobile app's status endpoint.
        */
        foreach (['arrived', 'with_doctor'] as $status) {
            Sanctum::actingAs($this->owner);

            $this->postJson(route('api.v1.bookings.status', $booking->id), ['to' => $status])
                ->assertOk();
        }

        $this->assertSame(BookingStatus::WITH_DOCTOR, $booking->fresh()->status);

        // Nothing to review yet — the visit is not finished.
        $this->get($booking->reviewUrl())
            ->assertOk()
            ->assertDontSee('name="rating"', escape: false);

        /*
        | 5. The secretary marks it done, and the thank-you goes out by itself.
        */
        Sanctum::actingAs($this->owner);

        $this->postJson(route('api.v1.bookings.status', $booking->id), ['to' => 'done'])
            ->assertOk();

        $booking->refresh();

        $this->assertSame(BookingStatus::DONE, $booking->status);
        $this->assertNotNull($booking->completed_at);

        $thanks = OutboundMessage::where('booking_id', $booking->id)
            ->where('template_key', WhatsAppMessagingService::VISIT_COMPLETED_KEY)
            ->sole();

        // The test queue is synchronous, so it has already gone through the
        // log driver. What matters is that it exists and did not fail.
        $this->assertNotSame('failed', $thanks->status);
        // The rating template declares no body parameters at all — only the
        // button varies, and it points at this booking's review page.
        $this->assertSame([], $thanks->variables);
        $this->assertSame('review/'.$booking->tracking_token, $thanks->button_suffix);
        $this->assertStringEndsWith($thanks->button_suffix, $booking->reviewUrl());

        /*
        | 6. The patient opens the review page from that message and rates it.
        */
        $this->get($booking->reviewUrl())
            ->assertOk()
            ->assertSee('name="rating"', escape: false);

        $this->post($booking->reviewUrl(), [
            'rating' => ReviewRating::VERY_GOOD->value,
            'comment' => 'الدكتورة شرحت كل حاجة بهدوء.',
        ])->assertRedirect($booking->reviewUrl());

        /*
        | 7. The row is stored with everyone attached, and the page now shows
        |    the thank-you rather than the form.
        */
        $review = BookingReview::where('booking_id', $booking->id)->sole();

        $this->assertSame(ReviewRating::VERY_GOOD, $review->rating);
        $this->assertSame('الدكتورة شرحت كل حاجة بهدوء.', $review->comment);
        $this->assertSame($this->clinic->id, $review->clinic_id);
        $this->assertSame($booking->doctor_id, $review->doctor_id);
        $this->assertSame($booking->patient_id, $review->patient_id);
        $this->assertSame('review_page', $review->source);
        $this->assertNotNull($review->submitted_at);

        $this->get($booking->reviewUrl())
            ->assertOk()
            ->assertDontSee('name="rating"', escape: false);

        /*
        | 8. A second submission cannot overwrite the first.
        */
        $this->post($booking->reviewUrl(), [
            'rating' => ReviewRating::BAD->value,
            'comment' => 'غيرت رأيي.',
        ])->assertRedirect($booking->reviewUrl());

        $this->assertSame(1, BookingReview::where('booking_id', $booking->id)->count());
        $this->assertSame(ReviewRating::VERY_GOOD, $review->fresh()->rating);
    }

    /**
     * The same journey seen from the clinic's public page: a patient who has
     * never booked lands here first, and everything they need to get in touch
     * has to be on it.
     */
    public function test_the_public_page_carries_what_a_new_patient_needs(): void
    {
        $this->clinic->update(['phone' => '+201012345678']);

        $page = $this->get('/'.$this->clinic->slug)->assertOk();

        $page->assertSee($this->clinic->doctor->name)
            // The way a patient actually books: a WhatsApp message.
            ->assertSee('wa.me/201012345678', escape: false);

        // What the page shows in detail — hidden visit types, treatment
        // areas, opening hours — is DoctorLandingPageTest's subject, not this
        // one's. Here it only has to be reachable and complete enough to act on.
    }

    public function test_a_disabled_clinic_disappears_from_the_public_page(): void
    {
        $this->clinic->update(['is_active' => false]);

        $this->get('/'.$this->clinic->slug)->assertNotFound();
    }

    /**
     * A visit that never happened has nothing to review, and must not be
     * pushed into pretending otherwise.
     */
    public function test_a_cancelled_visit_is_never_reviewable(): void
    {
        $booking = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-03 09:00', 'Africa/Cairo'))
            ->cancelled()
            ->create();

        $this->get($booking->reviewUrl())
            ->assertOk()
            ->assertDontSee('name="rating"', escape: false);

        $this->post($booking->reviewUrl(), ['rating' => ReviewRating::GOOD->value])
            ->assertRedirect($booking->reviewUrl());

        $this->assertSame(0, BookingReview::where('booking_id', $booking->id)->count());
    }

    /**
     * A patient who never agreed to WhatsApp is never messaged — not at
     * booking, and not when the visit finishes.
     */
    public function test_no_consent_means_no_messages_at_either_end(): void
    {
        Sanctum::actingAs($this->owner);

        $created = $this->postJson(route('api.v1.bookings.store'), [
            'patient_name' => 'هدى سالم',
            'phone' => '01099887766',
            'whatsapp_opt_in' => false,
            'visit_type_id' => $this->visitType->id,
            'date' => '2026-09-03',
            'start_time' => $this->firstFreeSlot(),
            'booking_kind' => 'normal',
        ])->assertCreated()->json('data');

        $booking = Booking::findOrFail($created['id']);

        $this->assertSame(0, OutboundMessage::where('booking_id', $booking->id)->count());

        foreach (['arrived', 'with_doctor', 'done'] as $status) {
            Sanctum::actingAs($this->owner);
            $this->postJson(route('api.v1.bookings.status', $booking->id), ['to' => $status])->assertOk();
        }

        $this->assertSame(BookingStatus::DONE, $booking->fresh()->status);
        $this->assertSame(0, OutboundMessage::where('booking_id', $booking->id)->count());

        // The visit still happened, so the page still works if they find it.
        $this->get($booking->reviewUrl())->assertOk()->assertSee('name="rating"', escape: false);
    }

    private function firstFreeSlot(): string
    {
        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::parse('2026-09-03', $this->clinic->timezone),
            $this->visitType,
        );

        foreach ($availability->slots as $slot) {
            if ($slot->isAvailable) {
                return $slot->startAt->format('H:i');
            }
        }

        $this->fail('The clinic has no free slot on the test day.');
    }
}
