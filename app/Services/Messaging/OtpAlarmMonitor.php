<?php

namespace App\Services\Messaging;

use App\Mail\OtpAlarmMail;
use App\Models\Clinic;
use App\Models\MessageTemplate;
use App\Models\OtpAlarm;
use App\Models\PhoneVerification;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Notices when verification codes stop reaching patients.
 *
 * The provider is no help here. Twice in a month its codes stopped arriving,
 * and on 17 November 2026 its portal showed every one of them "Delivered". It
 * took patient complaints, hours later, to find out. The one thing a silent
 * failure cannot fake is a patient typing the code. So: codes to several
 * numbers, and nobody typing any of them, raises the alarm.
 *
 * Codes the provider refused outright are counted too. Those patients saw an
 * error rather than silence, but nobody else did.
 *
 * One alarm at a time. It tells the admins by email and WhatsApp when it
 * opens, and once more when a code is typed again, never in between. Each
 * channel stands alone: one that fails is tried again on the next check
 * without repeating the other.
 */
class OtpAlarmMonitor
{
    public const STARTED_TEMPLATE = 'otp_alarm_started';

    public const RECOVERED_TEMPLATE = 'otp_alarm_recovered';

    public function __construct(private readonly MessageSender $whatsapp) {}

    /** The alarm open after this check, if any. */
    public function check(): ?OtpAlarm
    {
        $open = OtpAlarm::current();

        if ($open !== null) {
            return $this->follow($open);
        }

        if (! config('clinic.otp_alarm.enabled')) {
            return null;
        }

        return $this->detect();
    }

    private function detect(): ?OtpAlarm
    {
        $now = Carbon::now();
        $from = $now->copy()->subMinutes((int) config('clinic.otp_alarm.window_minutes'));
        $until = $now->copy()->subMinutes((int) config('clinic.otp_alarm.grace_minutes'));

        // A code typed at any point in the window means codes are getting
        // through, whenever it was sent.
        if (PhoneVerification::query()->where('verified_at', '>=', $from)->exists()) {
            return null;
        }

        $codes = PhoneVerification::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $until)
            ->whereNull('verified_at')
            ->orderBy('created_at')
            ->get(['clinic_id', 'phone', 'sent_at', 'created_at']);

        $phones = $codes->pluck('phone')->unique()->count();

        if ($codes->count() < (int) config('clinic.otp_alarm.min_codes')
            || $phones < (int) config('clinic.otp_alarm.min_phones')) {
            return null;
        }

        $names = Clinic::query()->whereIn('id', $codes->pluck('clinic_id')->unique())->pluck('name', 'id');

        $alarm = OtpAlarm::create([
            'started_at' => $codes->first()->created_at,
            'codes' => $codes->count(),
            'phones' => $phones,
            'refused' => $codes->whereNull('sent_at')->count(),
            'details' => [
                'clinics' => $codes->countBy('clinic_id')
                    ->map(fn (int $count, int $id): array => ['name' => $names[$id] ?? '#'.$id, 'codes' => $count])
                    ->values()
                    ->all(),
                'last_typed_at' => PhoneVerification::query()->max('verified_at'),
            ],
        ]);

        Log::critical('Verification codes are not reaching patients.', [
            'alarm' => $alarm->id,
            'since' => $alarm->started_at->toDateTimeString(),
            'codes' => $alarm->codes,
            'phones' => $alarm->phones,
            'refused' => $alarm->refused,
        ]);

        $this->notify($alarm);

        return $alarm;
    }

    /** An open alarm closes on the first code typed after it started. */
    private function follow(OtpAlarm $alarm): ?OtpAlarm
    {
        $typed = PhoneVerification::query()
            ->where('verified_at', '>', $alarm->started_at)
            ->min('verified_at');

        if ($typed === null) {
            // A channel that failed, or had no address when it opened.
            $this->notify($alarm);

            return $alarm;
        }

        $alarm->update(['ended_at' => Carbon::parse($typed)]);

        Log::info('Verification codes are reaching patients again.', ['alarm' => $alarm->id]);

        // Only a channel that said it broke is told it is fixed.
        $emailed = $alarm->emailed_at !== null && $this->email($alarm, recovered: true);
        $whatsapped = $alarm->whatsapped_at !== null && $this->whatsApp($alarm, recovered: true);

        if ($emailed || $whatsapped) {
            $alarm->update(['recovery_sent_at' => Carbon::now()]);
        }

        return null;
    }

    private function notify(OtpAlarm $alarm): void
    {
        if ($alarm->emailed_at === null && $this->email($alarm, recovered: false)) {
            $alarm->update(['emailed_at' => Carbon::now()]);
        }

        if ($alarm->whatsapped_at === null && $this->whatsApp($alarm, recovered: false)) {
            $alarm->update(['whatsapped_at' => Carbon::now()]);
        }
    }

    /** Whether the mail went. A failure is logged and tried again next check. */
    private function email(OtpAlarm $alarm, bool $recovered): bool
    {
        $to = $this->emails();

        if ($to === []) {
            return false;
        }

        try {
            Mail::to($to)->send(new OtpAlarmMail($alarm, $recovered));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }

    /**
     * Whether it reached at least one number. Not every number: retrying
     * would message again the ones it already reached.
     */
    private function whatsApp(OtpAlarm $alarm, bool $recovered): bool
    {
        $numbers = $this->whatsAppNumbers();

        $template = $numbers === [] ? null : MessageTemplate::query()
            ->where('key', $recovered ? self::RECOVERED_TEMPLATE : self::STARTED_TEMPLATE)
            ->where('is_active', true)
            ->first();

        if ($template === null) {
            return false;
        }

        // Only the time: the message stays short, and the email has the rest.
        $variables = [$this->time($recovered ? $alarm->ended_at : $alarm->started_at)];

        $reached = false;

        foreach ($numbers as $number) {
            try {
                $this->whatsapp->sendTemplate($number, $template, $variables, null);
                $reached = true;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $reached;
    }

    /** "17/11 الساعة 08:15" — Cairo wall clock, as the app stores it. */
    private function time(Carbon $at): string
    {
        return $at->format('d/m').' الساعة '.$at->format('H:i');
    }

    /** @return list<string> */
    public function emails(): array
    {
        return self::split((string) config('clinic.otp_alarm.emails'));
    }

    /**
     * In international form; anything that is not a phone number is left
     * out (the settings page refuses those anyway).
     *
     * @return list<string>
     */
    public function whatsAppNumbers(): array
    {
        $numbers = [];

        foreach (self::split((string) config('clinic.otp_alarm.whatsapp_numbers')) as $input) {
            try {
                $numbers[] = PhoneNumber::parse($input)->e164;
            } catch (InvalidArgumentException) {
                // Skipped.
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * A list typed into one settings field: commas (Arabic ones too),
     * semicolons or new lines between items. Spaces inside a number are fine;
     * the phone parser ignores them.
     *
     * @return list<string>
     */
    public static function split(string $list): array
    {
        $items = preg_split('/[,;،\n]+/u', $list) ?: [];

        return array_values(array_unique(array_filter(
            array_map(trim(...), $items),
        )));
    }
}
