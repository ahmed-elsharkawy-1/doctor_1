<?php

return [
    'revenue_loaded' => 'تم تحميل الإيرادات',
    'retention_loaded' => 'تم تحميل بيانات الرجوع',
    'unknown_period' => 'الفترة دي مش معروفة',
    'period' => [
        'label' => 'الفترة',
        'today' => 'النهارده',
        'this_week' => 'الأسبوع ده',
        'this_month' => 'الشهر ده',
        'last_90_days' => 'آخر ٩٠ يوم',
        'last_365_days' => 'آخر سنة',
        'custom' => 'فترة مخصصة',
    ],

    'compare' => [
        'yesterday' => 'مقارنة بإمبارح',
        'last_week' => 'مقارنة بالأسبوع اللي فات',
        'last_month' => 'مقارنة بالشهر اللي فات',
    ],

    'revenue' => [
        'title' => 'الإيرادات',
        'subheading' => 'محسوبة من الزيارات المكتملة بسعر وقت الحجز. مش بتشمل الحجوزات الملغية أو اللي المريضة مجتش فيها.',
        'today' => 'إيراد النهارده',
        'this_week' => 'إيراد الأسبوع',
        'this_month' => 'إيراد الشهر',
        'completed_visits' => ':count زيارة مكتملة',
        'recent_visits' => 'زيارات الشهر ده',
    ],

    'retention' => [
        'title' => 'رجوع المريضات',
        'subheading' => 'بنقيس المريضات اللي أول زيارة ليهن كانت في الفترة دي، وكام واحدة منهن رجعت بعد كده.',
        'return_rate' => 'نسبة الرجوع',
        'return_rate_hint' => ':returned من :cohort مريضة رجعت تاني',
        'first_visit_only' => 'جت مرة واحدة بس',
        'first_visit_only_hint' => 'عدى عليها أكتر من :days يوم ومرجعتش',
        'new_patients' => 'مريضات جدد',
        'maturing_hint' => ':count لسه بدري عليهن نحكم',
        'visits_in_period' => 'زيارات في الفترة',
        'total_patients' => 'إجمالي المريضات: :count',
    ],

    'column' => [
        'date' => 'التاريخ',
        'time' => 'الوقت',
        'patient' => 'المريضة',
        'code' => 'الكود',
        'visit_type' => 'نوع الزيارة',
        'price' => 'المبلغ',
        'total' => 'الإجمالي',
    ],
];
