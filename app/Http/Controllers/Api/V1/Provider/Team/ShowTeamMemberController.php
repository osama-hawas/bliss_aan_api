<?php

namespace App\Http\Controllers\Api\V1\Provider\Team;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Team\TeamMemberResource;
use App\Services\Providers\ProviderSetup;
use App\Services\Team\TeamMembers;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ShowTeamMemberController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderSetup $setup, private readonly TeamMembers $team) {}

    public function __invoke(Request $request, int $member)
    {
        $provider = $this->setup->activeProvider($request->user());

        return $this->success(new TeamMemberResource($this->team->find($provider, $member)));
    }
}
