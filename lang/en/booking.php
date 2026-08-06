<?php

return [
    'days_loaded' => 'Available days loaded',
    'slots_loaded' => 'Time slots loaded',
    'created' => 'Booking saved',
    'updated' => 'Booking updated',
    'loaded' => 'Booking loaded',

    'not_found' => 'That booking does not exist',
    'not_editable' => 'A booking with status ":status" cannot be edited',
    'slot_unavailable' => 'That time is already booked',
    'slot_outside_hours' => 'That time is outside the clinic hours for this visit type',
    'clinic_closed' => 'The clinic is closed that day',
    'visit_type_inactive' => 'That visit type is hidden, please pick another',
    'no_doctor' => 'This clinic does not have a doctor yet',

    'closed_reason' => [
        'weekly_closed' => 'The clinic is closed that day',
        'holiday' => 'That day is a holiday',
        'outside_window' => 'That day is outside the booking window',
    ],
    'status' => [
        'booked' => 'Booked',
        'arrived' => 'Waiting',
        'with_doctor' => 'With doctor',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
    ],

    'cancel_reason' => [
        'patient_cancelled' => 'Cancelled by patient',
        'no_show' => 'No show',
        'emergency' => 'Emergency',
        'incomplete' => 'Incomplete',
    ],
];
