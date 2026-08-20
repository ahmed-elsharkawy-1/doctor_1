<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SpecialtySeeder::class);

        User::updateOrCreate(
            ['email' => config('clinic.super_admin.email')],
            [
                'name' => config('clinic.super_admin.name'),
                'password' => config('clinic.super_admin.password') ?: 'password',
                'role' => UserRole::SUPER_ADMIN,
                'locale' => 'ar',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->messageTemplates();
    }

    private function messageTemplates(): void
    {
        $templates = [
            'day_cancelled' => 'مرحباً {{1}}، نعتذر عن إلغاء مواعيد اليوم في {{2}} لظرف طارئ. سنتواصل معك لتحديد موعد جديد في أقرب وقت.',
            'appointment_earlier' => 'مرحباً {{1}}، نود إبلاغك بإمكانية تقديم موعد الكشف اليوم في {{2}}. برجاء الحضور في أقرب وقت يناسبك.',
            'appointment_delayed' => 'مرحباً {{1}}، نعتذر عن التأخير في مواعيد الكشف اليوم في {{2}} لظرف طارئ. سيتم استقبالك في أقرب وقت ممكن، ونشكر لك تفهمك.',
        ];

        foreach ($templates as $key => $body) {
            MessageTemplate::updateOrCreate(
                ['key' => $key],
                [
                    'category' => 'utility',
                    'body_ar' => $body,
                    'provider_template_name' => $key,
                    'is_active' => true,
                ],
            );
        }
    }
}
