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
