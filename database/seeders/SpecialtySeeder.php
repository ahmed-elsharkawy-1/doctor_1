<?php

namespace Database\Seeders;

use App\Models\Specialty;
use Illuminate\Database\Seeder;

/**
 * Specialties and the visit types a new clinic of that specialty starts with.
 *
 * These defaults are only a starting point — once a clinic is provisioned it
 * owns its own visit_types rows and edits them freely (SPEC §3.1).
 *
 * Idempotent: safe to re-run.
 */
class SpecialtySeeder extends Seeder
{
    /**
     * @var list<array{slug: string, name_ar: string, name_en: string, visit_types: list<array{name_ar: string, name_en: string, duration_minutes: int}>}>
     */
    private const SPECIALTIES = [
        [
            'slug' => 'general',
            'name_ar' => 'باطنة',
            'name_en' => 'Internal Medicine',
            'visit_types' => [
                ['name_ar' => 'كشف', 'name_en' => 'Checkup', 'duration_minutes' => 20],
                ['name_ar' => 'إعادة', 'name_en' => 'Follow-up', 'duration_minutes' => 10],
                ['name_ar' => 'إجراء', 'name_en' => 'Procedure', 'duration_minutes' => 30],
            ],
        ],
        [
            'slug' => 'dentistry',
            'name_ar' => 'أسنان',
            'name_en' => 'Dentistry',
            'visit_types' => [
                ['name_ar' => 'كشف', 'name_en' => 'Checkup', 'duration_minutes' => 20],
                ['name_ar' => 'حشو', 'name_en' => 'Filling', 'duration_minutes' => 30],
                ['name_ar' => 'خلع', 'name_en' => 'Extraction', 'duration_minutes' => 30],
                ['name_ar' => 'تنظيف', 'name_en' => 'Cleaning', 'duration_minutes' => 45],
            ],
        ],
        [
            'slug' => 'obstetrics-gynecology',
            'name_ar' => 'نساء وتوليد',
            'name_en' => 'Obstetrics & Gynecology',
            'visit_types' => [
                ['name_ar' => 'كشف', 'name_en' => 'Checkup', 'duration_minutes' => 20],
                ['name_ar' => 'إعادة', 'name_en' => 'Follow-up', 'duration_minutes' => 10],
                ['name_ar' => 'سونار', 'name_en' => 'Ultrasound', 'duration_minutes' => 20],
                ['name_ar' => 'متابعة حمل', 'name_en' => 'Pregnancy Follow-up', 'duration_minutes' => 15],
            ],
        ],
        [
            'slug' => 'dermatology',
            'name_ar' => 'جلدية',
            'name_en' => 'Dermatology',
            'visit_types' => [
                ['name_ar' => 'كشف', 'name_en' => 'Checkup', 'duration_minutes' => 20],
                ['name_ar' => 'إعادة', 'name_en' => 'Follow-up', 'duration_minutes' => 10],
                ['name_ar' => 'جلسة ليزر', 'name_en' => 'Laser Session', 'duration_minutes' => 30],
            ],
        ],
        [
            'slug' => 'pediatrics',
            'name_ar' => 'أطفال',
            'name_en' => 'Pediatrics',
            'visit_types' => [
                ['name_ar' => 'كشف', 'name_en' => 'Checkup', 'duration_minutes' => 20],
                ['name_ar' => 'إعادة', 'name_en' => 'Follow-up', 'duration_minutes' => 10],
                ['name_ar' => 'تطعيم', 'name_en' => 'Vaccination', 'duration_minutes' => 10],
            ],
        ],
        [
            'slug' => 'orthopedics',
            'name_ar' => 'عظام',
            'name_en' => 'Orthopedics',
            'visit_types' => [
                ['name_ar' => 'كشف', 'name_en' => 'Checkup', 'duration_minutes' => 20],
                ['name_ar' => 'إعادة', 'name_en' => 'Follow-up', 'duration_minutes' => 10],
                ['name_ar' => 'جبس', 'name_en' => 'Casting', 'duration_minutes' => 30],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::SPECIALTIES as $order => $definition) {
            $specialty = Specialty::updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name_ar' => $definition['name_ar'],
                    'name_en' => $definition['name_en'],
                    'sort_order' => $order,
                    'is_active' => true,
                ],
            );

            foreach ($definition['visit_types'] as $visitTypeOrder => $visitType) {
                $specialty->defaultVisitTypes()->updateOrCreate(
                    ['name_ar' => $visitType['name_ar']],
                    [
                        'name_en' => $visitType['name_en'],
                        'duration_minutes' => $visitType['duration_minutes'],
                        'sort_order' => $visitTypeOrder,
                    ],
                );
            }
        }
    }
}
