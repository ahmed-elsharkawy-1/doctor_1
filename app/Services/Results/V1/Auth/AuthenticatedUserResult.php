<?php

namespace App\Services\Results\V1\Auth;

use App\Models\Branch;
use App\Models\User;
use App\Services\Results\ServiceResult;
use App\Services\V1\Branches\ActiveBranch;
use App\Support\PhoneNumber;
use App\Support\Wire;

/**
 * The full signed-in profile payload returned by GET /auth/me.
 */
final class AuthenticatedUserResult extends ServiceResult
{
    public function __construct(
        private readonly User $user,
        private readonly ?string $token = null,
    ) {}

    public function toArray(): array
    {
        $clinic = $this->user->activeClinic();
        $phone = $this->user->phone ? PhoneNumber::tryParse($this->user->phone) : null;

        $body = [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => Wire::phone($phone),
                'role' => Wire::enum($this->user->role, $this->user->role->label()),
                'locale' => $this->user->locale ?? config('clinic.api.default_locale'),
                // The doctor, as opposed to a team member (ELA-17).
                'is_owner' => $this->user->isOwner(),
            ],
            // What this account can use now: the role's abilities, less any
            // permission the doctor hasn't granted it.
            'abilities' => $this->user->effectiveAbilities(),
            'clinic' => $clinic === null ? null : [
                'id' => $clinic->id,
                'name' => $clinic->name,
                'specialty' => $clinic->specialty?->name,
                'timezone' => $clinic->timezone,
                'booking_window_days' => $clinic->booking_window_days,
                'slot_step_minutes' => $clinic->slot_step_minutes,
                'patient_arrival_lead_minutes' => $clinic->patient_arrival_lead_minutes,
            ],
        ];

        // ELA-17: the branches this account works at, for the app's switcher,
        // and the one it opens on. One branch: the app shows no switcher.
        $branches = $clinic === null ? collect() : $this->user->accessibleBranches($clinic);
        $requested = request()->header(ActiveBranch::HEADER);
        $current = $clinic === null ? null : app(ActiveBranch::class)->resolve(
            $this->user,
            $clinic,
            is_numeric($requested) ? (int) $requested : null,
        );

        $body['branches'] = $branches->map(fn (Branch $branch): array => [
            'id' => $branch->id,
            'name' => $branch->name,
            'is_active' => $branch->is_active,
        ])->values()->all();
        $body['branch_id'] = $current?->id;

        if ($this->token !== null) {
            $body['token'] = $this->token;
            $body['token_type'] = 'Bearer';
        }

        return $body;
    }

    public function toLoginArray(): array
    {
        $clinic = $this->user->activeClinic();

        return [
            'title' => $clinic?->name ?? $this->user->name,
            'token' => $this->token,
        ];
    }
}
