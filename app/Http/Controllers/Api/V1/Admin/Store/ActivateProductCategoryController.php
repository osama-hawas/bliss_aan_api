<?php

namespace App\Http\Controllers\Api\V1\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Store\StopRequest;
use App\Http\Resources\Api\V1\Admin\Store\AdminProductCategoryResource;
use App\Services\Store\Admin\ProductCategoryManagement;
use App\Traits\ApiResponse;

/**
 * `products.manage` (D276).
 */
class ActivateProductCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCategoryManagement $categories) {}

    public function __invoke(StopRequest $request, int $category)
    {
        $row = $this->categories->setActive($category, true, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new AdminProductCategoryResource($row), __('store.admin.messages.category_activated'));
    }
}
