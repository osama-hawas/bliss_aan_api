<?php

namespace App\Http\Controllers\Api\V1\Shared\Store;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Shared\Store\ProductDetailResource;
use App\Services\Store\ProductCatalog;
use App\Traits\ApiResponse;

class ShowProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCatalog $catalog) {}

    /**
     * A visible product with its active variants; hidden or unknown → 404. Public (Slice 9).
     */
    public function __invoke(int $product)
    {
        $found = $this->catalog->product($product) ?? throw new ApiException(ErrorCode::NotFound);

        return $this->success(new ProductDetailResource($found));
    }
}
