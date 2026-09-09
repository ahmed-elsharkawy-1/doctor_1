<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the redesigned public page shows that the system could not
 * produce yet.
 *
 * Placement follows one rule: a field lives where it would still be correct if
 * a clinic had two doctors. A photo, a title and a biography belong to the
 * person; a city and a map pin belong to the building.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table): void {
            if (! Schema::hasColumn('doctors', 'title')) {
                // "أخصائي العلاج الطبيعي والتأهيل الحركي" — narrower than the
                // clinic's specialty, and shown directly under the name.
                $table->string('title')->nullable()->after('name');
            }

            if (! Schema::hasColumn('doctors', 'bio')) {
                $table->text('bio')->nullable()->after('title');
            }

            if (! Schema::hasColumn('doctors', 'photo_path')) {
                $table->string('photo_path')->nullable()->after('bio');
            }
        });

        Schema::table('clinics', function (Blueprint $table): void {
            if (! Schema::hasColumn('clinics', 'city')) {
                // Shown as its own stat, so it cannot be dug out of `address`.
                $table->string('city', 120)->nullable()->after('address');
            }

            if (! Schema::hasColumn('clinics', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('city');
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
        });

        Schema::table('visit_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('visit_types', 'description')) {
                // "أول زيارة لتحديد الحالة" — the line under each service.
                $table->string('description')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table): void {
            $table->dropColumn(['title', 'bio', 'photo_path']);
        });

        Schema::table('clinics', function (Blueprint $table): void {
            $table->dropColumn(['city', 'latitude', 'longitude']);
        });

        Schema::table('visit_types', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
