<?php

namespace App\Services\V1\Reports;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\ReportWebLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Links that open the reports page inside the mobile app's WebView.
 *
 * The app asks with its own API token; the link it gets back is single-use
 * and short-lived, and opening it starts an ordinary web session. The link
 * is a bearer secret for those two minutes, so only its hash is stored and
 * spending it is one conditional update — two WebViews racing for the same
 * link cannot both win.
 */
class ReportWebLinkService
{
    public function __construct(private readonly ReportAccess $access) {}

    /**
     * @return array{url: string, expires_at: string, renew_url: string}
     *
     * @throws ApiException when the account may not read reports
     */
    public function issue(User $user): array
    {
        if ($this->access->clinicFor($user) === null) {
            throw ApiException::make(
                ApiErrorCode::REPORTS_NOT_AVAILABLE,
                __('reports.app.not_available'),
                http: 403,
            );
        }

        $now = Carbon::now();

        // This account's spent and lapsed links are of no further use.
        ReportWebLink::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNotNull('used_at')->orWhere('expires_at', '<=', $now))
            ->delete();

        $token = Str::random(64);
        $expiresAt = $now->copy()->addSeconds((int) config('clinic.reports.app_link_ttl_seconds'));

        ReportWebLink::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        return [
            'url' => route('reports.app.open', ['token' => $token]),
            'expires_at' => $expiresAt->toIso8601String(),
            'renew_url' => route('reports.app.renew'),
        ];
    }

    /**
     * Spends the link and returns its account — or null when the link is
     * unknown, spent, lapsed, or the account has since lost report access.
     */
    public function redeem(string $token): ?User
    {
        $hash = hash('sha256', $token);
        $now = Carbon::now();

        $spent = ReportWebLink::query()
            ->where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->update(['used_at' => $now]);

        if ($spent !== 1) {
            return null;
        }

        $user = ReportWebLink::query()->where('token_hash', $hash)->first()?->user;

        return $this->access->clinicFor($user) !== null ? $user : null;
    }
}
