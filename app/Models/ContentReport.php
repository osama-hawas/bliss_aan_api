<?php

namespace App\Models;

use App\Enums\ContentReportDecision;
use App\Enums\ContentReportStatus;
use App\Enums\ReportReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A report on user content (Slice 6: reviews). Stored for moderation in Slice 17; the author is never told.
 */
class ContentReport extends Model
{
    protected $fillable = ['reportable_type', 'reportable_id', 'reporter_id', 'reason_code', 'details', 'status', 'handled_by', 'handled_at',
        'decision', 'decision_reason', 'internal_note', // Slice 17 (D262)
    ];

    protected function casts(): array
    {
        return [
            'reason_code' => ReportReason::class,
            'status' => ContentReportStatus::class,
            'decision' => ContentReportDecision::class,
            'handled_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
}
