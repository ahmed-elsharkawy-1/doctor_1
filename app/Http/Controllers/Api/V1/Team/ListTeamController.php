<?php

namespace App\Http\Controllers\Api\V1\Team;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Services\Results\V1\Team\TeamMemberResult;
use App\Services\V1\Team\TeamService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListTeamController extends V1Controller
{
    public function __construct(private readonly TeamService $team) {}

    public function __invoke(Request $request): JsonResponse
    {
        $clinic = $this->clinic($request);

        return ApiResponse::success(
            ['members' => TeamMemberResult::collection($this->team->members($clinic), $clinic)],
            __('settings.team.list_loaded'),
        );
    }
}
