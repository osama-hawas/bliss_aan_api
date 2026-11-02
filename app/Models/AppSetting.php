<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read through App\Services\Settings\AppSettings (cached). A null value means "not specified".
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
