<?php

namespace App\Http\Controllers\Api\V1\Provider\Team;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Team\ListTeamMembersRequest;
use App\Http\Resources\Api\V1\Provider\Team\TeamMemberResource;
use App\Services\Providers\ProviderSetup;
use App\Services\Team\TeamMembers;
use App\Support\Pagination;
use App\Traits\ApiResponse;

/**
 * Her team, active first (Slice 13).
 */
class ListTeamMembersController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup, private readonly TeamMembers $team) {}

    public function __invoke(ListTeamMembersRequest $request)
    {
        $provider = $this->setup->activeProvider($request->user());

        return $this->success(TeamMemberResource::collection($this->team->list($provider, $request->status(), Pagination::perPage($request))));
    }
}
