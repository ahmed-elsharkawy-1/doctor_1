<?php

namespace Tests\Feature\QrPoster;

use App\Enums\BookingReferral;
use App\Enums\DayOfWeek;
use App\Livewire\Patient\BookVisit;
use App\Models\Booking;
use App\Models\ClinicQrScan;
use App\Services\V1\Booking\PatientBookingService;
use App\Services\V1\QrPoster\QrScanService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * Counting the people the poster brings, and labelling the bookings they make.
 */
class QrScanTrackingTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    private const PHONE_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->clinic->update([
            'slug' => 'dr-sara',
            'timezone' => 'Africa/Cairo',
            'self_booking_enabled' => true,
            'patient_booking_window_days' => 3,
        ]);

        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $schedule->update(['is_open' => true]);

        if ($schedule->periods()->count() === 0) {
            $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);
        }

        config(['clinic.self_booking.require_otp' => false]);

        $this->withHeader('User-Agent', self::PHONE_AGENT);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function scans(): int
    {
        return (int) ClinicQrScan::query()->where('clinic_id', $this->clinic->id)->sum('scans');
    }

    public function test_opening_the_page_from_the_poster_counts(): void
    {
        $this->get('/dr-sara?src=qr')->assertOk();

        $this->assertSame(1, $this->scans());
    }

    public function test_opening_the_page_any_other_way_does_not(): void
    {
        $this->get('/dr-sara')->assertOk();
        $this->get('/dr-sara?src=facebook')->assertOk();

        $this->assertSame(0, $this->scans());
    }

    public function test_one_person_counts_once_however_often_they_reload(): void
    {
        $this->get('/dr-sara?src=qr');
        $this->get('/dr-sara?src=qr');
        $this->get('/dr-sara?src=qr');

        $this->assertSame(1, $this->scans());
    }

    public function test_each_new_person_counts(): void
    {
        $this->get('/dr-sara?src=qr');

        $this->flushSession();
        $this->get('/dr-sara?src=qr');

        $this->assertSame(2, $this->scans());
    }

    public function test_link_previews_and_crawlers_are_not_people(): void
    {
        $this->withHeader('User-Agent', 'WhatsApp/2.23.20.0 A')->get('/dr-sara?src=qr');
        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1)')->get('/dr-sara?src=qr');
        $this->withHeader('User-Agent', '')->get('/dr-sara?src=qr');

        $this->assertSame(0, $this->scans());
    }

    public function test_scans_are_counted_on_the_clinics_own_day(): void
    {
        // 23:30 in Cairo is still the 3rd there, whatever the server's clock says.
        Carbon::setTestNow(Carbon::parse(self::DAY.' 23:30:00', 'Africa/Cairo'));
        $this->get('/dr-sara?src=qr');

        Carbon::setTestNow(Carbon::parse('2026-09-04 00:30:00', 'Africa/Cairo'));
        $this->flushSession();
        $this->get('/dr-sara?src=qr');

        $this->assertSame(
            ['2026-09-03' => 1, '2026-09-04' => 1],
            ClinicQrScan::query()->orderBy('scan_date')->get()
                ->mapWithKeys(fn (ClinicQrScan $row) => [$row->scan_date->toDateString() => $row->scans])
                ->all(),
        );
    }

    public function test_a_deactivated_clinic_counts_nothing(): void
    {
        $this->clinic->update(['is_active' => false]);

        $this->get('/dr-sara?src=qr')->assertNotFound();

        $this->assertSame(0, $this->scans());
    }

    public function test_a_booking_made_after_scanning_is_labelled_as_from_the_poster(): void
    {
        $this->get('/dr-sara?src=qr')->assertOk();

        $this->book();

        $this->assertSame(BookingReferral::QR, Booking::firstOrFail()->referral);
    }

    public function test_a_booking_made_without_scanning_carries_no_label(): void
    {
        $this->get('/dr-sara')->assertOk();

        $this->book();

        $this->assertNull(Booking::firstOrFail()->referral);
    }

    /** A scan at one clinic says nothing about a booking at another. */
    public function test_the_label_belongs_to_the_clinic_that_was_scanned(): void
    {
        $other = $this->otherClinic();
        $other->update(['slug' => 'dr-other']);
        $this->clinic->doctor->replicate()->fill(['clinic_id' => $other->id])->save();

        $this->get('/dr-other?src=qr')->assertOk();

        $this->assertNull(app(QrScanService::class)->referralFor($this->clinic));
        $this->assertSame(BookingReferral::QR, app(QrScanService::class)->referralFor($other));
    }

    public function test_the_poster_numbers(): void
    {
        $this->get('/dr-sara?src=qr');
        $this->book();

        $this->flushSession();
        $this->get('/dr-sara?src=qr');

        // A month and a half ago: in the totals, not in the last 30 days.
        ClinicQrScan::query()->create(['clinic_id' => $this->clinic->id, 'scan_date' => '2026-07-15', 'scans' => 5]);

        $this->assertSame(
            ['scans' => 7, 'scans_30_days' => 2, 'bookings' => 1, 'bookings_30_days' => 1],
            app(QrScanService::class)->stats($this->clinic),
        );
    }

    private function book(): void
    {
        $visitType = $this->clinic->visitTypes()->selfBookable()->active()->first();
        $slot = collect(app(PatientBookingService::class)->availability($this->clinic, $visitType, self::DAY)->slots)
            ->first(fn ($slot) => $slot->isAvailable);

        Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', '01012225521')
            ->call('sendCode')
            ->call('selectSlot', $slot->startAt->format('H:i'))
            ->call('confirm')
            ->assertSet('failed', false);
    }
}
