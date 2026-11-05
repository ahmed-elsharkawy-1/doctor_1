<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use Illuminate\Database\Seeder;

/**
 * The WhatsApp templates the app sends.
 *
 * Separate from DatabaseSeeder so it can be run on a live environment: that
 * one also resets the super admin's password, which is not something a deploy
 * should do. Every write is an upsert keyed on `key`, so running this is
 * always safe and never disturbs a template Meta has already approved.
 *
 * `key` is ours and appears throughout the code; `provider_template_name` and
 * `language_code` are Meta's and must match the approved template exactly —
 * they were read back from the Graph API rather than typed from a screenshot.
 * `body_ar` is only our copy, for the dashboard and for support: Meta renders
 * the approved text itself, so this never travels.
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $key => $template) {
            MessageTemplate::updateOrCreate(['key' => $key], $template);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            /*
            | Submitted 2026-11-05 (ID 2168195491242678), in review.
            | {{1}} patient · {{2}} date · {{3}} time · {{4}} doctor
            | {{5}} address
            | Button: تابع دورك وتفاصيل حجزك
            |   https://elayadah.com/{{1}} <- booking/<token>
            */
            WhatsAppMessagingService::CONFIRMATION_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_booking_confirmation_v4',
                'language_code' => 'ar',
                'body_ar' => "👋 أهلاً {{1}}،\nتم تأكيد حجزك بنجاح ✅\n\n"
                    ."📅 التاريخ: {{2}}\n⏰ الموعد: {{3}}\n👨‍⚕️ الطبيب: {{4}}\n📌 العنوان: {{5}}\n\n"
                    ."⏳ الموعد ممكن يتأخر لو فيه حالة طارئة، وهنبلغك بأي تغيير.\n\n"
                    .'📋 تعليمات الزيارة هتوصلك في الرسالة الجاية.',
                'is_active' => true,
            ],

            /*
            | Sent a few seconds after the confirmation, never on its own.
            | Submitted 2026-11-05 (ID 4629394283996329), in review.
            | {{1}} arrival lead, a bare number ("30") · {{2}} tracking link
            | Button: quick reply الغاء الحجز — answered by WhatsAppReplyHandler
            */
            WhatsAppMessagingService::VISIT_GUIDELINES_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'visit_guidelines',
                'language_code' => 'ar',
                'body_ar' => "📋 تعليمات الزيارة:\n\n"
                    ."🕐 احضر قبل موعدك بـ {{1}} دقيقة. لو اتأخرت، هتدخل بعد الحالات المحجوزة الموجودة.\n\n"
                    ."📞 مش هتقدر تيجي؟ ألغِ الحجز بأسرع وقت علشان نقدر ندي الميعاد لمريض محتاجه. الغياب بدون إلغاء يوقف الحجز من التطبيق.\n\n"
                    ."🚑 الحالات الطارئة ليها الأولوية، فممكن عدد اللي قبلك يزيد.\n\n"
                    ."🔗 تابع دورك أول بأول من هنا: {{2}}\n\n"
                    .'شكرًا لتعاونك 🙏',
                'is_active' => true,
            ],

            /*
            | The doctor's morning report. Sent to a clinic account, never to a
            | patient, and only for clinics with reports switched on.
            | {{1}} period ("أمس السبت ٣ أكتوبر") · {{2}} clinic
            | {{3}} completed visits · {{4}} income
            | Button: https://elayadah.com/reports/{{1}} <- day/2026-10-03,
            | week/2026-09-26 or month/2026-09
            */
            'clinic_report' => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'clinic_report',
                'language_code' => 'ar',
                'body_ar' => "📊 تقرير {{1}}\n🏥 {{2}}\n\n"
                    ."✅ *كشوفات مكتملة:* {{3}}\n💰 *الدخل:* {{4}}\n\n"
                    .'لعرض التفاصيل كاملة اضغط على الزر بالأسفل.',
                'is_active' => true,
            ],

            /*
            | Submitted 2026-11-05 (ID 1143286641896246), in review.
            | {{1}} patient
            | Buttons, in this order:
            |   0 قيّم تجربتك      https://elayadah.com/{{1}} <- review/<token>
            |   1 احجز ميعاد جديد  https://elayadah.com/{{1}} <- <slug>/book
            */
            WhatsAppMessagingService::VISIT_COMPLETED_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_rating_v4',
                'language_code' => 'ar',
                'body_ar' => "👋 أهلاً {{1}}،\nشكرًا لزيارتك النهارده 🙏\n\n"
                    ."رأيك في تجربة الحجز والانتظار بيفرق معانا، والتقييم مش هياخد غير دقيقة.\n\n"
                    .'ولحجز ميعاد جديد في أي وقت، الزرار متاح تحت 👇',
                'is_active' => true,
            ],

            /*
            | The turn-approaching alert, sent by clinic:send-reminders
            | the clinic's position × slot length before the visit. It
            | replaced the pre-visit reminder and kept its key.
            | Submitted 2026-11-05 (ID 1172295225303188), in review.
            | {{1}} patient
            | Button: تابع دورك وتفاصيل حجزك
            |   https://elayadah.com/{{1}} <- booking/<token>
            */
            WhatsAppMessagingService::REMINDER_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_reminder_v2',
                'language_code' => 'ar',
                'body_ar' => "👋 أهلاً {{1}}،\nدورك قرّب! ⏰\n\n"
                    ."يُرجى التوجه للعيادة دلوقتي.\n\n"
                    .'🕐 في حالة عدم التواجد وقت الدور، الدخول هيكون بعد الحالات المحجوزة الموجودة.',
                'is_active' => true,
            ],

            /*
            | The confirm-or-cancel reminder, sent by
            | clinic:send-daily-reminders at the clinic's configured reminder
            | time. Two quick-reply buttons and no URL — the booking link
            | follows only after Confirm.
            | Submitted 2026-11-05 (ID 1035505822923857), in review.
            | {{1}} patient · {{2}} date · {{3}} time · {{4}} doctor
            | Buttons: تأكيد الحضور / إلغاء الحجز
            */
            WhatsAppMessagingService::DAILY_REMINDER_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_reminder_today_v2',
                'language_code' => 'ar',
                'body_ar' => "👋 أهلاً {{1}}،\nيُرجى تأكيد حضورك لموعدك ⏰\n\n"
                    ."📅 التاريخ: {{2}}\n⏰ الموعد: {{3}}\n👨‍⚕️ الطبيب: {{4}}\n\n"
                    .'ولو الظروف اتغيرت، الإلغاء بدري بيدي الفرصة لمريض تاني محتاج الميعاد.',
                'is_active' => true,
            ],

            /*
            | Sent from the broadcast screen; sending it cancels the day.
            | Submitted 2026-11-05 (ID 1428443785558956), in review.
            | {{1}} patient · {{2}} date · {{3}} time · {{4}} clinic phone
            | Button: احجز ميعاد جديد  https://elayadah.com/{{1}} <- <slug>/book
            */
            WhatsAppMessagingService::CANCELS_THE_DAY_KEY => [
                'category' => 'utility',
                'is_broadcast' => true,
                'provider_template_name' => 'booking_cancellation_v2',
                'language_code' => 'ar',
                'body_ar' => "👋 أهلاً {{1}}،\nنعتذر، اتلغى موعدك بسبب ظرف طارئ ⚠️\n\n"
                    ."📅 الموعد الملغي: {{2}} الساعة {{3}}\n\n"
                    ."اختيار ميعاد جديد متاح بسهولة من الزرار تحت 👇\n\n"
                    ."📞 لأي استفسار، العيادة متاحة على: {{4}}\n\n"
                    .'شكرًا لتفهمك 🙏',
                'is_active' => true,
            ],

            /*
            | A patient joined a full day's waiting list, or the secretary
            | added them. Not a booking, and it says so.
            | {{1}} patient · {{2}} the day asked for ("الثلاثاء 10 نوفمبر")
            | No button.
            */
            WhatsAppMessagingService::WAITING_LIST_JOINED_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'waiting_list_joined',
                'language_code' => 'ar',
                'body_ar' => "👋 أهلاً {{1}}،\n*تم تسجيلك في قائمة الانتظار* ✅\n\n"
                    ."📅 اليوم المطلوب: {{2}}\n\n"
                    ."أول ما يفضى ميعاد، العيادة هتتواصل على الرقم ده لتأكيد الحجز.\n\n"
                    .'⚠️ التسجيل في القائمة مش معناه إن الحجز اتأكد.',
                'is_active' => true,
            ],

            /*
            | Sent by clinic:close-waiting-lists once the day's last period has
            | ended, to whoever is still on its list.
            | {{1}} patient · {{2}} the day asked for
            | Button: https://elayadah.com/{{1}} <- <slug>/book
            */
            WhatsAppMessagingService::WAITING_LIST_NO_SLOT_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'waiting_list_no_slot_opened_v3',
                'language_code' => 'ar',
                'body_ar' => "👋 أهلاً {{1}}،\n\nبخصوص تسجيلك في قائمة الانتظار ليوم {{2}}:\n\n"
                    ."نعتذر، لم يتوفر موعد شاغر خلال اليوم المطلوب، ولذلك لم يتم تأكيد حجز.\n\n"
                    ."يمكنك مراجعة المواعيد المتاحة من الزر بالأسفل.\n\n"
                    .'شكرًا لتفهمك 🙏',
                'is_active' => true,
            ],

            /*
            | Sent instead of the confirmation when the secretary books a
            | waiting-list patient, after calling them.
            | {{1}} patient · {{2}} date · {{3}} time · {{4}} doctor
            | {{5}} address
            | Button: https://elayadah.com/{{1}} <- booking/<token>
            */
            WhatsAppMessagingService::WAITING_LIST_SLOT_BOOKED_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'waiting_list_slot_booked',
                'language_code' => 'ar',
                'body_ar' => "👋 أهلاً {{1}}،\nخبر حلو! اتحجزلك ميعاد من قائمة الانتظار 🎉\n\n"
                    ."📅 التاريخ: {{2}}\n⏰ الموعد: {{3}}\n👨‍⚕️ الطبيب: {{4}}\n📌 العنوان: {{5}}\n\n"
                    ."⏳ الموعد ممكن يتأخر لو فيه حالة طارئة، وهنبلغك بأي تغيير.\n\n"
                    .'🕐 في حالة التأخير عن الموعد، الدخول هيكون بعد الحالات المحجوزة الموجودة.',
                'is_active' => true,
            ],

            /*
            | Written by a super admin in the panel — never offered in the
            | clinic app, which is why it is not a broadcast.
            | {{1}} doctor · {{2}} patient · {{3}} the announcement, one
            | paragraph · {{4}} clinic phone
            | No button.
            */
            WhatsAppMessagingService::ANNOUNCEMENT_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'clinic_announcement_v2',
                'language_code' => 'ar',
                'body_ar' => "📢 تنبيه من عيادة {{1}}\n\n👋 أهلاً {{2}}،\n{{3}}\n\n"
                    ."لأي استفسار، العيادة متاحة على: {{4}}\n"
                    .'شكرًا 🙏',
                'is_active' => true,
            ],

            /*
            | Written for the app but never submitted to Meta, so there is
            | nothing to send them through. Kept as rows — the clinic may want
            | them later and a deleted row would take its history with it —
            | but inactive, which is what keeps them off the broadcast screen.
            | Offering a send that is certain to fail is worse than not
            | offering it at all.
            */
            'appointment_earlier' => [
                'category' => 'utility',
                'is_broadcast' => true,
                'provider_template_name' => 'appointment_earlier',
                'language_code' => 'ar',
                'body_ar' => 'مرحباً {{1}}، نود إبلاغك بإمكانية تقديم موعد الكشف اليوم في {{2}}. برجاء الحضور في أقرب وقت يناسبك.',
                'is_active' => false,
            ],

            /*
            | Sent by the emergency delay, one per moved patient with their own
            | times — so never a broadcast.
            | Submitted 2026-11-05 (ID 29134038209560264), in review.
            | {{1}} patient · {{2}} new time · {{3}} old time · {{4}} clinic phone
            | Button: تابع دورك وتفاصيل حجزك
            |   https://elayadah.com/{{1}} <- booking/<token>
            */
            WhatsAppMessagingService::DELAYED_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_delayed_v2',
                'language_code' => 'ar',
                'body_ar' => "👋 أهلاً {{1}}،\nنعتذر، اتأجل موعدك بسبب حالة طارئة ⚠️\n\n"
                    ."⏰ الموعد الجديد: {{2}} بدل {{3}}\n\n"
                    ."📞 لو فيه أي استفسار، العيادة متاحة على: {{4}}\n\n"
                    .'نشكرك على تفهمك 🙏',
                'is_active' => true,
            ],
        ];
    }
}
