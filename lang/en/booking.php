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

    'kind' => [
        'normal' => 'Normal',
        'emergency' => 'Emergency',
    ],

    'patient_location' => [
        'inside_clinic' => 'Patient inside clinic',
        'on_way' => 'Patient on the way',
    ],

    /*
    | The patient-facing tracking page.
    */
    'tracking' => [
        'patient_name' => 'Patient',
        'time' => 'Time',
        'date' => 'Date',
        'booking_type' => 'Booking type',
        'title' => 'Your booking at :clinic',
        'queue_title' => 'Your place in the queue',
        'waiting_count' => 'Ahead of you',
        'total' => 'Total',
        'normal' => 'Normal cases',
        'emergency' => 'Emergency',
        'emergency_notice' => 'Emergency cases always take priority, so the number ahead of you may rise while you wait',
        'your_status' => 'Your status',
        'expected_at' => 'Be at the clinic by',
        'call_clinic' => 'Call the clinic',
        'patient_code' => 'Patient code',
        'appointment' => 'Appointment',
        'your_turn' => "It's your turn",
        'first_in_line' => "You're first",
        'first_in_line_note' => 'Your appointment is at :time. Please arrive :lead beforehand.',
        'first_in_line_note_no_time' => 'Nobody is ahead of you in the queue right now.',
        'your_turn_note' => 'Please go through to the consulting room',
        'done' => 'Visit complete',
        'done_note' => 'Thank you for your visit',
        'cancelled' => 'Booking cancelled',
        'cancelled_note' => 'Please contact the clinic to book a new appointment',
        'no_show' => 'Marked as not attended',
        'no_show_note' => 'Please contact the clinic to book a new appointment',
        'not_today' => 'Your appointment is coming up',
        'not_today_note' => 'The waiting count appears on the day of your booking',
        'am' => 'AM',
        'pm' => 'PM',
    ],
];
