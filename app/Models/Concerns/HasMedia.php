<?php

namespace App\Models\Concerns;

use App\Models\Media;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasMedia
{
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'attachable');
    }

    /**
     * The latest file in a single-file collection, e.g. `image`.
     */
    public function mediaIn(string $collection): MorphOne
    {
        return $this->morphOne(Media::class, 'attachable')->where('collection', $collection)->latestOfMany();
    }
}
