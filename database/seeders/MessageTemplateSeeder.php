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
                'provider_template_name' => 'appointment_booking_confirmation',
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
            | No body parameters at all — the text is fixed and only the button
            | varies. Registered at Meta as en_US despite being written in
            | Arabic; sending `ar` for it fails, so the code follows Meta.
            | Button: https://elayadah.com/{{1}} <- review/<token>
            */
            WhatsAppMessagingService::VISIT_COMPLETED_KEY => [
                'category' => 'utility',
                'is_broadcast' => false,
                'provider_template_name' => 'appointment_rating',
                'language_code' => 'en_US',
                'body_ar' => "*قيّم تجربتك*\n\nوقتك أغلى حاجة عندنا، ونفسنا نعرف وفّرناه فعلاً؟\n\n"
                    ."تقدر تعمل تقييم سريع عن تجربتك في نظام\nالحجز الجديد \n\n"
                    .'حجزك أسهل، وقتك أثمن',
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

            'appointment_delayed' => [
                'category' => 'utility',
                'is_broadcast' => true,
                'provider_template_name' => 'appointment_delayed',
                'language_code' => 'ar',
                'body_ar' => 'مرحباً {{1}}، نعتذر عن التأخير في مواعيد الكشف اليوم في {{2}} لظرف طارئ. سيتم استقبالك في أقرب وقت ممكن، ونشكر لك تفهمك.',
                'is_active' => false,
            ],
        ];
    }
}
