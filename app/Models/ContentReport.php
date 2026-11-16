<?php

namespace App\Models;

use App\Enums\ContentReportStatus;
use App\Enums\ReportReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A report on user content (Slice 6: reviews). Stored for moderation in Slice 17; the author is never told.
 */
class ContentReport extends Model
{
    protected $fillable = ['reportable_type', 'reportable_id', 'reporter_id', 'reason_code', 'details', 'status', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return [
            'reason_code' => ReportReason::class,
            'status' => ContentReportStatus::class,
            'handled_at' => 'datetime',
        ];
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
}
