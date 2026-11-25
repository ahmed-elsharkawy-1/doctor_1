<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which branches each team member works at (ELA-17), and the branch each
 * account last used, so the apps open on it.
 *
 * The doctor (`users.is_owner`) works at every branch without a row here.
 * Every existing team member is assigned to every branch of their clinics,
 * which today is the one each clinic was given, so nobody loses access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('last_branch_id')->nullable()->after('permissions')
                ->constrained('branches')->nullOnDelete();
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        $now = now();

        $rows = DB::table('clinic_user')
            ->join('users', 'users.id', '=', 'clinic_user.user_id')
            ->join('branches', 'branches.clinic_id', '=', 'clinic_user.clinic_id')
            ->where('users.role', 'clinic')
            ->where('users.is_owner', false)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('branch_user')
                ->whereColumn('branch_user.user_id', 'users.id')
                ->whereColumn('branch_user.branch_id', 'branches.id'))
            ->get(['branches.id as branch_id', 'users.id as user_id']);

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('branch_user')->insert($chunk->map(fn ($row): array => [
                'branch_id' => $row->branch_id,
                'user_id' => $row->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_branch_id');
        });

        Schema::dropIfExists('branch_user');
    }
};
