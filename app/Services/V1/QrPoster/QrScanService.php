<?php

namespace App\Services\V1\QrPoster;

use App\Enums\BookingReferral;
use App\Models\Clinic;
use App\Models\ClinicQrScan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * How many people the poster brought, and which bookings they made.
 *
 * A visit counts when the clinic's page is opened from the poster's address,
 * once per browser session — a patient who reloads, or comes back to the page
 * after picking a time, is still one person. The same session mark is what
 * labels their booking: it is written by the server alone, so a patient can
 * not claim a referral they did not come through.
 */
class QrScanService
{
    private const SESSION_KEY = 'qr_poster.visited';

    /**
     * Counts this request if it came from the clinic's poster.
     */
    public function recordVisit(Clinic $clinic, Request $request): void
    {
        $tracking = config('clinic.qr_poster.tracking');

        if ($request->query($tracking['param']) !== $tracking['value'] || $this->isAutomated($request)) {
            return;
        }

        $visited = (array) $request->session()->get(self::SESSION_KEY, []);

        if (in_array($clinic->id, $visited, true)) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, [...$visited, $clinic->id]);

        $now = Carbon::now();

        // One statement, so two scans in the same instant both count.
        ClinicQrScan::query()->upsert(
            [[
                'clinic_id' => $clinic->id,
                'scan_date' => Carbon::now($clinic->timezone)->toDateString(),
                'scans' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['clinic_id', 'scan_date'],
            ['scans' => DB::raw('scans + 1'), 'updated_at' => $now],
        );
    }

    /**
     * What brought this browser to the clinic, if it was the poster.
     */
    public function referralFor(Clinic $clinic): ?BookingReferral
    {
        $visited = (array) session()->get(self::SESSION_KEY, []);

        return in_array($clinic->id, $visited, true) ? BookingReferral::QR : null;
    }

    /**
     * The poster's numbers, for the panel: all time, and the last 30 days of
     * the clinic's own calendar.
     *
     * @return array{scans: int, scans_30_days: int, bookings: int, bookings_30_days: int}
     */
    public function stats(Clinic $clinic): array
    {
        $since = Carbon::now($clinic->timezone)->subDays(29)->toDateString();
        $bookings = $clinic->bookings()->where('referral', BookingReferral::QR);

        return [
            'scans' => (int) $clinic->qrScans()->sum('scans'),
            'scans_30_days' => (int) $clinic->qrScans()->where('scan_date', '>=', $since)->sum('scans'),
            'bookings' => (clone $bookings)->count(),
            'bookings_30_days' => (clone $bookings)->where('created_at', '>=', Carbon::now()->subDays(30))->count(),
        ];
    }

    private function isAutomated(Request $request): bool
    {
        $agent = Str::lower((string) $request->userAgent());

        return $agent === '' || Str::contains($agent, config('clinic.qr_poster.tracking.ignored_user_agents'));
    }
}
