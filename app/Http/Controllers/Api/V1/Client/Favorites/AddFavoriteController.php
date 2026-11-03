<?php

namespace App\Http\Controllers\Api\V1\Client\Favorites;

use App\Enums\FavoriteType;
use App\Http\Controllers\Controller;
use App\Services\Catalog\FavoriteService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class AddFavoriteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FavoriteService $favorites) {}

    /**
     * Idempotent: adding an existing favorite is a no-op. 404 when the item is not visible.
     */
    public function __invoke(Request $request, FavoriteType $type, int $id)
    {
        $this->favorites->add($request->user(), $type, $id);

        return $this->success(['type' => $type->value, 'id' => $id, 'is_favorite' => true], __('Added to favorites.'));
    }
}
