<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every booking, and every slot someone is part way through booking, is at a
 * branch (ELA-18). Each existing one is at its clinic's first branch, the
 * only one any clinic had.
 *
 * Null on delete rather than cascade: a branch is deactivated, never
 * deleted, and a booking's history must not vanish with a branch row.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['bookings', 'slot_holds'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('branch_id')->nullable()->after('clinic_id')->constrained()->nullOnDelete();
                $table->index(['branch_id', 'visit_date']);
            });

            $this->backfill($name);
        }
    }

    public function backfill(string $table): void
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

    public function down(): void
    {
        foreach ($this->tables as $name) {
            // Foreign key first: InnoDB lets it lean on the composite index
            // and then refuses to drop that index while the key exists.
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['branch_id']);
                $table->dropIndex(['branch_id', 'visit_date']);
                $table->dropColumn('branch_id');
            });
        }
    }
};
