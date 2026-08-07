<?php

return [
    'period' => [
        'label' => 'Period',
        'today' => 'Today',
        'this_week' => 'This week',
        'this_month' => 'This month',
        'last_90_days' => 'Last 90 days',
        'last_365_days' => 'Last year',
        'custom' => 'Custom period',
    ],

    'compare' => [
        'yesterday' => 'vs yesterday',
        'last_week' => 'vs last week',
        'last_month' => 'vs last month',
    ],

    'revenue' => [
        'title' => 'Revenue',
        'subheading' => 'From completed visits, at the price recorded when each was booked. Cancellations and no-shows are excluded.',
        'today' => "Today's revenue",
        'this_week' => 'This week',
        'this_month' => 'This month',
        'completed_visits' => ':count completed visits',
        'recent_visits' => "This month's visits",
    ],

    'retention' => [
        'title' => 'Retention',
        'subheading' => 'Measures the patients whose first visit fell in this period, and how many of them came back.',
        'return_rate' => 'Return rate',
        'return_rate_hint' => ':returned of :cohort patients came back',
        'first_visit_only' => 'Seen once only',
        'first_visit_only_hint' => 'More than :days days ago, never returned',
        'new_patients' => 'New patients',
        'maturing_hint' => ':count still too recent to judge',
        'visits_in_period' => 'Visits in period',
        'total_patients' => 'Total patients: :count',
    ],

    'column' => [
        'date' => 'Date',
        'time' => 'Time',
        'patient' => 'Patient',
        'code' => 'Code',
        'visit_type' => 'Visit type',
        'price' => 'Amount',
        'total' => 'Total',
    ],
];
