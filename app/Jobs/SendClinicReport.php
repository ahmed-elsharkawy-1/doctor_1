<?php

namespace App\Jobs;

use App\Models\MessageTemplate;
use App\Models\ReportDelivery;
use App\Services\Messaging\MessageSender;
use App\Services\Reports\ReportPeriod;
use App\Services\V1\Reports\ClinicPeriodReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Sends one report delivery: the headline numbers, and a button to the page.
 *
 * The numbers are worked out when it sends, from the same service the page
 * reads, so the message and the page it links to always agree.
 */
class SendClinicReport implements ShouldQueue
{
    use Queueable;

    public const TEMPLATE_KEY = 'clinic_report';

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  string  $value  the period as the page's URL names it: a date, a
     *                         week's Saturday, or a month (Y-m)
     */
    public function __construct(public readonly int $deliveryId, public readonly string $value) {}

    public function handle(MessageSender $sender, ClinicPeriodReportService $reports): void
    {
        $delivery = ReportDelivery::with(['clinic', 'user'])->find($this->deliveryId);

        // The clinic or the account was removed after this was queued.
        if ($delivery === null || $delivery->clinic === null || $delivery->user === null) {
            return;
        }

        $clinic = $delivery->clinic;

        try {
            $template = MessageTemplate::where('key', self::TEMPLATE_KEY)->first()
                ?? throw new RuntimeException('The clinic_report template does not exist.');

            $report = $reports->for($clinic, $this->period($delivery));

            $id = $sender->sendTemplate(
                (string) $delivery->user->phone,
                $template,
                [
                    $this->label($delivery),
                    $clinic->name,
                    (string) $report->completed['count'],
                    number_format($report->completed['income']).' '.__('messages.currency'),
                ],
                $delivery->period_type.'/'.$this->value,
            );
        } catch (Throwable $e) {
            $delivery->update(['status' => 'failed', 'error' => $e->getMessage()]);

            throw $e;
        }

        $delivery->update(['status' => 'sent', 'provider_message_id' => $id, 'sent_at' => now(), 'error' => null]);
    }

    private function period(ReportDelivery $delivery): ReportPeriod
    {
        $start = Carbon::parse($delivery->period_start->toDateString(), $delivery->clinic->timezone);
        $today = Carbon::now($delivery->clinic->timezone)->startOfDay();

        return match ($delivery->period_type) {
            'day' => ReportPeriod::forDay($start),
            'week' => ReportPeriod::forWeek($start, $today),
            'month' => ReportPeriod::forMonth($start, $today),
        };
    }

    /** "يوم السبت 3 أكتوبر", "الأسبوع 26 سبتمبر – 2 أكتوبر", "شهر سبتمبر 2026". */
    private function label(ReportDelivery $delivery): string
    {
        $period = $this->period($delivery);
        $from = $period->from->copy()->locale('ar');

        return match ($delivery->period_type) {
            'day' => __('reports.message.day', ['date' => $from->isoFormat('dddd D MMMM')]),
            'week' => __('reports.message.week', [
                'from' => $from->isoFormat('D MMMM'),
                'to' => $period->to->copy()->locale('ar')->isoFormat('D MMMM'),
            ]),
            'month' => __('reports.message.month', ['month' => $from->isoFormat('MMMM YYYY')]),
        };
    }
}
