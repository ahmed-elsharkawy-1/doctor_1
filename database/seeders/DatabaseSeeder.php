<?php

namespace Database\Seeders;

use App\Enums\UserRole;
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
                'password' => config('clinic.super_admin.password'),
                'role' => UserRole::SUPER_ADMIN,
                'locale' => 'ar',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
