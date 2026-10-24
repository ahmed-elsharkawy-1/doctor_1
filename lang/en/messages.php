<?php

return [
    'currency' => 'EGP',
    'am' => 'AM',
    'pm' => 'PM',
    'loaded' => 'Loaded',
    'sent' => 'Message queued',

    'validation_failed' => 'Please review the submitted data',
    'not_found' => 'The requested item was not found',
    'whatsapp_disabled' => 'WhatsApp is switched off for this clinic, so messages cannot be sent.',
    'request_failed' => 'The request could not be completed',
    'server_error' => 'Something went wrong, please try again',
    'minutes' => ':count minutes',
    'reminder_follow_up' => [
        'confirmed' => "Your attendance is confirmed ✅ See you today.\n\nYou can follow your booking and place in the queue here:\n:link",
        'cancelled' => "Your booking has been cancelled.\n\nIf that was a mistake or you need another appointment, call the clinic:\n📞 :phone",
    ],
    'fallback' => [
        'patient' => 'our patient',
        'phone' => 'the clinic number',
        'no_time' => 'no set time',
    ],
];
