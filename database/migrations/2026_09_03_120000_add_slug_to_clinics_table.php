<?php

use App\Models\Clinic;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The public address of a clinic's landing page — `/{slug}`.
 *
 * Backfilled rather than defaulted. An Arabic name transliterates to
 * something valid but unlovely — عيادة د. سارة النجار becomes
 * `aayad-d-sar-alngar` — so the backfill only guarantees a working unique
 * address. The operator sets a real one in the panel, and that is the slug
 * that ends up on business cards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clinics', 'slug')) {
            Schema::table('clinics', function (Blueprint $table): void {
                $table->string('slug', 120)->nullable()->after('specialty_id');
            });
        }

        Clinic::whereNull('slug')->get(['id', 'name'])->each(function (Clinic $clinic): void {
            Clinic::whereKey($clinic->id)->update([
                'slug' => $this->uniqueSlug($clinic),
            ]);
        });

        if (! $this->hasIndex('clinics_slug_unique')) {
            Schema::table('clinics', function (Blueprint $table): void {
                $table->unique('slug');
            });
        }
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            if (Schema::hasColumn('clinics', 'slug')) {
                $table->dropUnique('clinics_slug_unique');
                $table->dropColumn('slug');
            }
        });
    }

    private function uniqueSlug(Clinic $clinic): string
    {
        $base = Str::slug((string) $clinic->name);

        if ($base === '' || in_array($base, config('clinic.landing.reserved'), true)) {
            $base = 'clinic-'.$clinic->id;
        }

        $slug = $base;
        $suffix = 2;

        while (Clinic::where('slug', $slug)->whereKeyNot($clinic->id)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('clinics'))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
