<?php

namespace App\Filament\Admin\Concerns;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Reporting screens belong to one clinic — the signed-in owner's.
 *
 * Super admins have no clinic of their own, so they see no reports; they
 * manage the platform, not a practice.
 */
trait ResolvesReportingClinic
{
    protected function reportingClinic(): ?Clinic
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null || ! $user->hasAbility('reports.view')) {
            return null;
        }

        return $user->activeClinic();
    }

    public static function canAccessReports(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return ($user?->hasAbility('reports.view') ?? false) && $user?->activeClinicId() !== null;
    }
}
