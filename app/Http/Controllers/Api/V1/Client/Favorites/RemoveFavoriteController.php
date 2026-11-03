<?php

namespace App\Http\Controllers\Api\V1\Client\Favorites;

use App\Enums\FavoriteType;
use App\Http\Controllers\Controller;
use App\Services\Catalog\FavoriteService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RemoveFavoriteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FavoriteService $favorites) {}

    /**
     * Idempotent, and works even if the item is no longer visible.
     */
    public function __invoke(Request $request, FavoriteType $type, int $id)
    {
        $this->favorites->remove($request->user(), $type, $id);

        return $this->success(['type' => $type->value, 'id' => $id, 'is_favorite' => false], __('Removed from favorites.'));
    }
}
