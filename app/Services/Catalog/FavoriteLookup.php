<?php

namespace App\Services\Catalog;

use App\Enums\FavoriteType;
use App\Enums\UserType;
use App\Models\Favorite;
use Illuminate\Http\Request;

/**
 * `is_favorite` for public catalog responses. Loads the signed-in client's favorites once per request;
 * guests and non-clients get false.
 */
final class FavoriteLookup
{
    /**
     * @param  array<string, true>  $keys
     */
    private function __construct(private readonly array $keys) {}

    public static function for(Request $request): self
    {
        if (! $request->attributes->has(self::class)) {
            $user = $request->user();
            $keys = $user?->isType(UserType::Client)
                ? Favorite::query()->where('user_id', $user->id)->get(['favoritable_type', 'favoritable_id'])
                    ->mapWithKeys(fn (Favorite $favorite) => ["{$favorite->favoritable_type->value}:{$favorite->favoritable_id}" => true])
                    ->all()
                : [];
            $request->attributes->set(self::class, new self($keys));
        }

        return $request->attributes->get(self::class);
    }

    public function has(FavoriteType $type, int $id): bool
    {
        return isset($this->keys["{$type->value}:{$id}"]);
    }
}
