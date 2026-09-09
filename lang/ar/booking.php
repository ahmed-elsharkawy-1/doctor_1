<?php

return [
    'arrived' => 'تم تسجيل وصول المريضة',
    'called_in' => 'تم استدعاء المريضة للداخل',
    'completed' => 'تم إنهاء الزيارة',
    'cancelled_ok' => 'تم إلغاء الحجز',
    'status_updated' => 'تم تحديث حالة الحجز',
    'not_cancellable' => 'مينفعش تلغي حجز حالته ":status"',
    'invalid_transition' => 'مينفعش تنقلي الحجز من ":from" لـ ":to" مباشرة',
    'postpone_candidates_loaded' => 'تم تحميل المريضات اللي هيتأثروا',
    'postponed' => 'تم تأجيل :count حجز',
    'nothing_to_postpone' => 'مفيش حجوزات تتأجل في اليوم ده',
    'rebooking_list_loaded' => 'تم تحميل قائمة إعادة الحجز',
    'marked_contacted' => 'تم تسجيل الاتصال',
    'not_awaiting_rebooking' => 'الحجز ده مش في قائمة إعادة الحجز',
    'days_loaded' => 'تم تحميل الأيام المتاحة',
    'calendar_loaded' => 'تم تحميل تقويم الحجوزات',
    'home_loaded' => 'تم تحميل الرئيسية',
    'slots_loaded' => 'تم تحميل المواعيد',
    'created' => 'تم حفظ الحجز',
    'updated' => 'تم تعديل الحجز',
    'loaded' => 'تم تحميل الحجز',

    'not_found' => 'الحجز ده مش موجود',
    'not_editable' => 'مينفعش تعدلي حجز حالته ":status"',
    'slot_unavailable' => 'الميعاد ده محجوز بالفعل',
    'slot_outside_hours' => 'الميعاد ده مش ضمن مواعيد العيادة لنوع الزيارة ده',
    'clinic_closed' => 'العيادة مقفولة في اليوم ده',
    'visit_type_inactive' => 'نوع الزيارة ده متخفي، اختاري نوع تاني',
    'no_doctor' => 'العيادة دي لسه مالهاش طبيب',

    'closed_reason' => [
        'weekly_closed' => 'العيادة مقفولة في اليوم ده',
        'holiday' => 'اليوم ده إجازة',
        'outside_window' => 'اليوم ده خارج مدة فتح الحجز',
    ],
    'status' => [
        'booked' => 'محجوزة',
        'arrived' => 'داخل العيادة',
        'with_doctor' => 'قيد الكشف',
        'done' => 'تم',
        'cancelled' => 'ملغية',
        'no_show' => 'لم يحضر',
    ],

    'cancel_reason' => [
        'patient_cancelled' => 'المريضة ألغت',
        'emergency' => 'ظرف طارئ',
        'incomplete' => 'لم تكتمل',
    ],

    'kind' => [
        'normal' => 'حجز عادي',
        'emergency' => 'حجز طارئ',
    ],

    'patient_location' => [
        'inside_clinic' => 'المريض داخل العيادة',
        'on_way' => 'المريض في الطريق',
    ],

    /*
    | The patient-facing tracking page.
    */
    'tracking' => [
        'patient_name' => 'اسم المريض',
        'time' => 'الوقت',
        'date' => 'التاريخ',
        'booking_type' => 'نوع الحجز',
        'title' => 'حجزك في :clinic',
        'waiting_count' => 'عدد الإنتظار',
        'total' => 'الإجمالى',
        'normal' => 'حالات عادية',
        'emergency' => 'حالة طارئة',
        'emergency_notice' => 'الحالات الطارئة لها الأولوية دائماً، لذلك عدد الحالات قبلك قد يزيد أثناء إنتظارك',
        'your_status' => 'حالتك الآن',
        'expected_at' => 'الوصول للعيادة',
        'call_clinic' => 'إتصال بالعيادة',
        'patient_code' => 'كود المريض',
        'appointment' => 'ميعاد الحجز',
        'your_turn' => 'دورك الآن',
        'your_turn_note' => 'من فضلك توجه إلى غرفة الكشف',
        'done' => 'تم الكشف',
        'done_note' => 'شكراً لزيارتك، نتمنى لك دوام الصحة',
        'cancelled' => 'الحجز ملغي',
        'cancelled_note' => 'من فضلك تواصل مع العيادة لحجز ميعاد جديد',
        'no_show' => 'لم يتم الحضور',
        'no_show_note' => 'من فضلك تواصل مع العيادة لحجز ميعاد جديد',
        'not_today' => 'ميعادك لسه جاي',
        'not_today_note' => 'هتقدر تشوف عدد الإنتظار في يوم الحجز',
        'am' => 'ص',
        'pm' => 'م',
    ],
];
