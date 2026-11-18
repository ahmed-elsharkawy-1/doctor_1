{{-- The OTP alarm mail. Plain and short: it is read on a phone, in a hurry. --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: Tahoma, Arial, sans-serif; line-height: 1.7; color: #111827; direction: rtl; text-align: right;">
@if ($recovered)
    <p>{{ __('otp_alarm.mail.recovered_lead', ['time' => $alarm->ended_at->format('Y-m-d H:i')]) }}</p>
    <p>{{ __('otp_alarm.mail.recovered_since', ['time' => $alarm->started_at->format('Y-m-d H:i')]) }}</p>
    <p>{{ __('otp_alarm.mail.recovered_check') }}</p>
@else
    <p><strong>{{ __('otp_alarm.mail.lead') }}</strong></p>
    <ul>
        <li>{{ __('otp_alarm.mail.since', ['time' => $alarm->started_at->format('Y-m-d H:i')]) }}</li>
        <li>{{ __('otp_alarm.mail.counts', ['codes' => $alarm->codes, 'phones' => $alarm->phones]) }}</li>
        @if ($alarm->refused > 0)
            <li>{{ __('otp_alarm.mail.refused', ['count' => $alarm->refused]) }}</li>
        @endif
        @foreach ($alarm->details['clinics'] ?? [] as $clinic)
            <li>{{ __('otp_alarm.mail.clinic', ['name' => $clinic['name'], 'codes' => $clinic['codes']]) }}</li>
        @endforeach
        @if (! empty($alarm->details['last_typed_at']))
            <li>{{ __('otp_alarm.mail.last_typed', ['time' => \Illuminate\Support\Carbon::parse($alarm->details['last_typed_at'])->format('Y-m-d H:i')]) }}</li>
        @endif
    </ul>
    <p>{{ __('otp_alarm.mail.what_to_do') }}</p>
    <p><a href="{{ $settingsUrl }}">{{ __('otp_alarm.mail.open_settings') }}</a></p>
    <p style="color: #6b7280; font-size: 0.9em;">{{ __('otp_alarm.mail.footer') }}</p>
@endif
</body>
</html>
