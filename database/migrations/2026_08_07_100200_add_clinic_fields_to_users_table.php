<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->after('email');
            $table->string('phone')->nullable()->after('role');
            $table->foreignId('doctor_id')->nullable()->after('phone')
                ->constrained()->nullOnDelete();
            $table->string('locale', 5)->nullable()->after('doctor_id');
            $table->boolean('is_active')->default(true)->after('locale');

            $table->index('role');
            $table->index('is_active');
        });

        // Staff can be attached to more than one clinic (SPEC decision #3).
        Schema::create('clinic_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['clinic_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['doctor_id']);
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['role', 'phone', 'doctor_id', 'locale', 'is_active']);
        });
    }
};
