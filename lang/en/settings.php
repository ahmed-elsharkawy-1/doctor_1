<?php

return [
    'bootstrap_loaded' => 'Clinic settings loaded',
    'general_updated' => 'Settings saved',

    'visit_type' => [
        'list_loaded' => 'Visit types loaded',
        'created' => 'Visit type added',
        'updated' => 'Visit type updated',
        'hidden' => 'Visit type hidden',
        'not_found' => 'That visit type does not exist',
        'duplicate_name' => 'A visit type with this name already exists',
        'last_active' => 'You cannot hide the last visit type — at least one is needed to make bookings',
    ],

    'schedule' => [
        'loaded' => 'Working hours loaded',
        'updated' => 'Day saved',
        'day_not_found' => 'That day is not part of the clinic schedule',
        'period_invalid' => 'The end time must be after the start time',
        'period_overlap' => 'Two periods on the same day overlap',
        'periods_required' => 'An open day needs at least one period',
    ],

    'holiday' => [
        'list_loaded' => 'Holidays loaded',
        'created' => 'Holiday added',
        'deleted' => 'Holiday removed',
        'not_found' => 'That holiday does not exist',
        'already_exists' => 'This date is already marked as a holiday',
        'has_bookings' => 'There are :count bookings on this date. Postpone them first, or confirm adding the holiday.',
    ],
];
