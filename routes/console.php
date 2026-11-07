<?php

use Illuminate\Support\Facades\Schedule;

/*
| Every job below takes a lock so two runs never overlap. A run killed before
| it lets go — a deploy restarting the scheduler mid-run — would otherwise
| keep that lock, and so stop the job, for the default 24 hours: on
| 7 November 2026 it held back Dr Seham's morning reminder. Each lock now
| lapses after a few minutes, longer than any honest run takes, and every
| deploy clears them (deploy.yml, `schedule:clear-cache`).
*/

/*
| Runs hourly rather than once at midnight: each clinic is closed out when its
| own local day has rolled over, so clinics in different timezones stay correct
| without a schedule entry each. The command is idempotent.
*/
Schedule::command('clinic:close-day')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();

/*
| Every minute, because this one exists for the live slot grid rather than for
| correctness: an expired hold already stops blocking its slot on the next read
| (`expires_at` is a query filter), but nothing tells the browsers watching that
| day until this runs. Usually an indexed delete that finds nothing.
*/
Schedule::command('clinic:release-lapsed-holds')
    ->everyMinute()
    ->withoutOverlapping(10)
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
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('clinic:send-daily-reminders')
    ->everyMinute()
    ->withoutOverlapping(10)
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
    ->withoutOverlapping(30)
    ->runInBackground();

/*
| Waiting lists close when each clinic's own day ends, not at a fixed hour,
| so this runs often and gates itself on each clinic's schedule. A run with
| nobody on any list is one query.
*/
Schedule::command('clinic:close-waiting-lists')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();
