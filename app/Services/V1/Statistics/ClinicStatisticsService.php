<?php

namespace App\Services\V1\Statistics;

use App\Enums\BookingKind;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\DayOfWeek;
use App\Enums\PatientResponse;
use App\Enums\PatientResponseSource;
use App\Enums\ReviewRating;
use App\Models\Booking;
use App\Models\BookingLinkOpen;
use App\Models\BookingReview;
use App\Models\Clinic;
use App\Models\ClinicSchedule;
use App\Models\ClinicSchedulePeriod;
use App\Models\OutboundMessage;
use App\Services\Results\V1\Statistics\ClinicStatisticsResult;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the admin panel's Clinic Statistics page: evidence the
 * team shows a doctor, so every figure is for one clinic only and nothing is
 * filled in where the data is missing.
 *
 * The range is a span of the clinic's own calendar days. Booking metrics
 * select by visit date; outside-hours bookings by when they were made;
 * ratings by when they were submitted.
 *
 * Timestamps come in two kinds. created_at, sent_at and opened_at are the
 * application's clock (config app.timezone — Africa/Cairo in production, not
 * UTC). Every time the clinic's own day is written in — start_at, arrived_at,
 * called_in_at, cancelled_at, submitted_at — is the clinic's wall time,
 * stored as is. The two agree for a Cairo clinic, but comparisons between
 * them still go through wall() / appTime() so a clinic elsewhere stays right.
 *
 * A card's `state` is `ok`, `insufficient` (fewer than min_sample) or
 * `no_data` (nothing to measure at all).
 */
class ClinicStatisticsService
{
    /** Statuses at which a WhatsApp message reached the patient's phone. */
    private const DELIVERED = ['delivered', 'read'];

    /** Reminder sent, whatever happened to it after. Marks the suite as active. */
    private const SENT = ['sent', 'delivered', 'read'];

    private Clinic $clinic;

    private Carbon $from;

    private Carbon $to;

    public function for(Clinic $clinic, Carbon $from, Carbon $to): ClinicStatisticsResult
    {
        $this->clinic = $clinic;
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->startOfDay();

        $activation = $this->activationDate();

        return new ClinicStatisticsResult(
            clinicId: $clinic->id,
            clinicName: $clinic->name,
            from: $this->from->toDateString(),
            to: $this->to->toDateString(),
            minSample: $this->minSample(),
            cards: [
                'no_show' => $this->noShow($activation),
                'outside_hours' => $this->outsideHours(),
                'rebooked_slots' => $this->rebookedSlots(),
                'waiting_time' => $this->waitingTime(),
                'link_opens' => $this->linkOpens(),
                'on_time' => $this->onTime(),
                'whatsapp' => $this->whatsApp(),
                'satisfaction' => $this->satisfaction(),
            ],
            comments: $this->topComments(),
        );
    }

    /**
     * The No-Show suite's start: the day of this clinic's first reminder that
     * actually went out, in the clinic's calendar. Null when none ever did.
     */
    public function activationDate(?Clinic $clinic = null): ?Carbon
    {
        $clinic ??= $this->clinic;

        $first = OutboundMessage::query()
            ->where('clinic_id', $clinic->id)
            ->whereIn('template_key', [
                WhatsAppMessagingService::REMINDER_KEY,
                WhatsAppMessagingService::DAILY_REMINDER_KEY,
            ])
            ->whereIn('status', self::SENT)
            ->whereNotNull('sent_at')
            ->min('sent_at');

        return $first === null
            ? null
            : Carbon::parse($first, self::appTimezone())->setTimezone($clinic->timezone)->startOfDay();
    }

    /*
    |--------------------------------------------------------------------------
    | 1. No-show rate, before and after activation
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function noShow(?Carbon $activation): array
    {
        $rows = $this->bookingsInRange()
            ->whereIn('status', [BookingStatus::DONE, BookingStatus::NO_SHOW])
            ->get(['visit_date', 'status', 'price']);

        $activationDay = $activation?->toDateString();

        [$beforeRows, $afterRows] = $activationDay === null
            ? [$rows, collect()]
            : $rows->partition(fn (Booking $b) => $b->visit_date->toDateString() < $activationDay);

        $before = $beforeRows->isEmpty() ? null : $this->noShowSide($beforeRows);
        $after = $activationDay === null ? null : $this->noShowSide($afterRows);

        $change = $before !== null && $after !== null && $before['rate'] !== null && $after['rate'] !== null
            ? round($after['rate'] - $before['rate'], 1)
            : null;

        $avgFee = $afterRows->avg('price');

        return [
            'state' => match (true) {
                $activationDay === null => $before === null ? 'no_data' : $this->stateFor($before['visits']),
                default => $this->stateFor($after['visits']),
            },
            'sample' => $rows->count(),
            'activation_date' => $activationDay,
            'activated' => $activationDay !== null,
            'no_baseline' => $activationDay !== null && $before === null,
            'before' => $before,
            'after' => $after,
            'change_points' => $change,
            'avg_visit_fee' => $avgFee === null ? null : round((float) $avgFee, 2),
            // What the drop is worth: visits that would have been missed at the
            // old rate, at the clinic's average fee. Negative if it got worse.
            'recovered_revenue' => $change === null || $avgFee === null
                ? null
                : round(-$change / 100 * $after['visits'] * (float) $avgFee, 2),
        ];
    }

    /**
     * @param  Collection<int, Booking>  $rows
     * @return array{visits: int, no_shows: int, rate: ?float, state: string}
     */
    private function noShowSide(Collection $rows): array
    {
        $noShows = $rows->where('status', BookingStatus::NO_SHOW)->count();

        return [
            'visits' => $rows->count(),
            'no_shows' => $noShows,
            'rate' => $this->percent($noShows, $rows->count()),
            'state' => $this->stateFor($rows->count()),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Bookings made outside working hours or on holidays
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function outsideHours(): array
    {
        $tz = $this->clinic->timezone;

        $created = Booking::query()
            ->forClinic($this->clinic->id)
            ->whereBetween('created_at', [
                // The clinic's days, written in the application's clock, which
                // is what created_at holds. Formatted here: the query builder
                // writes a Carbon's own wall time and ignores its zone.
                Carbon::parse($this->from->toDateString(), $tz)->startOfDay()->setTimezone(self::appTimezone())->format('Y-m-d H:i:s'),
                Carbon::parse($this->to->toDateString(), $tz)->endOfDay()->setTimezone(self::appTimezone())->format('Y-m-d H:i:s'),
            ])
            ->pluck('created_at');

        /** @var Collection<int, ClinicSchedule> $schedules */
        $schedules = $this->clinic->schedules()->with('periods')->get()
            ->keyBy(fn (ClinicSchedule $schedule) => $schedule->day_of_week->value);

        $holidays = $this->clinic->holidays()
            ->whereDate('date', '>=', $this->from->copy()->subDay()->toDateString())
            ->whereDate('date', '<=', $this->to->toDateString())
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();

        $onHoliday = 0;
        $outside = 0;

        foreach ($created as $createdAt) {
            $local = $this->appTime($createdAt)->setTimezone($tz);

            if ($holidays->has($local->toDateString())) {
                $onHoliday++;
            } elseif (! $this->isOpenAt($local, $schedules, $holidays)) {
                $outside++;
            }
        }

        $total = $created->count();

        return [
            'state' => $total === 0 ? 'no_data' : $this->stateFor($total),
            'sample' => $total,
            'count' => $onHoliday + $outside,
            'outside_hours' => $outside,
            'on_holidays' => $onHoliday,
            'percent' => $this->percent($onHoliday + $outside, $total),
        ];
    }

    /**
     * Whether a period of that day — or an evening of the day before that
     * ran past midnight — covers the moment. Today's weekly pattern is used
     * for every date, as the doctor's report does.
     *
     * @param  Collection<int, ClinicSchedule>  $schedules
     * @param  Collection<string, int>  $holidays
     */
    private function isOpenAt(Carbon $local, Collection $schedules, Collection $holidays): bool
    {
        foreach ([$local->copy()->startOfDay(), $local->copy()->startOfDay()->subDay()] as $day) {
            if ($holidays->has($day->toDateString())) {
                continue;
            }

            $schedule = $schedules->get(DayOfWeek::fromDate($day)->value);

            if ($schedule === null || ! $schedule->is_open) {
                continue;
            }

            foreach ($schedule->periods as $period) {
                /** @var ClinicSchedulePeriod $period */
                [$opens, $closes] = $period->boundsOn($day, $local->getTimezone()->getName());

                if ($local->greaterThanOrEqualTo($opens) && $local->lessThan($closes)) {
                    return true;
                }
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Cancelled slots filled again before their time
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function rebookedSlots(): array
    {
        // A slot freed before its time — not a day the clinic itself cancelled,
        // which nobody can be booked into.
        $cancelled = $this->bookingsInRange()
            ->where('status', BookingStatus::CANCELLED)
            ->where(fn ($q) => $q->whereNull('cancel_reason')->orWhere('cancel_reason', '!=', CancelReason::EMERGENCY))
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->whereNotNull('cancelled_at')
            ->whereColumn('cancelled_at', '<', 'start_at')
            ->get(['id', 'patient_id', 'start_at', 'end_at', 'cancelled_at']);

        $candidates = Booking::query()
            ->forClinic($this->clinic->id)
            ->whereBetween('visit_date', [
                $this->from->copy()->subDay()->toDateString(),
                $this->to->copy()->addDay()->toDateString(),
            ])
            ->where('status', '!=', BookingStatus::CANCELLED)
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->get(['id', 'patient_id', 'start_at', 'end_at', 'created_at'])
            ->each(fn (Booking $b) => $b->setAttribute('created_wall', $this->wall($b->created_at)));

        $minutes = [];

        foreach ($cancelled as $slot) {
            $fill = $candidates
                ->filter(fn (Booking $b) => $b->patient_id !== $slot->patient_id
                    && $b->start_at->lessThan($slot->end_at)
                    && $b->end_at->greaterThan($slot->start_at)
                    && $b->created_wall->greaterThan($slot->cancelled_at)
                    && $b->created_wall->lessThan($slot->start_at))
                ->sortBy('created_wall')
                ->first();

            if ($fill !== null) {
                $minutes[] = (int) $slot->cancelled_at->diffInMinutes($fill->created_wall, true);
            }
        }

        $total = $cancelled->count();

        return [
            'state' => $total === 0 ? 'no_data' : $this->stateFor($total),
            'sample' => $total,
            'cancelled' => $total,
            'refilled' => count($minutes),
            'fill_rate' => $this->percent(count($minutes), $total),
            'avg_minutes_to_refill' => $this->average($minutes),
            'median_minutes_to_refill' => $this->median($minutes),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Waiting time: check-in to the doctor calling the patient in
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function waitingTime(): array
    {
        $seen = $this->bookingsInRange()
            ->whereIn('status', [BookingStatus::WITH_DOCTOR, BookingStatus::DONE])
            ->get(['arrived_at', 'called_in_at']);

        // A visit without either time cannot say how long anyone waited.
        $minutes = $seen
            ->filter(fn (Booking $b) => $b->arrived_at !== null
                && $b->called_in_at !== null
                && $b->called_in_at->greaterThanOrEqualTo($b->arrived_at))
            ->map(fn (Booking $b) => (int) $b->arrived_at->diffInMinutes($b->called_in_at, true))
            ->values()
            ->all();

        return [
            'state' => $minutes === [] ? 'no_data' : $this->stateFor(count($minutes)),
            'sample' => count($minutes),
            'excluded' => $seen->count() - count($minutes),
            'avg_minutes' => $this->average($minutes),
            'median_minutes' => $this->median($minutes),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Queue link open rate
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function linkOpens(): array
    {
        // The tracking start is recorded at migration time. Using the first
        // observed open would incorrectly exclude bookings made after release
        // but before the first patient opened their link.
        $since = DB::table('booking_link_tracking_starts')
            ->where('metric', 'queue_link_open')
            ->value('started_at');

        if ($since === null) {
            return ['state' => 'no_data', 'sample' => 0, 'opened' => 0, 'percent' => null, 'tracked_since' => null];
        }

        $bookings = $this->bookingsInRange()
            ->where('status', '!=', BookingStatus::CANCELLED)
            ->where('created_at', '>=', $since);

        $total = (clone $bookings)->count();
        $opened = (clone $bookings)
            ->whereIn('id', BookingLinkOpen::query()->where('clinic_id', $this->clinic->id)->select('booking_id'))
            ->count();

        return [
            'state' => $total === 0 ? 'no_data' : $this->stateFor($total),
            'sample' => $total,
            'opened' => $opened,
            'percent' => $this->percent($opened, $total),
            'tracked_since' => Carbon::parse($since, self::appTimezone())->setTimezone($this->clinic->timezone)->toDateString(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 7. On-time arrival
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function onTime(): array
    {
        $tolerance = (int) config('clinic.statistics.on_time_tolerance_minutes');

        $arrivals = $this->bookingsInRange()
            ->where('booking_kind', BookingKind::NORMAL)
            ->whereNotNull('start_at')
            ->whereNotNull('arrived_at')
            ->get(['start_at', 'arrived_at']);

        $onTime = $arrivals
            ->filter(fn (Booking $b) => $b->arrived_at->lessThanOrEqualTo($b->start_at->copy()->addMinutes($tolerance)))
            ->count();

        $total = $arrivals->count();

        return [
            'state' => $total === 0 ? 'no_data' : $this->stateFor($total),
            'sample' => $total,
            'on_time' => $onTime,
            'percent' => $this->percent($onTime, $total),
            'tolerance_minutes' => $tolerance,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 8. WhatsApp response rate
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function whatsApp(): array
    {
        // The visit-day reminder is the one with Confirm and Cancel buttons.
        // Failed, queued and skipped messages never reached the patient.
        $bookingIds = OutboundMessage::query()
            ->where('clinic_id', $this->clinic->id)
            ->where('template_key', WhatsAppMessagingService::DAILY_REMINDER_KEY)
            ->whereIn('status', self::DELIVERED)
            ->whereIn('booking_id', $this->bookingsInRange()->select('id'))
            ->distinct()
            ->pluck('booking_id');

        $responses = Booking::query()
            ->whereIn('id', $bookingIds)
            ->where('patient_response_source', PatientResponseSource::WHATSAPP)
            ->selectRaw('patient_response, count(*) as aggregate')
            ->groupBy('patient_response')
            ->pluck('aggregate', 'patient_response');

        $total = $bookingIds->count();
        $confirmed = (int) ($responses[PatientResponse::CONFIRMED->value] ?? 0);
        $cancelled = (int) ($responses[PatientResponse::CANCELLED->value] ?? 0);

        return [
            'state' => $total === 0 ? 'no_data' : $this->stateFor($total),
            'sample' => $total,
            'responded' => $confirmed + $cancelled,
            'percent' => $this->percent($confirmed + $cancelled, $total),
            'confirmed' => $confirmed,
            'confirmed_percent' => $this->percent($confirmed, $total),
            'cancelled' => $cancelled,
            'cancelled_percent' => $this->percent($cancelled, $total),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 9. Satisfaction, on the review page's own three levels
    |--------------------------------------------------------------------------
    */

    /** @return array<string, mixed> */
    private function satisfaction(): array
    {
        $counts = $this->reviewsInRange()
            ->selectRaw('rating, count(*) as aggregate')
            ->groupBy('rating')
            ->pluck('aggregate', 'rating');

        $total = (int) $counts->sum();

        $levels = [];

        foreach (ReviewRating::ordered() as $rating) {
            $count = (int) ($counts[$rating->value] ?? 0);
            $levels[$rating->value] = ['count' => $count, 'percent' => $this->percent($count, $total)];
        }

        $positive = $levels[ReviewRating::VERY_GOOD->value]['count'] + $levels[ReviewRating::GOOD->value]['count'];

        return [
            'state' => $total === 0 ? 'no_data' : $this->stateFor($total),
            'sample' => $total,
            'positive' => $positive,
            'positive_percent' => $this->percent($positive, $total),
            'levels' => $levels,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 10. Latest positive comments, anonymised
    |--------------------------------------------------------------------------
    */

    /** @return list<array{rating: string, date: string, comment: string}> */
    private function topComments(): array
    {
        return $this->reviewsInRange()
            ->whereIn('rating', [ReviewRating::VERY_GOOD->value, ReviewRating::GOOD->value])
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->with('patient:id,name,phone')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit((int) config('clinic.statistics.top_comments'))
            ->get()
            ->map(fn (BookingReview $review) => [
                'rating' => $review->rating->value,
                'date' => $review->submitted_at->toDateString(),
                'comment' => self::anonymise(
                    (string) $review->comment,
                    $review->patient?->name,
                    $review->patient?->phone,
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * Patients sometimes sign a comment or leave a number in it. Phone-like
     * digit runs and the reviewer's own name are masked before display.
     */
    public static function anonymise(string $comment, ?string $name, ?string $phone = null): string
    {
        $comment = preg_replace('/\+?[\d٠-٩][\d٠-٩\s\-]{5,}[\d٠-٩]/u', '•••', $comment) ?? $comment;

        $phone = trim((string) $phone);

        if ($phone !== '') {
            $comment = preg_replace('/'.preg_quote($phone, '/').'/u', '•••', $comment) ?? $comment;
        }

        $name = trim((string) $name);

        if ($name === '') {
            return trim($comment);
        }

        $parts = array_filter(
            [$name, ...preg_split('/\s+/u', $name)],
            fn (string $part) => $part !== '',
        );

        foreach ($parts as $part) {
            $comment = preg_replace(
                '/(?<![\pL\pN])'.preg_quote($part, '/').'(?![\pL\pN])/iu',
                '•••',
                $comment,
            ) ?? $comment;
        }

        return trim($comment);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** @return Builder<Booking> */
    private function bookingsInRange()
    {
        return Booking::query()
            ->forClinic($this->clinic->id)
            ->whereBetween('visit_date', [$this->from->toDateString(), $this->to->toDateString()]);
    }

    /** @return Builder<BookingReview> */
    private function reviewsInRange()
    {
        return BookingReview::query()
            ->where('clinic_id', $this->clinic->id)
            ->whereBetween('submitted_at', [
                $this->from->toDateString().' 00:00:00',
                $this->to->toDateString().' 23:59:59',
            ]);
    }

    /**
     * An application-clock timestamp (created_at, sent_at) as the clinic's
     * wall time, in the same form as the clinic-time columns, so the two can
     * be compared directly.
     */
    private function wall(Carbon|string|null $at): Carbon
    {
        return Carbon::parse(
            $this->appTime($at)->setTimezone($this->clinic->timezone)->format('Y-m-d H:i:s'),
        );
    }

    /**
     * The moment an application-clock value stands for. Read from its digits,
     * never from the zone a Carbon happens to carry, so a cast value and a raw
     * string give the same answer.
     */
    private function appTime(Carbon|string|null $at): Carbon
    {
        $digits = $at instanceof Carbon ? $at->format('Y-m-d H:i:s') : (string) $at;

        return Carbon::parse($digits, self::appTimezone());
    }

    private static function appTimezone(): string
    {
        return (string) config('app.timezone');
    }

    private function minSample(): int
    {
        return max(1, (int) config('clinic.statistics.min_sample'));
    }

    private function stateFor(int $sample): string
    {
        return $sample >= $this->minSample() ? 'ok' : 'insufficient';
    }

    /** A percentage, or null when the sample is too small to mean anything. */
    private function percent(int $hits, int $total): ?float
    {
        return $total >= $this->minSample() ? round($hits / $total * 100, 1) : null;
    }

    /** @param  list<int>  $values */
    private function average(array $values): ?float
    {
        return count($values) >= $this->minSample() ? round(array_sum($values) / count($values), 1) : null;
    }

    /** @param  list<int>  $values */
    private function median(array $values): ?float
    {
        if (count($values) < $this->minSample()) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (float) $values[$middle]
            : round(($values[$middle - 1] + $values[$middle]) / 2, 1);
    }
}
