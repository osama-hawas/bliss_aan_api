<?php

namespace App\Http\Controllers\Api\V1\Provider\Calendar;

use App\Http\Controllers\Controller;
use App\Services\Availability\AvailabilityBlocks;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Unblocks: soft-deletes one of her active blocks (§5.6, D94).
 */
class DeleteBlockController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AvailabilityBlocks $blocks) {}

    public function __invoke(Request $request, int $block)
    {
        $this->blocks->delete($request->user(), $block);

        return $this->success(null);
    }
}
