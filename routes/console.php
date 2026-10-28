<?php

use Illuminate\Support\Facades\Schedule;

/*
| Runs hourly rather than once at midnight: each clinic is closed out when its
| own local day has rolled over, so clinics in different timezones stay correct
| without a schedule entry each. The command is idempotent.
*/
Schedule::command('clinic:close-day')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
| Every minute, because this one exists for the live slot grid rather than for
| correctness: an expired hold already stops blocking its slot on the next read
| (`expires_at` is a query filter), but nothing tells the browsers watching that
| day until this runs. Usually an indexed delete that finds nothing.
*/
Schedule::command('clinic:release-lapsed-holds')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
| The patient's reminders, two commands so each can be scheduled — or run by
| hand — without the other. Both run every minute so they land close to their
| moment and gate themselves on the clock; a run that finds nobody due is a
| few queries per clinic. Each reminder is sent once, so overlapping or
| missed runs are safe.
*/
Schedule::command('clinic:send-reminders')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('clinic:send-daily-reminders')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
| The doctors' morning WhatsApp report. One time for every clinic, in Cairo
| time; which clinics get it is decided per clinic in the admin panel. A
| missed morning is not caught up — the panel's "send report" covers it.
| Paused unless CLINIC_REPORTS_MORNING_WHATSAPP is on — read each morning,
| so switching it needs no deploy beyond the .env change.
*/
Schedule::command('clinic:send-reports')
    ->when(fn (): bool => (bool) config('clinic.reports.morning_whatsapp'))
    ->dailyAt(config('clinic.reports.send_at'))
    ->timezone(config('clinic.reports.timezone'))
    ->withoutOverlapping()
    ->runInBackground();
