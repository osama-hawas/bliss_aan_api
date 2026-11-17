<?php

namespace App\Http\Controllers\Api\V1\Shared\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Account\DeletionRequestResource;
use App\Services\Account\AccountDeletion;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * GET shared/account/deletion-request — her latest request (or null) and the live obligations that keep going.
 */
class ShowDeletionRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccountDeletion $deletion) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();
        $latest = $this->deletion->latest($user);

        return $this->success([
            'request' => $latest ? new DeletionRequestResource($latest) : null,
            'obligations' => $this->deletion->obligations($user),
        ]);
    }
}
