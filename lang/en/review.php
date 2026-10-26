<?php

/*
| The patient-facing review page, opened from the visit-completed message.
*/

return [
    'title' => 'Rate your booking experience',
    'lead' => 'Your feedback helps us improve. It takes less than a minute.',
    'choose' => 'Choose a rating',
    'comment' => 'Your notes on the booking experience',
    'comment_optional' => '(optional)',
    'comment_required_mark' => '(required)',
    'comment_placeholder' => 'Anything else you would like to add',
    'comment_placeholder_required' => 'Tell us what we could do better',
    'submit' => 'Send feedback',

    'rating' => [
        'very_good' => 'Very good',
        'good' => 'Good',
        'bad' => 'I have some notes',
    ],

    // As the clinic reads them on its reviews screen.
    'staff_rating' => [
        'very_good' => 'Very good',
        'good' => 'Good',
        'bad' => 'Some notes',
    ],
    'list_loaded' => 'Reviews loaded',

    'thanks_title' => 'Thank you',
    'thanks_lead' => 'We have your feedback, and it helps us improve.',
    'your_rating' => 'Your rating',

    'not_done_title' => 'The visit is not finished yet',
    'not_done_lead' => 'You can rate it once the visit is complete.',
    'unavailable_title' => 'Nothing to rate',
    'unavailable_lead' => 'This booking was cancelled or not attended, so there is no visit to rate.',

    'rating_required' => 'Please choose a rating.',
    'comment_required' => 'Please write your notes, so we know what to improve.',
];
