<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One catalog service inside a package, in display order (Slice 24).
 */
class PackageItem extends Model
{
    protected $fillable = ['package_id', 'service_id', 'sort'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
