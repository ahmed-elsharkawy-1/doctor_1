<?php

namespace Tests\Feature\Web;

use App\Enums\DayOfWeek;
use App\Models\Booking;
use App\Models\Doctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class DoctorLandingPageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();

        $this->clinic->update([
            'slug' => 'dr-sara',
            'address' => '12 شارع الجمهورية، المنصورة',
            'phone' => '01001234500',
        ]);
    }

    private function url(): string
    {
        return '/'.$this->clinic->slug;
    }

    public function test_the_page_is_public(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee($this->clinic->name);
    }

    public function test_it_shows_the_doctor_and_the_specialty(): void
    {
        $doctor = $this->clinic->doctor;

        $this->get($this->url())
            ->assertOk()
            ->assertSee($doctor->name)
            ->assertSee($this->clinic->specialty->name);
    }

    public function test_it_offers_a_whatsapp_link_to_the_clinic(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('https://wa.me/201001234500', escape: false)
            ->assertSee(__('landing.book_on_whatsapp'));
    }

    public function test_the_whatsapp_link_carries_an_opening_message(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee(rawurlencode(__('landing.whatsapp_greeting', [
                'clinic' => $this->clinic->name,
            ])), escape: false);
    }

    public function test_it_lists_the_opening_hours(): void
    {
        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        $this->get($this->url())
            ->assertOk()
            ->assertSee(__('landing.hours'))
            ->assertSee(DayOfWeek::SATURDAY->label())
            ->assertSee('09:00');
    }

    public function test_closed_days_are_not_listed(): void
    {
        // Every day of a provisioned clinic starts closed.
        $this->get($this->url())
            ->assertOk()
            ->assertDontSee(__('landing.hours'));
    }

    public function test_hidden_visit_types_are_not_advertised(): void
    {
        $visitTypes = $this->clinic->visitTypes()->active()->get();
        $hidden = $visitTypes->first();
        $stillShown = $visitTypes->last();

        $hidden->hide();

        // Matched on the chip itself: the visit type name can legitimately
        // appear elsewhere on the page, such as inside the meta description.
        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('<span class="type">'.$hidden->name.'</span>', escape: false)
            ->assertSee('<span class="type">'.$stillShown->name.'</span>', escape: false);
    }

    public function test_it_carries_the_search_metadata(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('<link rel="canonical"', escape: false)
            ->assertSee('property="og:title"', escape: false)
            ->assertSee('application/ld+json', escape: false)
            ->assertSee('MedicalClinic', escape: false);
    }

    public function test_the_page_is_not_hidden_from_search_engines(): void
    {
        // The opposite of every other page in the system.
        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('noindex', escape: false);
    }

    public function test_an_unknown_slug_is_not_found(): void
    {
        $this->get('/no-such-clinic')->assertNotFound();
    }

    public function test_a_deactivated_clinic_has_no_page(): void
    {
        $this->clinic->update(['is_active' => false]);

        $this->get($this->url())->assertNotFound();
    }

    public function test_a_clinic_without_a_doctor_still_renders(): void
    {
        Doctor::where('clinic_id', $this->clinic->id)->update(['is_active' => false]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee($this->clinic->name);
    }

    /*
    |--------------------------------------------------------------------------
    | The catch-all must not swallow the app
    |--------------------------------------------------------------------------
    */

    public function test_the_landing_route_never_shadows_the_clinic_app(): void
    {
        $this->get('/app/login')->assertOk();
        $this->get('/app')->assertRedirect(route('app.login'));
    }

    public function test_the_landing_route_never_shadows_the_tracking_page(): void
    {
        $booking = Booking::factory()->forClinic($this->clinic)->create();

        $this->get($booking->trackingUrl())->assertOk();
    }

    public function test_the_root_signposts_staff_to_sign_in(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('app.root.title'))
            ->assertSee(route('app.login'), escape: false)
            // Clinics are found at their own page, never through this one.
            ->assertSee('noindex', escape: false);
    }

    public function test_reserved_words_are_not_available_as_slugs(): void
    {
        foreach (config('clinic.landing.reserved') as $reserved) {
            $this->assertNotSame(
                $reserved,
                $this->clinic->slug,
                "A clinic must never hold the reserved slug [{$reserved}].",
            );
        }

        // And the routes that own them still answer.
        $this->get('/admin/login')->assertSuccessful();
    }
}
