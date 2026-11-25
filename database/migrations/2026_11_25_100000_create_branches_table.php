<?php

use App\Support\BranchName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The places a doctor works from (ELA-16). Every clinic gets one, so a clinic
 * that never adds a second keeps working exactly as before.
 *
 * Address, location and phone are nullable here and fall back to the clinic's
 * own (see Branch). The first branch is created empty of them, so the clinic
 * row stays the one place they are edited until the branch screens replace it.
 * Nothing is copied, so nothing can drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A backfill that failed half way (MySQL commits the CREATE on its
        // own) must not stop the migration from being run again.
        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
                $table->string('name', 100);
                $table->string('address')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                // Stored E.164. Null means the clinic's main booking number.
                $table->string('phone', 20)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['clinic_id', 'name']);
                $table->index(['clinic_id', 'is_active', 'sort_order']);
            });
        }

        $this->backfill();
    }

    /**
     * One branch for every clinic that has none. Safe to run again.
     */
    public function backfill(): void
    {
        $now = now();

        DB::table('clinics')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('branches')
                ->whereColumn('branches.clinic_id', 'clinics.id'))
            ->select(['id', 'name', 'address', 'city'])
            // By id, not by page: each chunk takes its clinics out of the
            // filter, so a page offset would skip as many as it just handled.
            ->chunkById(200, function ($clinics) use ($now): void {
                DB::table('branches')->insert($clinics->map(fn ($clinic): array => [
                    'clinic_id' => $clinic->id,
                    'name' => BranchName::forFirstBranch($clinic->address, $clinic->city, $clinic->name),
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
