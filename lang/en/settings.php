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
        'period_invalid' => 'The end time must be after the start time, or — for a period that runs past midnight — no later than :time the next morning',
        'period_overlap' => 'Two periods on the same day overlap',
        'overnight_clash' => 'The :day evening runs past midnight until :time, so no other period can start before then',
        'periods_required' => 'An open day needs at least one period',
        'branch_conflict' => 'The doctor is at :branch from :from to :to that day. Periods at different branches cannot overlap.',
        'short_gap' => 'Only :minutes minutes between this day and :branch (:from–:to). Make sure there is time to get there.',
        'bookings_outside' => '{1} One upcoming booking that day is now outside the new hours. Nothing moved it; please handle it.|[2,*] :count upcoming bookings that day are now outside the new hours. Nothing moved them; please handle them.',
    ],

    'display' => [
        'not_found' => 'This screen does not exist',
    ],

    'team' => [
        'list_loaded' => 'Team loaded',
        'updated' => 'Team member saved',
        'not_found' => 'This person is not on the clinic team',
        'is_owner' => 'The doctor works at every branch with every permission; there is nothing to change here',
        'branch_required' => 'Choose at least one branch; without one they cannot open the app',
    ],

    'branch' => [
        'list_loaded' => 'Branches loaded',
        'created' => 'Branch added',
        'updated' => 'Branch saved',
        'deactivated' => 'Branch deactivated. Its existing bookings carry on as usual.',
        'activated' => 'Branch activated',
        'not_found' => 'This branch does not exist',
        'duplicate_name' => 'A branch with this name already exists',
        'last_active' => 'At least one branch must stay active',
        'adding_disabled' => 'Adding a branch is not available yet',
        'map_link_unreadable' => 'We could not read a location from this link. Paste the full Google Maps link, or type the latitude and longitude.',
        'phone_invalid' => 'This phone number is not valid',
    ],

    'holiday' => [
        'range_invalid' => 'The end date must be on or after the start, and a range can be :days days at most',
        'branch_required' => 'Choose at least one of your branches',
        'list_loaded' => 'Holidays loaded',
        'created' => 'Holiday added',
        'deleted' => 'Holiday removed',
        'not_found' => 'That holiday does not exist',
        'already_exists' => 'This date is already marked as a holiday',
        'has_bookings' => 'There are :count bookings on this date. Postpone them first, or confirm adding the holiday.',
    ],
];
