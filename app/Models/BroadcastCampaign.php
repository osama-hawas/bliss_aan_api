<?php

namespace App\Models;

use App\Enums\Audience;
use App\Enums\BroadcastStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * A staff broadcast (Slice 20, D305/D306). Title and body are {ar, en}.
 */
class BroadcastCampaign extends Model
{
    use HasTranslations;

    public array $translatable = ['title', 'body'];

    protected $fillable = [
        'title', 'body', 'app', 'city_id', 'status', 'scheduled_at', 'sent_at', 'finished_at', 'recipients_count',
        'cancel_reason', 'created_by', 'sent_by', 'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'app' => Audience::class,
            'status' => BroadcastStatus::class,
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'finished_at' => 'datetime',
            'recipients_count' => 'integer',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
