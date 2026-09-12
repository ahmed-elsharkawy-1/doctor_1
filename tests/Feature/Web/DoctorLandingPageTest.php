<?php

namespace Tests\Feature\Web;

use App\Enums\DayOfWeek;
use App\Enums\DoctorSex;
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

    public function test_it_lists_the_opening_hours_in_twelve_hour_form(): void
    {
        $schedule = $this->clinic->scheduleFor(DayOfWeek::SATURDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        $this->get($this->url())
            ->assertOk()
            ->assertSee(__('landing.hours'))
            ->assertSee(DayOfWeek::SATURDAY->label())
            // Crosses noon, so both meridiems are written.
            ->assertSee('9:00 '.__('schedule.am').' – 1:00 '.__('schedule.pm'))
            ->assertDontSee('09:00–13:00');
    }

    public function test_both_ends_of_a_period_name_their_meridiem(): void
    {
        $schedule = $this->clinic->scheduleFor(DayOfWeek::SUNDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '13:00', 'end_time' => '15:00']);

        // Even sharing a half of the day, the opening time says so itself —
        // "1:00 – 3:00 مساءً" leaves the first time merely implied.
        $this->get($this->url())
            ->assertOk()
            ->assertSee('1:00 '.__('schedule.pm').' – 3:00 '.__('schedule.pm'))
            ->assertDontSee('1:00 – 3:00 '.__('schedule.pm'));
    }

    public function test_a_split_day_shows_each_shift_as_its_own_tag(): void
    {
        $schedule = $this->clinic->scheduleFor(DayOfWeek::MONDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '13:00', 'end_time' => '15:00']);
        $schedule->periods()->create(['start_time' => '17:00', 'end_time' => '21:00']);

        $html = $this->get($this->url())->assertOk()->getContent();

        // Two separate tags, not one comma-joined string.
        $this->assertStringContainsString(
            '<bdi class="time-tag">1:00 '.__('schedule.pm').' – 3:00 '.__('schedule.pm').'</bdi>',
            $html,
        );
        $this->assertStringContainsString(
            '<bdi class="time-tag">5:00 '.__('schedule.pm').' – 9:00 '.__('schedule.pm').'</bdi>',
            $html,
        );
    }

    public function test_closed_days_are_shown_as_closed(): void
    {
        // The design lists the whole week, so a patient can see at a glance
        // which day the clinic does not open.
        $this->get($this->url())
            ->assertOk()
            ->assertSee(__('landing.hours'))
            ->assertSee(__('landing.closed'));
    }

    public function test_hidden_visit_types_are_not_advertised(): void
    {
        $visitTypes = $this->clinic->visitTypes()->active()->get();
        $hidden = $visitTypes->first();
        $stillShown = $visitTypes->last();

        $hidden->hide();

        // Matched on the service row itself: a visit type name can legitimately
        // appear elsewhere on the page, such as inside the meta description.
        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('<b>'.$hidden->name.'</b>', escape: false)
            ->assertSee('<b>'.$stillShown->name.'</b>', escape: false);
    }

    public function test_services_are_listed_as_names_only(): void
    {
        $visitType = $this->clinic->visitTypes()->active()->first();
        $visitType->update(['price' => 400, 'duration_minutes' => 35]);

        $html = $this->get($this->url())->assertOk()->getContent();

        $panel = $this->servicesPanel($html);

        $this->assertStringContainsString(
            '<div class="service-block"><b>'.$visitType->name.'</b></div>',
            $panel,
        );

        // Neither the fee nor the length belongs in this list. The visit length
        // still appears in the header stat row, which the design asks for.
        $this->assertStringNotContainsString(__('messages.currency'), $panel);
        $this->assertStringNotContainsString(__('landing.minutes', ['count' => 35]), $panel);
    }

    /** The services tab's markup, isolated from the rest of the page. */
    private function servicesPanel(string $html): string
    {
        $start = strpos($html, 'id="panel-services"');
        $end = strpos($html, 'id="panel-contact"');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    public function test_the_overview_no_longer_repeats_the_services(): void
    {
        $visitType = $this->clinic->visitTypes()->active()->first();

        $html = $this->get($this->url())->assertOk()->getContent();

        // It used to appear twice: once under أبرز الخدمات on the overview and
        // again on the services tab. That card is gone, so once is correct.
        $this->assertSame(
            1,
            substr_count($html, '<b>'.$visitType->name.'</b>'),
            'A service should be listed on the services tab only.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The redesigned page's own content
    |--------------------------------------------------------------------------
    */

    public function test_it_offers_every_tab_except_the_blog(): void
    {
        $page = $this->get($this->url())->assertOk();

        foreach (['overview', 'services', 'contact', 'location'] as $tab) {
            $page->assertSee(__('landing.tab_'.$tab));
        }
    }

    public function test_it_shows_the_doctors_title_bio_and_treatment_areas(): void
    {
        $doctor = $this->clinic->doctor;
        $doctor->update([
            'title' => 'أخصائية النساء والتوليد',
            'bio' => 'نبذة تجريبية عن الطبيبة.',
        ]);
        $doctor->treatmentAreas()->create([
            'title' => 'متابعة الحمل',
            'description' => 'متابعة دورية بالسونار.',
            'icon' => 'baby',
        ]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('أخصائية النساء والتوليد')
            ->assertSee('نبذة تجريبية عن الطبيبة.')
            ->assertSee(__('landing.treatment_areas'))
            ->assertSee('متابعة الحمل');
    }

    public function test_a_hidden_treatment_area_is_not_shown(): void
    {
        $this->clinic->doctor->treatmentAreas()->create([
            'title' => 'مجال متخفي',
            'is_active' => false,
        ]);

        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('مجال متخفي');
    }

    public function test_it_shows_the_clinic_photos_with_captions(): void
    {
        $this->clinic->photos()->create([
            'path' => 'clinics/reception.jpg',
            'caption' => 'الاستقبال',
        ]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee(__('landing.photos'))
            ->assertSee('الاستقبال');
    }

    public function test_the_working_days_stat_reads_as_a_range(): void
    {
        foreach ([DayOfWeek::SATURDAY, DayOfWeek::SUNDAY, DayOfWeek::MONDAY] as $day) {
            $this->clinic->scheduleFor($day)->update(['is_open' => true]);
        }

        // The Arabic article contracts: not "السبت للـالاثنين".
        $this->get($this->url())
            ->assertOk()
            ->assertSee('السبت للاثنين')
            ->assertDontSee('للـ');
    }

    public function test_a_clinic_with_no_city_falls_back_to_its_address(): void
    {
        $this->clinic->update(['city' => null]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee($this->clinic->address);
    }

    public function test_a_doctor_without_a_photo_gets_the_avatar_for_their_sex(): void
    {
        $this->clinic->doctor->update(['sex' => DoctorSex::FEMALE, 'photo_path' => null]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee(DoctorSex::FEMALE->avatarUrl(), escape: false)
            ->assertDontSee('<div class="portrait portrait-fallback"', escape: false);
    }

    public function test_a_doctor_with_no_sex_recorded_still_falls_back_to_an_initial(): void
    {
        $this->clinic->doctor->update(['sex' => null, 'photo_path' => null]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('<div class="portrait portrait-fallback"', escape: false);
    }

    /**
     * Two-up leaves the third fact stranded on a line of its own, and squeezes
     * a long specialty into four lines. A phone gets one per line.
     */
    public function test_the_three_facts_stack_on_a_phone(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('@media (max-width: 700px)', escape: false)
            ->assertSee('.stats { grid-template-columns: minmax(0, 1fr); }', escape: false);
    }

    /**
     * A scrollable strip that looks like a full one hides its last tabs
     * completely. The fades appear only on the side with more to show.
     */
    public function test_the_tab_strip_says_which_way_it_scrolls(): void
    {
        $html = $this->get($this->url())->assertOk()->getContent();

        foreach ([
            '.tabs-wrap[data-scroll~="start"]::before',
            '.tabs-wrap[data-scroll~="end"]::after',
            'function markOverflow()',
            // RTL counts scrollLeft down from zero, so magnitude is what works.
            'Math.abs(strip.scrollLeft)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_the_stock_avatar_is_never_used_as_the_share_image(): void
    {
        $this->clinic->doctor->update(['sex' => DoctorSex::MALE, 'photo_path' => null]);

        // Sharing a link should not put a cartoon in the preview card. The
        // platform cover goes instead — it carries the name and the promise,
        // which beats both a cartoon and no preview at all.
        // The avatar still shows on the page itself; what matters is what the
        // share card points at.
        $html = $this->get($this->url())->assertOk()->getContent();

        preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $shared);

        $this->assertNotEmpty($shared, 'The page offers no share image at all.');
        $this->assertStringNotContainsString('avatars/', $shared[1]);
        $this->assertStringContainsString(config('clinic.brand.cover'), $shared[1]);
    }

    public function test_the_header_carries_three_stats_without_the_visit_length(): void
    {
        // A stat with no value is skipped, so give the clinic an open day.
        $this->clinic->scheduleFor(DayOfWeek::SATURDAY)->update(['is_open' => true]);

        $page = $this->get($this->url())->assertOk();

        $page->assertSee(__('landing.stat_specialty'))
            ->assertSee(__('landing.stat_working_days'))
            ->assertSee(__('landing.stat_location'));

        // The clinic name used to repeat under the doctor's name; it does not now.
        $this->assertSame(
            1,
            substr_count($page->getContent(), '<span class="k">'.__('landing.stat_location').'</span>'),
        );

        // Exactly three tiles, and the visit length is not one of them.
        $this->assertSame(3, substr_count($page->getContent(), '<div class="stat">'));
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

    public function test_structured_data_cannot_break_out_of_its_script_tag(): void
    {
        $this->clinic->update([
            'name' => '</script><script>alert(1)</script>',
        ]);

        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('</script><script>alert(1)</script>', escape: false)
            ->assertSee('\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E', escape: false);
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
