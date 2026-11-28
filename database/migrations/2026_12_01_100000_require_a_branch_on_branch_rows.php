<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8, the contract: every booking, hold, waiting-list entry and week of
 * hours is at a branch. Each phase added its column nullable, filled it, and
 * had new rows default to the clinic's first branch; now it is required.
 *
 * A branch with bookings or a waiting list can no longer be deleted: it is
 * deactivated, as the app always did, and its history stays. Holds and hours
 * go with their branch. Holidays keep a nullable branch: null there means
 * every branch.
 */
return new class extends Migration
{
    /** table => what deleting a branch does to its rows, now and before */
    private array $tables = [
        'bookings' => ['restrict', 'null'],
        'waiting_list_entries' => ['restrict', 'null'],
        'slot_holds' => ['cascade', 'null'],
        'clinic_schedules' => ['cascade', 'cascade'],
    ];

    public function up(): void
    {
        foreach ($this->tables as $name => [$onDelete]) {
            // Anything still without one is the first branch's, as each
            // phase's own backfill made it.
            $this->backfill($name);

            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['branch_id']);
            });

            Schema::table($name, function (Blueprint $table) use ($onDelete): void {
                $table->unsignedBigInteger('branch_id')->nullable(false)->change();
                $this->foreign($table, $onDelete);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name => [, $onDelete]) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['branch_id']);
            });

            Schema::table($name, function (Blueprint $table) use ($onDelete): void {
                $table->unsignedBigInteger('branch_id')->nullable()->change();
                $this->foreign($table, $onDelete);
            });
        }
    }

    private function foreign(Blueprint $table, string $onDelete): void
    {
        $key = $table->foreign('branch_id')->references('id')->on('branches');

        match ($onDelete) {
            'restrict' => $key->restrictOnDelete(),
            'cascade' => $key->cascadeOnDelete(),
            'null' => $key->nullOnDelete(),
        };
    }

    private function backfill(string $table): void
    {
        $firstBranch = DB::table('branches')
            ->select('clinic_id', DB::raw('MIN(id) as branch_id'))
            ->groupBy('clinic_id');

        DB::table($table)
            ->whereNull("{$table}.branch_id")
            ->joinSub($firstBranch, 'first_branch', 'first_branch.clinic_id', '=', "{$table}.clinic_id")
            ->select(["{$table}.id", 'first_branch.branch_id'])
            ->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows->groupBy('branch_id') as $branchId => $group) {
                    DB::table($table)->whereIn('id', $group->pluck('id'))->update(['branch_id' => $branchId]);
                }
            }, "{$table}.id", 'id');
    }
};
