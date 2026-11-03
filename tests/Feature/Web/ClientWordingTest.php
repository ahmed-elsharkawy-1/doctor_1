<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Livewire\Patient\BookVisit;
use App\Models\Booking;
use App\Services\Messaging\OtpSender;
use App\Services\V1\Booking\SlotAvailabilityService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingOtpSender;
use Tests\TestCase;

/**
 * The people who book are the clinic's clients, and every page they see
 * says so: no "مريض" or "مرضى" on the booking page, the tracking page, the
 * review page, the doctor's page, or the WhatsApp text they send the clinic.
 * The clinic's own screens keep their wording.
 */
class ClientWordingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    private const PATIENT_WORDS = '/مريض|مرضى/u';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->clinic->update([
            'slug' => 'dr-sara',
            'phone' => '01001234500',
            'address' => '12 شارع الجمهورية، المنصورة',
            'self_booking_enabled' => true,
            'booking_window_days' => 7,
            'patient_booking_window_days' => 3,
        ]);

        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $schedule->update(['is_open' => true]);

        if ($schedule->periods()->count() === 0) {
            $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function assertClientWording(string $html, string $page): void
    {
        // The WhatsApp link carries its text URL-encoded.
        $text = rawurldecode(html_entity_decode($html));

        $this->assertDoesNotMatchRegularExpression(self::PATIENT_WORDS, $text, "The $page still says patient.");
    }

    private function booking(): Booking
    {
        return Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse(self::DAY.' 10:00', 'Africa/Cairo'))
            ->create();
    }

    public function test_the_booking_page_says_client_from_start_to_finish(): void
    {
        $sender = new RecordingOtpSender;
        $this->app->instance(OtpSender::class, $sender);

        $page = Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start');
        $this->assertClientWording($page->html(), 'details step');

        $page->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', '01012225521')
            ->call('sendCode')
            ->set('code', $sender->lastCode())
            ->call('verifyCode')
            ->assertViewHas('stage', 'appointment');
        $this->assertClientWording($page->html(), 'appointment step');

        $visitType = $this->clinic->visitTypes()->selfBookable()->firstOrFail();
        $slot = collect(app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::parse(self::DAY, $this->clinic->timezone),
            $visitType,
        )->slots)->first(fn ($slot) => $slot->isAvailable);

        $page->call('selectSlot', $slot->startAt->format('H:i'))
            ->call('confirm')
            ->assertViewHas('stage', 'done');
        $this->assertClientWording($page->html(), 'confirmation');
    }

    public function test_the_tracking_page_and_its_whatsapp_text_say_client(): void
    {
        $html = $this->get($this->booking()->trackingUrl())
            ->assertOk()
            ->assertSee('wa.me', escape: false)
            ->getContent();

        $this->assertClientWording($html, 'tracking page');
    }

    public function test_the_review_page_says_client(): void
    {
        $booking = $this->booking();
        $booking->update(['status' => BookingStatus::DONE]);

        $this->assertClientWording($this->get($booking->reviewUrl())->assertOk()->getContent(), 'review page');
    }

    public function test_the_doctors_page_says_client(): void
    {
        $this->assertClientWording($this->get('/'.$this->clinic->slug)->assertOk()->getContent(), 'doctor page');
    }
}
