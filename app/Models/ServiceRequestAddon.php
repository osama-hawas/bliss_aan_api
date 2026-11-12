<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * A requested catalog add-on, with its name as it was at creation. No quantity (D54).
 */
class ServiceRequestAddon extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['service_request_id', 'addon_id', 'provider_addon_id', 'name'];
}
