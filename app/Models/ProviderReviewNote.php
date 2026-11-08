<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer's note on an application. With `completion_items` it is a request to complete it.
 * `internal_note` is staff only.
 */
class ProviderReviewNote extends Model
{
    protected $fillable = ['provider_id', 'reviewer_id', 'note_to_provider', 'internal_note', 'completion_items'];

    protected function casts(): array
    {
        return ['completion_items' => 'array'];
    }

    public function isCompletionRequest(): bool
    {
        return $this->completion_items !== null;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
