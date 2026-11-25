<?php

use App\Enums\StaffPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tells the doctor apart from the rest of the team (ELA-17).
 *
 * Until now every clinic account could do everything, and the backfill keeps
 * it that way for everyone who already has an account:
 *
 * - The doctor's own login (the one linked to a doctor) is the owner.
 * - A clinic with no such login shares one account between doctor and
 *   assistant, so every account of that clinic is an owner.
 * - Any other account is an assistant, and keeps editing working hours and
 *   holidays through the "Manage schedule" permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_owner')->default(false)->after('role');
            $table->json('permissions')->nullable()->after('is_owner');
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        $withDoctorLogin = DB::table('clinic_user')
            ->join('users', 'users.id', '=', 'clinic_user.user_id')
            ->where('users.role', 'clinic')
            ->whereNotNull('users.doctor_id')
            ->distinct()
            ->pluck('clinic_user.clinic_id');

        DB::table('users')
            ->where('role', 'clinic')
            ->where(fn ($query) => $query
                ->whereNotNull('doctor_id')
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('clinic_user')
                    ->whereColumn('clinic_user.user_id', 'users.id')
                    ->whereNotIn('clinic_user.clinic_id', $withDoctorLogin)))
            ->update(['is_owner' => true]);

        DB::table('users')
            ->where('role', 'clinic')
            ->where('is_owner', false)
            ->update(['permissions' => json_encode([StaffPermission::MANAGE_SCHEDULE->value])]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_owner', 'permissions']);
        });
    }
};
