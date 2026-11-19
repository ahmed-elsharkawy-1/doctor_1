<?php

/*
| The privacy policy at /privacy?lang=en. Mirrors lang/ar/privacy.php; keep
| the two in step.
*/

return [
    'meta' => [
        'title' => 'Privacy Policy — :brand',
        'description' => 'What data :brand collects, why, who sees it, and how we protect it.',
    ],

    'title' => 'Privacy Policy',
    'updated' => 'Last updated: 19 November 2026',
    'switch_language' => 'العربية',

    'intro' => 'Thank you for using :brand. We are a booking and management system for clinics: patients book a visit, follow their turn, and receive confirmations and reminders. This policy explains what data we collect, why we collect it, who sees it, and how we protect it. By using the app or the website, you agree to it.',

    'sections' => [
        'collect' => [
            'title' => 'Information we collect',
            'lead' => 'We collect only what a clinic needs to run its appointments:',
            'items' => [
                'Patient details: name and mobile number, and age if entered.',
                'Booking details: date, time, visit type and its price, status, arrival and called-in times, and any note the clinic writes on the booking.',
                'Your replies to WhatsApp messages, such as confirming or cancelling a visit, and your consent to receive WhatsApp messages.',
                'Your rating and comment on a visit, if you choose to leave one.',
                'When you book online: the mobile number the verification code is sent to, and your device’s IP address, to prevent abuse. The code is stored encrypted and expires within minutes.',
                'Clinic team details (doctors and secretaries): name, email, mobile number, role and language. Passwords are stored encrypted.',
                'When you sign in: a session cookie, your IP address and browser type, to keep you signed in securely.',
            ],
        ],

        'not_collect' => [
            'title' => 'What we do not collect',
            'items' => [
                'We do not access your location, contacts or photos.',
                'We do not collect bank card details, and no payments are made through the app.',
                'We do not use third-party advertising, tracking or analytics tools.',
                'We do not store diagnoses or medical records; the system handles appointments, not medical files.',
            ],
        ],

        'use' => [
            'title' => 'How we use data',
            'items' => [
                'To record bookings and run the clinic’s waiting queue.',
                'To send confirmations, reminders, turn alerts, a link to follow your booking, and a review request.',
                'To verify your mobile number with a code before an online booking is confirmed.',
                'To prepare daily reports for the doctor about their clinic’s appointments.',
                'To protect the service from fake bookings and abuse.',
            ],
        ],

        'access' => [
            'title' => 'Who sees your data',
            'items' => [
                'Only the clinic you booked with. Each clinic sees its own patients and nothing from any other clinic.',
                'Inside the clinic, each person sees only what their role needs.',
                'Your booking-tracking and review links are personal; do not share them.',
            ],
        ],

        'share' => [
            'title' => 'Sharing with third parties',
            'lead' => 'We do not sell your data or use it for advertising. We send it only to the services that run the system, and only as much as they need:',
            'items' => [
                'WhatsApp (Meta): to deliver booking messages to your number.',
                'Our SMS provider: to deliver verification codes.',
                'Our hosting provider: where the database is stored.',
                'Authorities: only when the law requires it.',
            ],
        ],

        'security' => [
            'title' => 'How we protect data',
            'items' => [
                'All traffic to the website and app is encrypted over HTTPS.',
                'Passwords and verification codes are stored encrypted and cannot be read by anyone.',
                'Each clinic’s data is kept separate, and every user has permissions based on their role.',
            ],
        ],

        'retention' => [
            'title' => 'How long we keep data',
            'items' => [
                'We keep patient and booking data for as long as the clinic uses the system, because it is the clinic’s appointment record.',
                'If a clinic stops using the system and its account is deleted, its patients’ and bookings’ data is deleted with it.',
            ],
        ],

        'rights' => [
            'title' => 'Your rights',
            'items' => [
                'To know what data we hold about you.',
                'To ask for your data to be corrected or deleted.',
                'To ask us to stop WhatsApp messages at any time.',
            ],
            'how' => 'To make a request, contact the clinic you booked with, or reach us directly using the details below.',
        ],

        'children' => [
            'title' => 'Children',
            'items' => [
                'When the patient is a child, a parent or guardian books for them and enters their own mobile number.',
            ],
        ],

        'changes' => [
            'title' => 'Changes to this policy',
            'items' => [
                'We may update this policy when the service changes. The date of the last update is shown at the top of this page.',
            ],
        ],
    ],

    'contact' => [
        'title' => 'Contact us',
        'lead' => 'For any question about this policy or your data:',
        'email' => 'Email',
        'whatsapp' => 'WhatsApp',
        'phone' => 'Phone',
    ],

    'back_home' => 'Home',
];
