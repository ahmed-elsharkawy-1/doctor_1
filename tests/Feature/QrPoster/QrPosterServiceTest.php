<?php

namespace Tests\Feature\QrPoster;

use App\Models\ClinicQrPoster;
use App\Services\V1\QrPoster\QrPosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * What a clinic's poster says, what the operator's adjustments store, and how
 * it is laid out.
 */
class QrPosterServiceTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const LONG_TITLE = 'استشارى أمراض النسا والتوليد والحقن المجهرى والمناظير';

    private QrPosterService $posters;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['slug' => 'dr-seham']);
        $this->clinic->doctor->update(['name' => 'د. سهام عبدالعزيز', 'title' => 'استشارى أمراض النسا والتوليد']);

        $this->posters = app(QrPosterService::class);
    }

    public function test_the_code_opens_the_clinic_page_marked_as_coming_from_the_poster(): void
    {
        $this->assertSame(url('/dr-seham').'?src=qr', $this->posters->url($this->clinic));
        $this->assertSame(url('/dr-seham').'?src=qr', $this->posters->build($this->clinic)->url);
    }

    public function test_it_carries_the_platform_slogan(): void
    {
        config(['clinic.brand.slogan' => 'شعار للتجربة']);

        $this->assertSame('شعار للتجربة', $this->posters->build($this->clinic)->slogan);
    }

    /** The patient pages' own action blue, so the poster matches the page it opens. */
    public function test_it_uses_the_patient_pages_blue(): void
    {
        $this->assertSame('#185FA5', $this->posters->build($this->clinic)->colors['background']);
    }

    public function test_an_unadjusted_poster_shows_the_doctor(): void
    {
        $poster = $this->posters->build($this->clinic);

        $this->assertSame('د. سهام عبدالعزيز', $poster->name);
        $this->assertSame(['استشارى أمراض النسا والتوليد'], array_column($poster->titleLines, 'text'));
        $this->assertSame(
            explode("\n", __('qr_poster.call_to_action', [], 'ar')),
            array_column($poster->callToActionLines, 'text'),
        );
    }

    public function test_without_a_title_the_specialty_is_shown(): void
    {
        $this->clinic->doctor->update(['title' => null]);

        $poster = $this->posters->build($this->clinic->fresh());

        $this->assertSame([$this->clinic->specialty->name_ar], array_column($poster->titleLines, 'text'));
    }

    public function test_an_unadjusted_poster_follows_the_doctor(): void
    {
        $this->clinic->doctor->update(['name' => 'د. منى سالم']);

        $this->assertSame('د. منى سالم', $this->posters->build($this->clinic->fresh())->name);
    }

    public function test_an_adjustment_wins_over_the_doctor(): void
    {
        $this->posters->save($this->clinic, ['name' => 'د. سهام']);

        $this->clinic->doctor->update(['name' => 'د. منى سالم']);

        $this->assertSame('د. سهام', $this->posters->build($this->clinic->fresh())->name);
    }

    public function test_only_what_differs_from_the_default_is_stored(): void
    {
        $settings = $this->posters->settings($this->clinic);

        $this->posters->save($this->clinic, [...$settings, 'name' => 'د. سهام', 'qr_size' => 1200]);

        $row = ClinicQrPoster::firstOrFail();

        $this->assertSame('د. سهام', $row->name);
        $this->assertSame(1200, $row->qr_size);
        // Left as they came, so they keep following the doctor and config.
        $this->assertNull($row->title);
        $this->assertNull($row->call_to_action);
        $this->assertNull($row->name_size);
        $this->assertNull($row->title_size);
    }

    public function test_saving_the_defaults_stores_nothing(): void
    {
        $this->posters->save($this->clinic, $this->posters->settings($this->clinic));

        $this->assertDatabaseCount('clinic_qr_posters', 0);
    }

    public function test_an_empty_field_means_the_default(): void
    {
        $this->posters->save($this->clinic, ['name' => 'د. سهام']);
        $this->posters->save($this->clinic, ['name' => '   ']);

        $this->assertDatabaseCount('clinic_qr_posters', 0);
        $this->assertSame('د. سهام عبدالعزيز', $this->posters->build($this->clinic)->name);
    }

    public function test_sizes_stay_inside_their_limits(): void
    {
        $this->posters->save($this->clinic, ['name_size' => 9999, 'title_size' => 1, 'qr_size' => -5]);

        $row = ClinicQrPoster::firstOrFail();

        $this->assertSame(config('clinic.qr_poster.sizes.name.max'), $row->name_size);
        $this->assertSame(config('clinic.qr_poster.sizes.title.min'), $row->title_size);
        $this->assertSame(config('clinic.qr_poster.sizes.qr.min'), $row->qr_size);
    }

    public function test_reset_goes_back_to_the_defaults(): void
    {
        $this->posters->save($this->clinic, ['name' => 'د. سهام', 'qr_size' => 1200]);

        $this->posters->reset($this->clinic);

        $this->assertDatabaseCount('clinic_qr_posters', 0);
        $this->assertSame($this->posters->defaults($this->clinic), $this->posters->settings($this->clinic));
    }

    public function test_a_draft_is_previewed_without_being_saved(): void
    {
        $poster = $this->posters->build($this->clinic, ['name' => 'د. سهام', 'name_size' => 9999]);

        $this->assertSame('د. سهام', $poster->name);
        $this->assertSame(config('clinic.qr_poster.sizes.name.max'), $poster->nameSize);
        $this->assertDatabaseCount('clinic_qr_posters', 0);
    }

    public function test_a_long_title_is_split_evenly_at_word_boundaries(): void
    {
        $poster = $this->posters->build($this->clinic, ['title' => self::LONG_TITLE]);

        $lines = array_column($poster->titleLines, 'text');

        $this->assertCount(2, $lines);
        $this->assertSame(self::LONG_TITLE, implode(' ', $lines));
        $this->assertSame('استشارى أمراض النسا والتوليد', $lines[0]);
    }

    public function test_the_operators_own_line_breaks_win(): void
    {
        $poster = $this->posters->build($this->clinic, ['title' => "استشارى أمراض النسا\n  والتوليد والحقن المجهرى والمناظير  \n\n"]);

        $this->assertSame(
            ['استشارى أمراض النسا', 'والتوليد والحقن المجهرى والمناظير'],
            array_column($poster->titleLines, 'text'),
        );
    }

    public function test_extra_lines_join_the_last_one(): void
    {
        config(['clinic.qr_poster.title_max_lines' => 2]);

        $poster = $this->posters->build($this->clinic, ['title' => "أ\nب\nج\nد"]);

        $this->assertSame(['أ', 'ب ج د'], array_column($poster->titleLines, 'text'));
    }

    public function test_the_name_is_always_one_line(): void
    {
        $poster = $this->posters->build($this->clinic, ['name' => "د. سهام\nعبدالعزيز"]);

        $this->assertSame('د. سهام عبدالعزيز', $poster->name);
    }

    public function test_lines_are_drawn_top_to_bottom_without_overlapping(): void
    {
        $poster = $this->posters->build($this->clinic, ['title' => self::LONG_TITLE]);

        $titleYs = array_column($poster->titleLines, 'y');
        $callToActionYs = array_column($poster->callToActionLines, 'y');

        $this->assertLessThan($titleYs[0], $poster->nameY);
        $this->assertLessThan($titleYs[1], $titleYs[0]);
        $this->assertLessThan($poster->qr['y'], end($titleYs));
        $this->assertLessThan($callToActionYs[0], $poster->qr['y'] + $poster->qr['size']);
        $this->assertLessThan($poster->logoY, end($callToActionYs));
        $this->assertLessThan($poster->sloganY, $poster->logoY + $poster->logoHeight);
        $this->assertLessThanOrEqual($poster->height, $poster->sloganY);
    }

    /** A big title and a big code never collide: the code gives way. */
    public function test_the_code_shrinks_rather_than_overlap(): void
    {
        $max = config('clinic.qr_poster.sizes');

        $poster = $this->posters->build($this->clinic, [
            'title' => "سطر أول طويل\nسطر ثان طويل\nسطر ثالث طويل",
            'name_size' => $max['name']['max'],
            'title_size' => $max['title']['max'],
            'qr_size' => $max['qr']['max'],
        ]);

        $titleYs = array_column($poster->titleLines, 'y');
        $callToActionYs = array_column($poster->callToActionLines, 'y');

        $this->assertLessThan($max['qr']['max'], $poster->qr['size']);
        $this->assertLessThan($poster->qr['y'], end($titleYs));
        $this->assertLessThan($poster->logoY, end($callToActionYs));
    }

    public function test_the_code_is_centred_and_never_wider_than_the_text_area(): void
    {
        $poster = $this->posters->build($this->clinic);

        $this->assertSame($poster->width, 2 * $poster->qr['x'] + $poster->qr['size']);
        $this->assertLessThanOrEqual($poster->textWidth, $poster->qr['size']);
    }

    /** The white tile around the code is its quiet zone, and scanners need one. */
    public function test_the_code_keeps_a_quiet_zone(): void
    {
        $qr = $this->posters->build($this->clinic)->qr;

        $this->assertGreaterThanOrEqual(3 * $qr['moduleSize'], $qr['padding']);
        $this->assertEqualsWithDelta($qr['size'], 2 * $qr['padding'] + $qr['modules'] * $qr['moduleSize'], 0.01);
        $this->assertNotSame('', $qr['path']);
    }

    public function test_the_poster_embeds_everything_it_draws(): void
    {
        $poster = $this->posters->build($this->clinic);

        $this->assertStringStartsWith('data:image/png;base64,', $poster->logo);
        $this->assertStringContainsString('data:font/woff2;base64,', $this->posters->fontFaces());
        $this->assertSame('qr-poster-dr-seham.png', $poster->fileName);
    }
}
