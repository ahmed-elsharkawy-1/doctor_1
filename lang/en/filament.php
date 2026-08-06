<?php

return [
    'nav' => [
        'platform' => 'Platform',
        'reports' => 'Reports',
    ],

    'common' => [
        'is_active' => 'Active',
        'sort_order' => 'Order',
        'created_at' => 'Created',
    ],

    'specialty' => [
        'label' => 'Specialty',
        'plural_label' => 'Specialties',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'slug' => 'Slug',
        'default_visit_types' => 'Default visit types',
        'clinics' => 'Clinics',
        'section' => [
            'details' => 'Specialty details',
            'default_visit_types' => 'Default visit types',
            'default_visit_types_hint' => 'A starting point for new clinics of this specialty only — once created, each clinic edits its own visit types freely.',
        ],
    ],

    'clinic' => [
        'label' => 'Clinic',
        'plural_label' => 'Clinics',
        'name' => 'Clinic name',
        'specialty' => 'Specialty',
        'specialty_hint' => 'The specialty seeds the clinic’s visit types, so it cannot change after creation.',
        'address' => 'Address',
        'phone' => 'Phone',
        'timezone' => 'Timezone',
        'country_code' => 'Country',
        'booking_window_days' => 'Booking window (days)',
        'first_visit_only_days' => 'First-visit-only threshold (days)',
        'first_visit_only_days_hint' => 'A patient with a single visit older than this counts as never returned.',
        'slot_step_minutes' => 'Slot step (minutes)',
        'slot_step_minutes_hint' => 'How often a new start time is offered.',
        'doctor' => 'Doctor',
        'no_doctor' => 'Not added yet',
        'visit_types' => 'Visit types',
        'staff' => 'Staff',
        'is_active' => 'Active',
        'section' => [
            'details' => 'Clinic details',
            'settings' => 'Clinic settings',
            'settings_hint' => 'Seeded from system defaults; the clinic owner can change them afterwards.',
        ],
    ],

    'doctor' => [
        'label' => 'Doctor',
        'plural_label' => 'Doctors',
        'name' => 'Doctor name',
        'clinic' => 'Clinic',
        'phone' => 'Phone',
        'section' => [
            'details' => 'Doctor details',
        ],
    ],

    'user' => [
        'label' => 'Account',
        'plural_label' => 'Accounts',
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'password' => 'Password',
        'password_hint' => 'Leave blank to keep the current password.',
        'role' => 'Role',
        'clinics' => 'Clinics',
        'no_clinic' => 'No clinic',
        'doctor' => 'Linked doctor',
        'doctor_hint' => 'Link an owner account to its doctor record.',
        'locale' => 'Language',
        'section' => [
            'account' => 'Account details',
            'access' => 'Role & clinics',
        ],
    ],

    'visit_type' => [
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'duration_minutes' => 'Duration (minutes)',
        'add' => 'Add visit type',
    ],
];
