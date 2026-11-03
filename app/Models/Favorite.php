<?php

namespace App\Models;

use App\Enums\FavoriteType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's saved provider or provider service. The type is an enum string, not a class name.
 */
class Favorite extends Model
{
    protected $fillable = ['user_id', 'favoritable_type', 'favoritable_id'];

    protected function casts(): array
    {
        return ['favoritable_type' => FavoriteType::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'favoritable_id');
    }

    public function providerService(): BelongsTo
    {
        return $this->belongsTo(ProviderService::class, 'favoritable_id');
    }
}
