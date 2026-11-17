<?php

namespace App\Http\Controllers\Api\V1\Provider\Team;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Team\UpdateTeamMemberRequest;
use App\Http\Resources\Api\V1\Provider\Team\TeamMemberResource;
use App\Services\Providers\ProviderSetup;
use App\Services\Team\TeamMembers;
use App\Traits\ApiResponse;

/**
 * Edits, disables or enables a member. There is no delete (BRD: nothing is deleted when disabled).
 */
class UpdateTeamMemberController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup, private readonly TeamMembers $team) {}

    public function __invoke(UpdateTeamMemberRequest $request, int $member)
    {
        $provider = $this->setup->activeProvider($request->user());
        $found = $this->team->find($provider, $member);

        return $this->success(new TeamMemberResource($this->team->update($provider, $request->user(), $found, $request->validated())), __('Team member updated.'));
    }
}
