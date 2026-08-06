<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base for every V1 endpoint. Controllers are single-action (`__invoke`) and
 * stay thin — resolve the actor, call a service, hand the result to
 * ApiResponse.
 */
abstract class V1Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * The clinic every query is scoped to. Resolved from the token, never from
     * client input (SPEC §6.6).
     */
    protected function clinic(Request $request): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $request->attributes->get('clinic');

        return $clinic;
    }
}
