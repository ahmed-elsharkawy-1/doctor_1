<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A waiting list per branch and day (ELA-21).
 *
 * - `waiting_list_entries.branch_id`: every existing entry is at its clinic's
 *   first branch, the only one any clinic had.
 * - `branches.waiting_list_enabled`: each branch's own switch, under the
 *   clinic's (the operator's, in the panel). Existing branches start on, so a
 *   clinic with the list keeps it exactly as it was; a branch added later
 *   starts off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->boolean('waiting_list_enabled')->default(false)->after('is_active');
        });

        DB::table('branches')->update(['waiting_list_enabled' => true]);

        Schema::table('waiting_list_entries', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('clinic_id')->constrained()->nullOnDelete();
            $table->index(['branch_id', 'visit_date', 'status']);
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        $firstBranch = DB::table('branches')
            ->select('clinic_id', DB::raw('MIN(id) as branch_id'))
            ->groupBy('clinic_id');

        DB::table('waiting_list_entries')
            ->whereNull('waiting_list_entries.branch_id')
            ->joinSub($firstBranch, 'first_branch', 'first_branch.clinic_id', '=', 'waiting_list_entries.clinic_id')
            ->select(['waiting_list_entries.id', 'first_branch.branch_id'])
            ->chunkById(500, function ($rows): void {
                foreach ($rows->groupBy('branch_id') as $branchId => $group) {
                    DB::table('waiting_list_entries')->whereIn('id', $group->pluck('id'))->update(['branch_id' => $branchId]);
                }
            }, 'waiting_list_entries.id', 'id');
    }

    public function down(): void
    {
        // Foreign key first: InnoDB moves it onto the composite index.
        Schema::table('waiting_list_entries', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['branch_id', 'visit_date', 'status']);
            $table->dropColumn('branch_id');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('waiting_list_enabled');
        });
    }
};
