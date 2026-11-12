<?php

namespace App\Services\ServiceRequests;

use App\Models\Address;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A client's saved addresses (Slice 4, §6.2). At most one default per client, kept under a lock on her address
 * rows (MySQL has no partial unique index). Requests copy a snapshot, so nothing here changes a past request.
 */
class AddressBook
{
    /**
     * Default first, then newest.
     *
     * @return Collection<int, Address>
     */
    public function list(User $client): Collection
    {
        return $client->addresses()->with('city')->orderByDesc('is_default')->latest()->orderByDesc('id')->get();
    }

    /**
     * Her address, or 404 (another client's id never reveals that it exists).
     */
    public function find(User $client, int $id): Address
    {
        return $client->addresses()->findOrFail($id);
    }

    /**
     * Her first address becomes the default.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $client, array $data): Address
    {
        return DB::transaction(function () use ($client, $data) {
            $hasAny = $this->lockAll($client)->isNotEmpty();
            $makeDefault = (bool) ($data['is_default'] ?? false) || ! $hasAny;
            if ($makeDefault) {
                $client->addresses()->where('is_default', true)->update(['is_default' => false]);
            }

            return $client->addresses()->create([...$this->clean($data), 'is_default' => $makeDefault])->load('city');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Address $address, array $data): Address
    {
        return DB::transaction(function () use ($address, $data) {
            $this->lockAll($address->user);
            $address->fill($this->clean($data));
            if (array_key_exists('is_default', $data) && $data['is_default'] && ! $address->is_default) {
                $address->user->addresses()->where('is_default', true)->update(['is_default' => false]);
                $address->is_default = true;
            }
            // Unsetting the default leaves her without one, like deleting it.
            if (array_key_exists('is_default', $data) && ! $data['is_default']) {
                $address->is_default = false;
            }
            $address->save();

            return $address->load('city');
        });
    }

    /**
     * Soft delete. Deleting the default leaves no default (the contract does not pick another one).
     */
    public function delete(Address $address): void
    {
        DB::transaction(function () use ($address) {
            $this->lockAll($address->user);
            $address->forceFill(['is_default' => false])->save();
            $address->delete();
        });
    }

    public function setDefault(Address $address): Address
    {
        return $this->update($address, ['is_default' => true]);
    }

    /**
     * @return Collection<int, Address>
     */
    private function lockAll(User $client): Collection
    {
        return $client->addresses()->lockForUpdate()->get(['id']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        unset($data['is_default']);
        if (array_key_exists('recipient_phone', $data) && $data['recipient_phone'] !== null) {
            $data['recipient_phone'] = Phone::normalizeSaudiMobile($data['recipient_phone']);
        }

        return $data;
    }
}
