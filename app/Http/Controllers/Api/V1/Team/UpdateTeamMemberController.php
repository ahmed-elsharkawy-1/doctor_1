<?php

namespace App\Http\Controllers\Api\V1\Team;

use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Team\UpdateTeamMemberRequest;
use App\Services\Results\V1\Team\TeamMemberResult;
use App\Services\V1\Team\TeamService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateTeamMemberController extends V1Controller
{
    public function __construct(private readonly TeamService $team) {}

    public function __invoke(UpdateTeamMemberRequest $request, int $member): JsonResponse
    {
        $clinic = $this->clinic($request);
        $updated = $this->team->update(
            $clinic,
            $member,
            array_map('intval', $request->validated('branch_ids')),
            $request->validated('permissions'),
        );

        return ApiResponse::success((new TeamMemberResult($updated, $clinic))->toArray(), __('settings.team.updated'));
    }
}
