<?php

return [
    'search_done' => 'Search complete',
    'history_loaded' => 'Patient file loaded',
    'lookup_done' => 'Patient lookup complete',
    'invalid_phone' => 'That phone number is not valid',
    'not_found' => 'That patient does not exist',

    // Phone verification on the public self-booking page.
    'otp' => [
        'sent' => 'We sent a confirmation code to your number',
        'verified' => 'Phone number confirmed',
        'invalid' => 'That code is not right — try again',
        'expired' => 'That code has expired — ask for a new one',
        'too_many_attempts' => 'Too many tries — ask for a new code',
        'cooldown' => 'Wait :seconds seconds before asking for another code',
        'rate_limited' => 'Too many requests — try again in a little while',
        'still_valid' => 'The code we sent you still works — enter it below',
        'send_failed' => 'We could not send the code just now — try again in a minute',
        'not_verified' => 'Confirm the phone number first',
    ],

    // Whether the patient may book on the public booking page.
    'online_booking' => [
        'title' => 'Online booking',
        'allowed' => 'Allowed',
        'blocked' => 'Blocked from online booking',
        'blocked_since' => 'Blocked since :date',
        'counted' => 'No-shows in the last :days days: :count of :threshold',
        'rule_off' => 'Automatic blocking is off for this clinic',
        'allow' => 'Allow online booking',
        'block' => 'Block online booking',
        'block_confirm' => 'Block this patient from booking online? The clinic can still book for them by phone.',
        'allowed_ok' => 'The patient may book online again',
        'blocked_ok' => 'The patient is blocked from online booking',
        'last_no_show' => 'Last no-show',
        'online_bookings' => 'Online bookings',
        'clinic_bookings' => 'Clinic bookings',
        'reason' => [
            'no_shows' => 'Repeated no-shows',
            'manual' => "The clinic's decision",
        ],
    ],
];
