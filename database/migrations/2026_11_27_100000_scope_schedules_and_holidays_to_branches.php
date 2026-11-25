<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Working hours and holidays per branch (ELA-20).
 *
 * - Each branch has its own week: `clinic_schedules.branch_id`, one row per
 *   branch and day. Every existing week becomes its clinic's first branch's.
 * - A holiday is for one branch, or for all of them when `branch_id` is
 *   empty, which is what every existing holiday becomes. A date range is
 *   one row per date, tied together by `group_id`.
 *
 * `clinic_id` stays on both: it is how the clinic-wide reads that remain
 * until Phase 4 keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_schedules', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('clinic_id')->constrained()->cascadeOnDelete();
            // MySQL needs an index of its own for the clinic_id foreign key
            // before the unique key that served it can go.
            $table->index('clinic_id');
        });

        $this->backfillSchedules();

        Schema::table('clinic_schedules', function (Blueprint $table): void {
            $table->dropUnique(['clinic_id', 'day_of_week']);
            $table->unique(['branch_id', 'day_of_week']);
        });

        Schema::table('clinic_holidays', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('clinic_id')->constrained()->cascadeOnDelete();
            $table->uuid('group_id')->nullable()->after('branch_id');
            $table->index(['clinic_id', 'date']);
        });

        Schema::table('clinic_holidays', function (Blueprint $table): void {
            $table->dropUnique(['clinic_id', 'date']);
            $table->index(['branch_id', 'date']);
            $table->index('group_id');
        });
    }

    public function backfillSchedules(): void
    {
        $firstBranch = DB::table('branches')
            ->select('clinic_id', DB::raw('MIN(id) as branch_id'))
            ->groupBy('clinic_id');

        DB::table('clinic_schedules')
            ->whereNull('clinic_schedules.branch_id')
            ->joinSub($firstBranch, 'first_branch', 'first_branch.clinic_id', '=', 'clinic_schedules.clinic_id')
            ->select(['clinic_schedules.id', 'first_branch.branch_id'])
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('clinic_schedules')->where('id', $row->id)->update(['branch_id' => $row->branch_id]);
                }
            }, 'clinic_schedules.id', 'id');
    }

    public function down(): void
    {
        $firstBranches = DB::table('branches')->select(DB::raw('MIN(id)'))->groupBy('clinic_id');

        // Foreign keys first: InnoDB lets each lean on the composite index
        // added after it, then refuses to drop that index while the key
        // exists (error 1553).
        Schema::table('clinic_holidays', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['branch_id', 'date']);
            $table->dropIndex(['group_id']);
        });

        // The first branch's own holidays were the clinic's: they stay, as
        // clinic holidays. Other branches' can't be told apart and go, and
        // so does any second row for a date before the one-per-day key is back.
        DB::table('clinic_holidays')->whereIn('branch_id', $firstBranches)->update(['branch_id' => null]);
        DB::table('clinic_holidays')->whereNotNull('branch_id')->delete();
        $keep = DB::table('clinic_holidays')->select(DB::raw('MIN(id)'))->groupBy('clinic_id', DB::raw('DATE(date)'));
        DB::table('clinic_holidays')->whereNotIn('id', DB::query()->fromSub($keep, 'keep'))->delete();

        Schema::table('clinic_holidays', function (Blueprint $table): void {
            $table->unique(['clinic_id', 'date']);
            $table->dropIndex(['clinic_id', 'date']);
            $table->dropColumn(['branch_id', 'group_id']);
        });

        Schema::table('clinic_schedules', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
        });

        // Only the first branch's week fits the old one-per-day key.
        DB::table('clinic_schedules')
            ->whereNotIn('branch_id', DB::query()->fromSub($firstBranches, 'first'))
            ->delete();

        Schema::table('clinic_schedules', function (Blueprint $table): void {
            $table->dropUnique(['branch_id', 'day_of_week']);
            $table->unique(['clinic_id', 'day_of_week']);
            $table->dropIndex(['clinic_id']);
            $table->dropColumn('branch_id');
        });
    }
};
