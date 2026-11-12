<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client's saved address. Requests keep their own snapshot, so editing or deleting it never changes a request
 * (BRD Req 04). `is_default` is managed only by AddressBook (one per user).
 */
class Address extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'label', 'city_id', 'district', 'details', 'lat', 'lng', 'recipient_name', 'recipient_phone', 'is_default'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_default' => 'boolean',
        ];
    }

    public function hasPin(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
