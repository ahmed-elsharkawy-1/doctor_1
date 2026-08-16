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
];
