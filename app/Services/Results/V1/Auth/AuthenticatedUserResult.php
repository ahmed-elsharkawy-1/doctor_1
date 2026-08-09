<?php

namespace App\Services\Results\V1\Auth;

use App\Models\User;
use App\Services\Results\ServiceResult;
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
            ],
            'abilities' => $this->user->role->abilities(),
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
