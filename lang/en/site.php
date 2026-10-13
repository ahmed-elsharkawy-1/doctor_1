<?php

/*
| The platform's landing page. Mirrors lang/ar/site.php key for key; the page
| is served in Arabic.
*/

return [
    'meta' => [
        'title' => ':brand — clinic booking and management',
        'description' => 'Booking and management for clinics in Egypt: clash-free bookings, a live queue patients follow from their phone, WhatsApp confirmations and a daily report for the doctor.',
    ],

    'nav' => [
        'features' => 'Features',
        'roles' => 'For your team',
        'screens' => 'Screens',
        'faq' => 'FAQ',
        'contact' => 'Contact',
        'login' => 'Clinic sign-in',
        'demo' => 'Request a demo',
        'menu' => 'Menu',
    ],

    'hero' => [
        'eyebrow' => 'Clinic booking and management for Egypt',
        'title' => 'Running your clinic is easier than you think',
        'title_highlight' => 'easier',
        'lead' => 'Clash-free bookings, a queue patients follow from their phone, WhatsApp confirmations and a daily report for the doctor — one system, in Arabic, built for how clinics here work.',
        'cta' => 'Request a free demo',
        'whatsapp' => 'Chat on WhatsApp',
        'see_how' => 'See how it works',
        'points' => [
            'Fully Arabic',
            'Works from a phone',
            'We set your clinic up with you',
        ],
        'card_queue_title' => 'Your turn is close',
        'card_queue_body' => '3 patients ahead of you',
        'card_confirm_title' => 'Booking confirmed',
        'card_confirm_body' => 'Sent to you on WhatsApp',
    ],

    'features' => [
        'eyebrow' => 'Features',
        'title' => 'Everything your clinic needs, in one place',
        'lead' => 'Every problem you know from your clinic has a clear answer in the system.',
        'problem_label' => 'The problem',
        'solution_label' => 'The answer',
        'items' => [
            [
                'problem' => 'A phone that never stops',
                'title' => 'Patients book themselves from your page',
                'body' => 'A page in the doctor\'s name with hours, services and address, where patients book after confirming their number — so fewer calls reach the secretary.',
                'tags' => ['Clinic page', 'Online booking'],
            ],
            [
                'problem' => 'A booking book that is hard to follow',
                'title' => 'Organised, clash-free bookings',
                'body' => 'The secretary books in seconds, the system never double-books a time, and each patient\'s visits sit in one file by mobile number.',
                'tags' => ['Clash-free booking', 'Patient file'],
            ],
            [
                'problem' => 'A crowded waiting room',
                'title' => 'Patients know their turn from their phone',
                'body' => 'A WhatsApp confirmation with every booking, carrying a link that shows how many are ahead and when to come.',
                'tags' => ['Live queue', 'WhatsApp confirmation'],
            ],
            [
                'problem' => 'A day that ends without numbers',
                'title' => 'A report reaches the doctor every morning',
                'body' => 'Visits, new patients, no-shows and income on WhatsApp — with day, week and month detail.',
                'tags' => ['Daily report', 'Compared with last period'],
            ],
        ],
    ],

    'roles' => [
        'eyebrow' => 'For your team',
        'title' => 'Built for everyone in the clinic',
        'preview' => [
            'doctor' => [
                'greeting' => 'Good morning, Dr. Sara',
                'subtitle' => 'Yesterday\'s report',
                'visits_label' => 'Visits done',
                'visits' => '18',
                'income_label' => 'Income',
                'income' => 'EGP 4,500',
                'week' => 'Last 7 days',
                'change' => '+12% on last week',
            ],
            'secretary' => [
                'title' => 'Today\'s queue',
                'count' => '12 bookings',
                'rows' => [
                    ['time' => '10:00', 'name' => 'Ahmed Mahmoud', 'status' => 'with_doctor'],
                    ['time' => '10:20', 'name' => 'Mona Ali', 'status' => 'arrived'],
                    ['time' => '10:40', 'name' => 'Karim Hassan', 'status' => 'booked'],
                    ['time' => '11:00', 'name' => 'Salma Youssef', 'status' => 'booked'],
                ],
                'new_booking' => 'New booking',
            ],
            'patient' => [
                'title' => 'Your place in the queue',
                'ahead' => '3',
                'ahead_label' => 'ahead',
                'eta' => 'Come at about 11:30 AM',
                'wa_title' => 'Booking confirmed',
                'wa_body' => 'Thursday 11:00 AM — Dr. Sara Ahmed',
            ],
        ],
        'tabs' => [
            'doctor' => [
                'label' => 'Doctor',
                'title' => 'Know how your clinic is doing without asking',
                'items' => [
                    'A report every morning on WhatsApp',
                    'Day, week and month on one page',
                    'Compared with the previous period, new patients by name',
                    'A professional page in your name for patients to share',
                ],
            ],
            'secretary' => [
                'label' => 'Secretary',
                'title' => 'A calmer day, fewer calls',
                'items' => [
                    'Quick booking by name and mobile',
                    'The whole day\'s queue on one screen',
                    'Each patient\'s status in a tap: arrived, in, done',
                    'A list of who needs rebooking when a day is postponed',
                ],
            ],
            'patient' => [
                'label' => 'Patient',
                'title' => 'Easy booking, less waiting',
                'items' => [
                    'Books from the doctor\'s page with no app to install',
                    'Gets the confirmation on WhatsApp',
                    'Knows their turn before leaving home',
                    'Rates the visit afterwards',
                ],
            ],
        ],
    ],

    'screens' => [
        'eyebrow' => 'Screens',
        'title' => 'The system as its users see it',
        'lead' => 'Real screens from the system, from a test clinic.',
        'items' => [
            'doctor_page' => 'Doctor\'s page',
            'booking' => 'Online booking',
            'tracking' => 'Following the queue',
            'report' => 'Doctor\'s report',
        ],
        'notes' => [
            'doctor_page' => 'A page in the doctor\'s name to book from',
            'booking' => 'The nearest free times at a glance',
            'tracking' => 'Patients see how many are ahead',
            'report' => 'The clinic\'s numbers by day, week and month',
        ],
    ],

    'faq' => [
        'eyebrow' => 'FAQ',
        'title' => 'What doctors usually ask',
        'help_title' => 'Didn\'t find your answer?',
        'help_body' => 'Ask us directly and we will get back to you soon.',
        'help_cta' => 'Contact us',
        'items' => [
            ['q' => 'Do patients need to install an app?', 'a' => 'No. Patients book from the doctor\'s page on any phone and follow their turn from a link sent on WhatsApp.'],
            ['q' => 'How do we start? Who sets the clinic up?', 'a' => 'We do. We set up working hours, visit types, prices and your team\'s accounts with you, and you start straight away.'],
            ['q' => 'Does the secretary need training?', 'a' => 'It is simple and in Arabic, and we stay with your team for the first days.'],
            ['q' => 'What do I need to use it?', 'a' => 'Just a phone or a device with internet. No special hardware, nothing to install.'],
            ['q' => 'Is online booking required?', 'a' => 'No. Bookings can stay with the secretary only, and online booking is switched on when you want it.'],
            ['q' => 'Who sees my patients\' data?', 'a' => 'Only your clinic\'s team. Each clinic\'s data is fully separate.'],
            ['q' => 'How much does it cost?', 'a' => 'It depends on your clinic. Request a demo and we will send you the details.'],
        ],
    ],

    'contact' => [
        'eyebrow' => 'Contact',
        'title' => 'Request a free demo',
        'lead' => 'Reach us however suits you, and we will show you the system and set it up for your clinic.',
        'whatsapp' => 'WhatsApp',
        'whatsapp_hint' => 'Fastest reply',
        'phone' => 'Call us',
        'phone_hint' => 'Talk to us directly',
        'email' => 'Email',
        'email_hint' => 'Write to us any time',
        'whatsapp_action' => 'Start a chat',
        'phone_action' => 'Call now',
        'email_action' => 'Send an email',
        'copy_number' => 'Copy number',
        'copy_email' => 'Copy email',
        'copied' => 'Copied',
        'whatsapp_greeting' => 'Hello, I would like to try the system in my clinic.',
    ],

    'footer' => [
        'rights' => '© :year :brand. All rights reserved.',
        'links' => 'Links',
        'reach' => 'Reach us',
    ],
];
