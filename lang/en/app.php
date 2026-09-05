<?php

/*
| The clinic web app. Patient-facing strings live in booking.tracking.
*/

return [
    'root' => [
        'title' => 'Clinic booking system',
        'lead' => 'Each clinic has its own page. If you are clinic staff, sign in here.',
        'sign_in' => 'Clinic sign in',
    ],

    'login' => [
        'title' => 'Clinic sign in',
        'lead' => 'Sign in with the clinic email',
        'email' => 'Clinic email',
        'password' => 'Password',
        'remember' => 'Keep me signed in',
        'submit' => 'Sign in',
    ],

    'queue' => [
        'title' => "Today's list",
        'today' => 'Today',
        'previous_day' => 'Previous day',
        'next_day' => 'Next day',
        'empty' => 'No bookings on this day',
        'total' => 'Total',
        'waiting' => 'Waiting',
        'done' => 'Seen',
        'sign_out' => 'Sign out',
        'no_time' => 'No time',
    ],

    'booking' => [
        'title' => 'New booking',
        'new' => 'New booking',
        'back_to_queue' => "Today's list",
        'patient' => 'Patient',
        'search_patient' => 'Search by name, code or phone',
        'new_patient' => 'New patient',
        'change_patient' => 'Change patient',
        'patient_name' => 'Name',
        'phone' => 'Phone',
        'age' => 'Age',
        'whatsapp_opt_in' => 'Happy to receive WhatsApp',
        'visit_type' => 'Visit type',
        'kind' => 'Booking type',
        'patient_location' => 'Patient location',
        'day' => 'Day',
        'slot' => 'Time',
        'no_slots' => 'No times available on this day',
        'closed' => 'Clinic closed',
        'notes' => 'Notes',
        'save' => 'Save booking',
        'visits' => 'visits',
        'emergency_note' => 'An emergency has no time slot — it goes to the front of today’s queue',
        'tracking_ready' => 'Tracking link ready for the patient',
    ],

    'actions' => [
        'arrive' => 'Arrived',
        'call_in' => 'Call in',
        'complete' => 'Complete',
        'no_show' => 'No show',
        'cancel' => 'Cancel',
        'cancel_prompt' => 'Reason for cancelling?',
        'dismiss' => 'Back',
        'call' => 'Call',
        'copy_link' => 'Copy tracking link',
        'link_copied' => 'Link copied',
    ],
];
