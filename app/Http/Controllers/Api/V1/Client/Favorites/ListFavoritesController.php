<?php

namespace App\Http\Controllers\Api\V1\Client\Favorites;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Client\Favorites\ListFavoritesRequest;
use App\Http\Resources\Api\V1\Client\Favorites\FavoriteResource;
use App\Services\Catalog\FavoriteService;
use App\Support\Pagination;
use App\Traits\ApiResponse;

class ListFavoritesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FavoriteService $favorites) {}

    /**
     * The client's favorites that are still visible, newest first.
     */
    public function __invoke(ListFavoritesRequest $request)
    {
        return $this->success(FavoriteResource::collection(
            $this->favorites->list($request->user(), $request->type(), Pagination::perPage($request)),
        ));
    }
}
