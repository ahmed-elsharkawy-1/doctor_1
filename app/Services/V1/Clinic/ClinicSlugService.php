<?php

namespace App\Services\V1\Clinic;

use App\Models\Clinic;
use App\Models\ClinicSlugRedirect;

/**
 * Where a clinic's old address leads now.
 *
 * Slugs end up on printed posters and business cards, which outlive any
 * rename. Clinic records every slug it gives up (see Clinic::booted); this
 * answers with the one it uses today.
 */
class ClinicSlugService
{
    /**
     * The live slug behind an address the clinic used before, or null when
     * the address is live already or never belonged to anyone.
     *
     * A live slug always wins: if another clinic holds the address today,
     * that clinic's page is what it opens.
     */
    public function currentSlugFor(string $slug): ?string
    {
        if (Clinic::query()->where('slug', $slug)->exists()) {
            return null;
        }

        $redirect = ClinicSlugRedirect::query()
            ->with('clinic:id,slug')
            ->where('slug', $slug)
            ->first();

        return $redirect?->clinic?->slug;
    }
}
