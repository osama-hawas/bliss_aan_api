<?php

namespace App\Http\Controllers\Api\V1\Shared\Reviews;

use App\Enums\ReviewTag;
use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;

/**
 * Approved review tags (D129).
 */
class ListReviewTagsController extends Controller
{
    use ApiResponse;

    public function __construct() {}

    public function __invoke()
    {
        return $this->success(array_map(fn (ReviewTag $t) => ['code' => $t->value, 'label' => $t->label()], ReviewTag::cases()));
    }
}
