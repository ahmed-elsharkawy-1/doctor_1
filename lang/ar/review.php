<?php

/*
| The patient-facing review page, opened from the visit-completed message.
|
| It asks about the booking experience — the system — not about the doctor.
|
| Formal Arabic, matching booking.tracking.* and booking.self_booking.*. The
| patient meets all three pages in one journey — booking, then the queue, then
| this — and they should read as one product rather than three.
*/

return [
    'title' => 'تقييم تجربة الحجز',
    'lead' => 'رأيك يساعدنا على تحسين الخدمة، ولا يستغرق التقييم أكثر من دقيقة.',
    'choose' => 'اختر التقييم المناسب',
    'comment' => 'ملاحظاتك على تجربة الحجز',
    'comment_optional' => '(اختياري)',
    'comment_required_mark' => '(مطلوب)',
    'comment_placeholder' => 'اكتب أي تفاصيل إضافية',
    'comment_placeholder_required' => 'اكتب ملاحظاتك حتى نتمكن من تحسين التجربة',
    'submit' => 'إرسال التقييم',

    'rating' => [
        'very_good' => 'ممتازة',
        'good' => 'جيد',
        'bad' => 'يوجد بعض الملاحظات',
    ],

    // After submitting
    'thanks_title' => 'شكراً لتقييمك',
    'thanks_lead' => 'وصلنا رأيك، وسيساعدنا على تحسين الخدمة.',
    'your_rating' => 'تقييمك',

    // Refusals
    'not_done_title' => 'لم تتم الزيارة بعد',
    'not_done_lead' => 'يمكنك تقييم الزيارة بعد انتهائها.',
    'unavailable_title' => 'لا توجد زيارة لتقييمها',
    'unavailable_lead' => 'هذا الحجز أُلغي أو لم يتم، فلا توجد زيارة يمكن تقييمها.',

    'rating_required' => 'من فضلك اختر تقييمًا.',
    'comment_required' => 'من فضلك اكتب ملاحظاتك، حتى نعرف ما الذي يمكن تحسينه.',
];
