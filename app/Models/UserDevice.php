<?php

namespace App\Models;

use App\Enums\DeviceApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    protected $fillable = ['user_id', 'jti', 'app', 'platform', 'device_name', 'fcm_token', 'last_seen_at', 'revoked_at'];

    protected $hidden = ['jti', 'fcm_token'];

    protected function casts(): array
    {
        return [
            'app' => DeviceApp::class,
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
