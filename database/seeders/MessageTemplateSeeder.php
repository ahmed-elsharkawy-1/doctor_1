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
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // {{1}} patient · {{2}} clinic · {{3}} date and time · {{4}} link
            WhatsAppMessagingService::CONFIRMATION_KEY => 'مرحباً {{1}}، تم تأكيد حجزك في {{2}} يوم {{3}}. تقدر تتابع دورك من هنا: {{4}}',
            // {{1}} patient · {{2}} clinic · the review link rides on the
            // template's URL button, not in the body.
            WhatsAppMessagingService::VISIT_COMPLETED_KEY => 'شكراً لزيارتك {{1}} في {{2}}. '
                .'وقتك أغلى حاجة عندنا، ونفسنا نعرف رأيك في تجربتك. التقييم بياخد أقل من دقيقة.',
            'day_cancelled' => 'مرحباً {{1}}، نعتذر عن إلغاء مواعيد اليوم في {{2}} لظرف طارئ. سنتواصل معك لتحديد موعد جديد في أقرب وقت.',
            'appointment_earlier' => 'مرحباً {{1}}، نود إبلاغك بإمكانية تقديم موعد الكشف اليوم في {{2}}. برجاء الحضور في أقرب وقت يناسبك.',
            'appointment_delayed' => 'مرحباً {{1}}، نعتذر عن التأخير في مواعيد الكشف اليوم في {{2}} لظرف طارئ. سيتم استقبالك في أقرب وقت ممكن، ونشكر لك تفهمك.',
        ];

        foreach ($templates as $key => $body) {
            MessageTemplate::updateOrCreate(
                ['key' => $key],
                [
                    'category' => 'utility',
                    // Sent for one booking, never to a whole day.
                    'is_broadcast' => ! in_array($key, [
                        WhatsAppMessagingService::CONFIRMATION_KEY,
                        WhatsAppMessagingService::VISIT_COMPLETED_KEY,
                    ], true),
                    'body_ar' => $body,
                    'provider_template_name' => $key,
                    'is_active' => true,
                ],
            );
        }
    }
}
