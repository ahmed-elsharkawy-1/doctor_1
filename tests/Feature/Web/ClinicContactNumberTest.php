<?php

namespace Tests\Feature\Web;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The clinic's own number, as a patient meets it.
 *
 * It is the WhatsApp the patient opens from the public page, the number the
 * tracking page offers to call, and the callback number inside the
 * cancellation message. All three read it from one column typed by hand in
 * the dashboard, so what that column may contain is the whole risk.
 */
class ClinicContactNumberTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-12 09:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->clinic->update(['slug' => 'dr-sara']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function whatsappLink(): ?string
    {
        $html = $this->get('/dr-sara')->assertOk()->getContent();

        return preg_match('#https://wa\.me/(\d+)#', $html, $m) ? $m[1] : null;
    }

    public function test_an_egyptian_number_typed_locally_opens_whatsapp(): void
    {
        $this->clinic->update(['country_code' => 'EG', 'phone' => '01001234567']);

        $this->assertSame('201001234567', $this->whatsappLink());
    }

    public function test_a_number_already_in_international_form_is_left_alone(): void
    {
        $this->clinic->update(['country_code' => 'EG', 'phone' => '+201001234567']);

        $this->assertSame('201001234567', $this->whatsappLink());
    }

    /**
     * The clinic is not always Egyptian. Parsed against the platform default
     * instead of the clinic's own country, a Saudi number typed locally came
     * back as nothing at all — and the button silently disappeared.
     */
    public function test_a_saudi_number_is_parsed_against_saudi_arabia(): void
    {
        $this->clinic->update(['country_code' => 'SA', 'phone' => '0501234567']);

        $this->assertSame('966501234567', $this->whatsappLink());
    }

    public function test_an_emirati_number_is_parsed_against_the_emirates(): void
    {
        $this->clinic->update(['country_code' => 'AE', 'phone' => '0501234567']);

        $this->assertSame('971501234567', $this->whatsappLink());
    }

    public function test_the_tracking_page_offers_the_same_number_to_call(): void
    {
        $this->clinic->update(['country_code' => 'SA', 'phone' => '0501234567']);

        $booking = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-09-12 10:00', $this->clinic->timezone))
            ->create();

        $this->get($booking->trackingUrl())
            ->assertOk()
            ->assertSee('tel:+966501234567', escape: false);
    }

    /**
     * A clinic with no number at all must not render a broken link — the
     * contact buttons come off the page instead.
     */
    public function test_a_clinic_without_a_number_shows_no_whatsapp_button(): void
    {
        $this->clinic->update(['phone' => null]);

        $this->assertNull($this->whatsappLink());
    }
}
