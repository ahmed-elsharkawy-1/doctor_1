<?php

return [
    'arrived' => 'Patient checked in',
    'called_in' => 'Patient called in',
    'completed' => 'Visit completed',
    'cancelled_ok' => 'Booking cancelled',
    'status_updated' => 'Booking status updated',
    'not_cancellable' => 'A booking with status ":status" cannot be cancelled',
    'invalid_transition' => 'A booking cannot move from ":from" straight to ":to"',
    'postpone_candidates_loaded' => 'Affected patients loaded',
    'postponed' => ':count bookings postponed',
    'nothing_to_postpone' => 'There are no bookings to postpone on that day',
    'rebooking_list_loaded' => 'Rebooking list loaded',
    'marked_contacted' => 'Marked as contacted',
    'not_awaiting_rebooking' => 'That booking is not on the rebooking list',
    'days_loaded' => 'Available days loaded',
    'calendar_loaded' => 'Booking calendar loaded',
    'home_loaded' => 'Home loaded',
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
        'no_show' => 'No show',
    ],

    'cancel_reason' => [
        'patient_cancelled' => 'Cancelled by patient',
        'emergency' => 'Emergency',
        'incomplete' => 'Incomplete',
    ],
];
