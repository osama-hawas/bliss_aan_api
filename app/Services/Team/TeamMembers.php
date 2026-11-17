<?php

namespace App\Services\Team;

use App\Enums\ActivityVisibility;
use App\Enums\BookingStatus;
use App\Enums\TeamMemberStatus;
use App\Enums\UploadContext;
use App\Models\Media;
use App\Models\Provider;
use App\Models\ProviderTeamMember;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Media\MediaService;
use App\Support\Phone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Her team (Slice 13, docs/slice-13-contract.md §4, D230–D236). Records only: members never log in, nothing is deleted
 * (disable instead), the photo is a private `team_photo` file she uploaded herself (D235).
 */
class TeamMembers
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly MediaService $media,
    ) {}

    public function list(Provider $provider, ?TeamMemberStatus $status, int $perPage): LengthAwarePaginator
    {
        return $this->query($provider)
            ->when($status, fn (Builder $q, TeamMemberStatus $s) => $q->where('status', $s))
            ->orderByRaw('status = ? desc', [TeamMemberStatus::Active->value])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Her own member or a 404.
     */
    public function find(Provider $provider, int $id): ProviderTeamMember
    {
        return $this->query($provider)->findOrFail($id);
    }

    /**
     * @param  array{name: string, title: string, phone?: ?string, photo_media_id?: ?int}  $data
     */
    public function create(Provider $provider, User $user, array $data): ProviderTeamMember
    {
        $member = DB::transaction(function () use ($provider, $user, $data) {
            $member = $provider->teamMembers()->create([
                'name' => $data['name'],
                'title' => $data['title'],
                'phone' => Phone::normalizeSaudiMobile($data['phone'] ?? null),
                'status' => TeamMemberStatus::Active,
            ]);
            if (($data['photo_media_id'] ?? null) !== null) {
                $this->media->attach($this->ownPhoto($user, (int) $data['photo_media_id']), $member, ProviderTeamMember::PHOTO);
            }

            return $member;
        });

        return $this->find($provider, $member->id);
    }

    /**
     * Partial update. `phone` / `photo_media_id` null clear them; `status` to the same value is a no-op.
     */
    public function update(Provider $provider, User $user, ProviderTeamMember $member, array $data): ProviderTeamMember
    {
        DB::transaction(function () use ($user, $member, $data) {
            $member = ProviderTeamMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();

            $fields = array_intersect_key($data, array_flip(['name', 'title']));
            if (array_key_exists('phone', $data)) {
                $fields['phone'] = Phone::normalizeSaudiMobile($data['phone']);
            }
            $member->fill($fields);

            $status = isset($data['status']) ? TeamMemberStatus::from($data['status']) : null;
            if ($status !== null && $status !== $member->status) {
                $from = $member->status;
                $member->status = $status;
                $member->disabled_at = $status === TeamMemberStatus::Disabled ? now() : null;
                $this->activity->record($member, $status === TeamMemberStatus::Disabled ? 'team_member_disabled' : 'team_member_enabled',
                    $from, $status, $user, ActivityVisibility::Provider);
            }
            $member->save();

            if (array_key_exists('photo_media_id', $data)) {
                $photo = $data['photo_media_id'] !== null ? $this->ownPhoto($user, (int) $data['photo_media_id']) : null;
                // The old file is detached and kept (like the provider avatar), never deleted.
                $member->media()->where('collection', ProviderTeamMember::PHOTO)->get()
                    ->each(fn (Media $old) => $old->attachable()->dissociate()->save());
                if ($photo) {
                    $this->media->attach($photo, $member, ProviderTeamMember::PHOTO);
                }
            }
        });

        return $this->find($provider, $member->id);
    }

    private function query(Provider $provider): Builder
    {
        return ProviderTeamMember::query()
            ->where('provider_id', $provider->id)
            ->with('photo')
            ->withCount(['bookings as active_assignments_count' => fn (Builder $q) => $q->whereIn('bookings.status', BookingStatus::busy())]);
    }

    private function ownPhoto(User $user, int $mediaId): Media
    {
        return Media::query()
            ->whereKey($mediaId)
            ->where('uploaded_by', $user->id)
            ->where('collection', UploadContext::TeamPhoto->value)
            ->whereNull('attachable_id')
            ->lockForUpdate()
            ->first()
            ?? throw ValidationException::withMessages(['photo_media_id' => __('validation.exists', ['attribute' => __('validation.attributes.photo_media_id')])]);
    }
}
