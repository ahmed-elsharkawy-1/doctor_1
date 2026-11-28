<?php

namespace App\Services\V1\Reports;

use App\Models\Clinic;
use App\Models\User;
use App\Services\V1\Branches\BranchScope;

/**
 * Who may read reports, and for which clinic. One rule for the website, the
 * mobile app's WebView link and anything that comes after them.
 */
class ReportAccess
{
    /** The clinic whose reports this account may read, or null. */
    public function clinicFor(?User $user): ?Clinic
    {
        if ($user === null || ! $user->is_active || ! $user->can_access_reports || ! $user->role->usesMobileApp()) {
            return null;
        }

        $clinic = $user->activeClinic();

        if ($clinic === null || ! $clinic->is_active || ! $clinic->reports_enabled) {
            return null;
        }

        // The report is the whole clinic's: not for one kept to some
        // branches (Phase 8), who reads those in the app's Reports instead.
        return app(BranchScope::class)->readsWholeClinic($user, $clinic) ? $clinic : null;
    }
}
