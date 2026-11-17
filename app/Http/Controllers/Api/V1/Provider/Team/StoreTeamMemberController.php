<?php

namespace App\Http\Controllers\Api\V1\Provider\Team;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Team\StoreTeamMemberRequest;
use App\Http\Resources\Api\V1\Provider\Team\TeamMemberResource;
use App\Services\Providers\ProviderSetup;
use App\Services\Team\TeamMembers;
use App\Traits\ApiResponse;

/**
 * Adds a team member (D230). 201.
 */
class StoreTeamMemberController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup, private readonly TeamMembers $team) {}

    public function __invoke(StoreTeamMemberRequest $request)
    {
        $provider = $this->setup->activeProvider($request->user());
        $member = $this->team->create($provider, $request->user(), $request->validated());

        return $this->success(new TeamMemberResource($member), __('Team member added.'), 201);
    }
}
