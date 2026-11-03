<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider switch for one catalog inclusion. A missing row means the inclusion applies.
 */
class ProviderServiceInclusion extends Model
{
    protected $fillable = ['provider_service_id', 'service_inclusion_id', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function inclusion(): BelongsTo
    {
        return $this->belongsTo(ServiceInclusion::class, 'service_inclusion_id');
    }
}
