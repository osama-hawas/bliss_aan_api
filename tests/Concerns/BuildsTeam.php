<?php

namespace Tests\Concerns;

use App\Enums\TeamMemberStatus;
use App\Enums\UploadContext;
use App\Models\Booking;
use App\Models\Media;
use App\Models\Provider;
use App\Models\ProviderTeamMember;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Slice 13 helpers (docs/slice-13-contract.md): team members and booking teams on top of BuildsBookings.
 */
trait BuildsTeam
{
    use BuildsBookings;

    protected const TEAM = '/api/v1/provider/team-members';

    protected function teamMember(Provider $provider, array $attributes = []): ProviderTeamMember
    {
        return $provider->teamMembers()->create([
            'name' => 'Reem', 'title' => 'Photo assistant', 'status' => TeamMemberStatus::Active, ...$attributes,
        ]);
    }

    protected function disabledMember(Provider $provider, array $attributes = []): ProviderTeamMember
    {
        return $this->teamMember($provider, ['status' => TeamMemberStatus::Disabled, 'disabled_at' => now(), ...$attributes]);
    }

    /**
     * An unattached file she uploaded (default context `team_photo`).
     */
    protected function photo(User $uploader, UploadContext $context = UploadContext::TeamPhoto): Media
    {
        $visibility = $context->visibility();

        return Media::query()->create([
            'collection' => $context->value,
            'disk' => $visibility->disk(),
            'path' => "{$context->value}/".uniqid().'.jpg',
            'original_name' => 'reem.jpg',
            'mime' => 'image/jpeg',
            'size' => 1200,
            'visibility' => $visibility,
            'uploaded_by' => $uploader->id,
        ]);
    }

    /**
     * @param  list<int>  $memberIds
     */
    protected function setTeam(Provider $provider, Booking $booking, array $memberIds): TestResponse
    {
        return $this->asProvider($provider)->putJson("/api/v1/provider/bookings/{$booking->id}/team", ['member_ids' => $memberIds]);
    }
}
