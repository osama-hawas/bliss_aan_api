<?php

namespace App\Services\Account;

use App\Enums\ActivityVisibility;
use App\Enums\UploadContext;
use App\Enums\UserType;
use App\Models\Media;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Media\MediaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Her own account details (Slice 12a, D152) and the client profile photo (D153). Which fields each user type may
 * send is decided by the FormRequest; this service only writes what it is given.
 */
class ProfileService
{
    /** Fields a client may edit (D152). */
    public const CLIENT_FIELDS = ['name', 'email', 'city_id', 'birth_date', 'locale'];

    /** Fields a provider may edit (D152): her name and city change only through support. */
    public const PROVIDER_FIELDS = ['email', 'birth_date', 'locale'];

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly MediaService $media,
    ) {}

    /**
     * @return list<string>
     */
    public static function editableFields(UserType $type): array
    {
        return $type === UserType::Client ? self::CLIENT_FIELDS : self::PROVIDER_FIELDS;
    }

    /**
     * @param  array<string, mixed>  $data  validated, only editable fields
     */
    public function update(User $user, array $data): User
    {
        $data = array_intersect_key($data, array_flip(self::editableFields($user->type)));
        if (array_key_exists('email', $data) && is_string($data['email'])) {
            $data['email'] = trim($data['email']) === '' ? null : trim($data['email']);
        }
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->fill($data);
            $changed = array_keys($user->getDirty());
            $user->save();
            if ($changed !== []) {
                // Field names only: the values are personal data and stay out of the history (PDPL).
                $this->activity->record($user, 'profile_updated', actor: $user, visibility: ActivityVisibility::Internal, meta: ['fields' => $changed]);
            }
        });

        return $user->refresh();
    }

    /**
     * Sets (or with null removes) the client's photo: a public `avatar` file she uploaded and that is not attached yet.
     * The previous photo is detached, not deleted.
     */
    public function setAvatar(User $client, ?int $mediaId): User
    {
        DB::transaction(function () use ($client, $mediaId) {
            // Her user row lock serializes concurrent photo changes, so she never ends with two attached photos.
            User::query()->whereKey($client->id)->lockForUpdate()->first(['id']);
            $media = $mediaId === null ? null : $this->ownAvatar($client, $mediaId);
            $client->media()->where('collection', 'avatar')->get()
                ->each(fn (Media $old) => $old->attachable()->dissociate()->save());
            if ($media !== null) {
                $this->media->attach($media, $client, 'avatar');
            }
            $this->activity->record($client, $media === null ? 'avatar_removed' : 'avatar_set', actor: $client, visibility: ActivityVisibility::Internal);
        });

        return $client->refresh()->load('avatar');
    }

    private function ownAvatar(User $user, int $mediaId): Media
    {
        return Media::query()
            ->whereKey($mediaId)
            ->where('uploaded_by', $user->id)
            ->where('collection', UploadContext::Avatar->value)
            ->whereNull('attachable_id')
            ->lockForUpdate()
            ->first()
            ?? throw ValidationException::withMessages(['media_id' => __('validation.exists', ['attribute' => __('account.attributes.media_id')])]);
    }
}
