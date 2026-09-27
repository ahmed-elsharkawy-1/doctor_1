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
