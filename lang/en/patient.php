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
];
