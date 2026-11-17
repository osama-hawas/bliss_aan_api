<?php

namespace App\Http\Resources\Concerns;

use App\Models\Media;
use App\Models\ProviderTeamMember;
use App\Services\Media\MediaService;

/**
 * Slice 13 team member fields (provider only, D230). The photo is private (D235): a short-lived signed URL, handed
 * out only to the provider who owns the member. Needs `photo` loaded.
 */
trait DescribesTeamMember
{
    protected function teamMemberItem(ProviderTeamMember $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'title' => $m->title,
            'photo' => $this->teamPhoto($m->photo),
            'is_active' => $m->isActive(),
        ];
    }

    protected function teamPhoto(?Media $photo): ?array
    {
        return $photo ? ['id' => $photo->id, 'url' => app(MediaService::class)->url($photo)] : null;
    }
}
