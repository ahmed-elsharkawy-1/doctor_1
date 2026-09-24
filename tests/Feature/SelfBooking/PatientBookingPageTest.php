<?php

namespace Tests\Feature\SelfBooking;

use App\Enums\DayOfWeek;
use App\Livewire\Patient\BookVisit;
use App\Models\VisitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The public booking page as a stranger meets it: does it open, what does it
 * offer, and what does it refuse to offer.
 *
 * Unauthenticated throughout — there is no `actingAs` anywhere in this file,
 * and that is the point.
 */
class PatientBookingPageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();

        $this->clinic->update([
            'slug' => 'dr-sara',
            'address' => '12 شارع الجمهورية، المنصورة',
            'phone' => '01001234500',
            'self_booking_enabled' => true,
        ]);

        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(): string
    {
        return '/'.$this->clinic->slug.'/'.config('clinic.self_booking.path');
    }

    /*
    |--------------------------------------------------------------------------
    | Getting in
    |--------------------------------------------------------------------------
    */

    public function test_the_page_opens_without_logging_in(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee($this->clinic->doctor->name)
            ->assertSee(__('booking.self_booking.available_title'));
    }

    /**
     * A clinic that has not switched self-booking on has no booking page —
     * not a disabled one. Same treatment as a deactivated clinic's landing page.
     */
    public function test_a_clinic_that_has_not_switched_it_on_has_no_page(): void
    {
        $this->clinic->update(['self_booking_enabled' => false]);

        $this->get($this->url())->assertNotFound();
    }

    public function test_a_deactivated_clinic_has_no_page_either(): void
    {
        $this->clinic->update(['is_active' => false]);

        $this->get($this->url())->assertNotFound();
    }

    public function test_an_unknown_slug_is_not_found(): void
    {
        $this->get('/nobody/'.config('clinic.self_booking.path'))->assertNotFound();
    }

    /** Only the doctor's own page is meant to be found by a search engine. */
    public function test_the_page_is_hidden_from_search_engines(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
    }

    public function test_the_page_is_right_to_left(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('dir="rtl"', escape: false)
            ->assertSee(config('clinic.brand.slogan'));
    }

    /*
    |--------------------------------------------------------------------------
    | The landing page's own button
    |--------------------------------------------------------------------------
    */

    public function test_the_doctors_page_offers_booking_only_when_it_is_switched_on(): void
    {
        $this->get('/'.$this->clinic->slug)
            ->assertOk()
            ->assertSee(__('landing.book_online'))
            ->assertSee($this->url(), escape: false);

        $this->clinic->update(['self_booking_enabled' => false]);

        // Asserted on the link, not the label: Arabic copy elsewhere on the
        // page shares substrings with it, and the link is the thing that
        // must not be there.
        $this->get('/'.$this->clinic->slug)
            ->assertOk()
            ->assertDontSee($this->url(), escape: false);
    }

    /**
     * WhatsApp is how patients already reach this clinic — switching booking on
     * moves it to second place, it does not remove it.
     */
    public function test_whatsapp_survives_alongside_the_booking_button(): void
    {
        $this->get('/'.$this->clinic->slug)
            ->assertOk()
            ->assertSee('https://wa.me/201001234500', escape: false);
    }

    /*
    |--------------------------------------------------------------------------
    | What it offers
    |--------------------------------------------------------------------------
    */

    /**
     * Patients get a shorter horizon than the secretary, so she keeps room for
     * the people who phone her.
     */
    public function test_it_offers_only_the_patient_window(): void
    {
        $this->clinic->update([
            'booking_window_days' => 7,
            'patient_booking_window_days' => 3,
        ]);

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertOk()
            ->assertViewHas('days', fn (array $days): bool => count($days) === 3);
    }

    public function test_the_patient_window_can_never_outrun_the_clinics_own(): void
    {
        $this->clinic->update([
            'booking_window_days' => 2,
            'patient_booking_window_days' => 14,
        ]);

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertViewHas('days', fn (array $days): bool => count($days) === 2);
    }

    public function test_a_type_the_clinic_keeps_to_itself_is_never_offered(): void
    {
        $private = VisitType::factory()->notSelfBookable()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'عملية كبرى',
        ]);

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertDontSee($private->name)
            ->assertViewHas('visitTypes', fn ($types): bool => ! $types->contains('id', $private->id));
    }

    public function test_a_hidden_type_is_never_offered(): void
    {
        $hidden = VisitType::factory()->hidden()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'نوع قديم',
        ]);

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertViewHas('visitTypes', fn ($types): bool => ! $types->contains('id', $hidden->id));
    }

    /** A dead end is still a page: say so rather than showing an empty grid. */
    public function test_a_clinic_with_nothing_bookable_says_so(): void
    {
        $this->clinic->visitTypes()->update(['is_self_bookable' => false]);

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertSee(__('booking.self_booking.nothing_bookable'));
    }

    public function test_the_opening_screen_counts_what_is_free(): void
    {
        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->assertViewHas('days', function (array $days): bool {
                $today = $days[0];

                return $today['available_count'] > 0 && $today['first_free'] !== null;
            });
    }
}
