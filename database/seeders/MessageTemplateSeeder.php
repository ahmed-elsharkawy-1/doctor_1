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
            | {{1}} patient · {{2}} date · {{3}} time · {{4}} doctor
            | {{5}} address · {{6}} arrival lead
            | Button: https://elayadah.com/{{1}} <- booking/<token>
            */
            WhatsAppMessagingService::CONFIRMATION_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_booking_confirmation_v3',
                'language_code' => 'ar',
                'body_ar' => "مرحبًا {{1}} \n\nتم تأكيد حجز موعدك بنجاح ✅\n\n"
                    ."📅 *التاريخ:* {{2}}\n⏰ *الموعد:* {{3}}\n\n"
                    ."👨‍⚕️ *الطبيب:* {{4}}\n\n📌 *العنوان:* {{5}}\n\n"
                    ."🕐 *يرجى الحضور قبل الموعد بـ:* {{6}}\n\n"
                    ."نتمنى لك الشفاء والعافية\n\n"
                    .'يمكنك متابعة تفاصيل الحجز أو الاطلاع على التحديثات من خلال الرابط:',
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
            | {{1}} patient
            | Button: https://elayadah.com/{{1}} <- review/<token>
            */
            WhatsAppMessagingService::VISIT_COMPLETED_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_rating_v3',
                'language_code' => 'ar',
                'body_ar' => "*قيّم تجربتك*\n\nأهلًا {{1}}،\n\n"
                    ."حابين نعرف رأيك في تجربة الحجز الأخيرة مع العيادة.\n\n"
                    ."هل كان نظام الحجز الجديد سهل وساعدك توفّر وقتك؟\n\n"
                    ."رأيك يهمنا ويساعدنا نحسّن الخدمة.\n\n"
                    .'حجزك أسهل، وقتك أثمن.',
                'is_active' => true,
            ],

            /*
            | Develop's reminder, sent by clinic:send-reminders an hour before
            | the visit. Nothing about this key, its provider template or its
            | wording changed. Its provider_template_name was read back from
            | the Graph API on 2026-10-24; confirm it is still approved at
            | Meta before scheduling the command, the way current_templates.md
            | does.
            | {{1}} patient · {{2}} date · {{3}} time · {{4}} doctor
            | {{5}} address. "30 دقيقة" is fixed text in the approved template.
            | Button: https://elayadah.com/{{1}} <- booking/<token>
            */
            WhatsAppMessagingService::REMINDER_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_reminder',
                'language_code' => 'ar',
                'body_ar' => "مرحبًا {{1}} 👋\n\nتذكير بموعدك النهارده ⏰\n\n"
                    ."📅 {{2}}\n⏰ الساعة: {{3}}\n👨‍⚕️ {{4}}\n📌 {{5}}\n\n"
                    .'يرجى الحضور قبل الموعد بـ 30 دقيقة 🙏',
                'is_active' => true,
            ],

            /*
            | The visit-day reminder, sent by clinic:send-daily-reminders at
            | the clinic's configured reminder time. Two quick-reply buttons
            | and no URL — the booking link follows only after Confirm.
            | Submitted to Meta 2026-10-24 (ID 1886523002487229).
            | {{1}} patient · {{2}} date · {{3}} time · {{4}} doctor
            | {{5}} address
            | Buttons: تأكيد الحضور / إلغاء الحجز
            */
            WhatsAppMessagingService::DAILY_REMINDER_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_reminder_today',
                'language_code' => 'ar',
                'body_ar' => "مرحبًا {{1}} 👋\n\nنذكّرك بموعد حجزك اليوم ✅\n\n"
                    ."📅 التاريخ: {{2}}\n⏰ الموعد: {{3}}\n👨‍⚕️ الطبيب: {{4}}\n📌 العنوان: {{5}}\n\n"
                    ."🕐 يرجى الحضور قبل الموعد بـ 30 دقيقة\n\n"
                    ."من فضلك أكّد حضورك أو ألغِ الحجز من الأزرار بالأسفل.\n"
                    .'نتمنى لك الشفاء والعافية',
                'is_active' => true,
            ],

            /*
            | {{1}} patient · {{2}} doctor · {{3}} date · {{4}} clinic phone
            | No button.
            */
            'day_cancelled' => [
                'category' => 'utility',
                'is_broadcast' => true,
                'provider_template_name' => 'booking_cancellation',
                'language_code' => 'ar',
                'body_ar' => "*اعتذار عن إلغاء ميعادك*\n\nأهلاً *{{1}}*\n\n"
                    .'نأسف نبلغك إنه بسبب ظرف طارئ، اضطرينا نلغي مواعيد اليوم، '
                    ."وده يشمل ميعادك مع الدكتور *{{2}}* يوم *{{3}}*.\n\n"
                    ."نعتذر بشدة عن أي إزعاج، وهيتم التواصل معاك في أقرب وقت لتنسيق ميعاد آخر يناسبك.\n\n"
                    ."ولأي استفسار، إحنا موجودين على *{{4}}*.\n\nشكراً لتفهمك\n\n"
                    .'*حجزك أسهل، وقتك أثمن*',
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
            | new time — so never a broadcast. Approved at Meta without a delay
            | amount; read back from the Graph API on 2026-10-24.
            | {{1}} patient · {{2}} date · {{3}} new time · {{4}} doctor
            | {{5}} clinic phone
            | Button: https://elayadah.com/{{1}} <- booking/<token>
            */
            WhatsAppMessagingService::DELAYED_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_delayed',
                'language_code' => 'ar',
                'body_ar' => "مرحبًا {{1}} 👋\n\nنعتذر، تم تأجيل موعدك بسبب حالة طارئة ⚠️\n\n"
                    ."📅 {{2}}\n⏰ الموعد الجديد: {{3}}\n👨‍⚕️ {{4}}\n\n"
                    ."📞 للاستفسار تواصل مع العيادة على:\n{{5}}\n\n"
                    .'نشكرك على تفهمك 🙏',
                'is_active' => true,
            ],
        ];
    }
}
