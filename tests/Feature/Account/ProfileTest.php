<?php

namespace Tests\Feature\Account;

use App\Enums\UploadContext;
use App\Models\ActivityEvent;
use App\Models\City;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Slice 12a — PATCH shared/account/profile (D152) and PUT client/account/avatar (D153).
 */
class ProfileTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const URL = '/api/v1/shared/account/profile';

    private const AVATAR = '/api/v1/client/account/avatar';

    private function as(User $user): static
    {
        return $this->actingAsApi($user)->withHeader('Accept-Language', 'en');
    }

    private function city(bool $active = true): City
    {
        return City::query()->create(['name' => ['ar' => 'مدينة', 'en' => 'Town'], 'is_active' => $active]);
    }

    public function test_a_client_edits_every_field_and_gets_the_me_shape(): void
    {
        $client = User::factory()->client()->create(['name' => 'Old', 'locale' => 'ar']);
        $city = $this->city();

        $this->as($client)->patchJson(self::URL, [
            'name' => '  Sara  ', 'email' => 'sara@example.com', 'city_id' => $city->id, 'birth_date' => '1995-04-01', 'locale' => 'en',
        ])->assertOk()
            ->assertJsonPath('message', 'Your details have been saved.')
            ->assertJsonPath('data.name', 'Sara')
            ->assertJsonPath('data.email', 'sara@example.com')
            ->assertJsonPath('data.city.id', $city->id)
            ->assertJsonPath('data.birth_date', '1995-04-01')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.avatar_url', null)
            ->assertJsonPath('data.deletion_request', null)
            ->assertJsonPath('data.phone', $client->phone);

        $event = ActivityEvent::query()->where('event', 'profile_updated')->sole();
        $this->assertSame($client->id, $event->subject_id);
        $this->assertEqualsCanonicalizing(['name', 'email', 'city_id', 'birth_date', 'locale'], $event->meta['fields']);
        $this->assertStringNotContainsString('sara@example.com', json_encode($event->meta)); // field names only
    }

    public function test_partial_updates_and_clearing_optional_fields(): void
    {
        $client = User::factory()->client()->create(['email' => 'a@b.co', 'birth_date' => '1990-01-01']);

        $this->as($client)->patchJson(self::URL, ['email' => null, 'birth_date' => null, 'city_id' => null])->assertOk()
            ->assertJsonPath('data.email', null)->assertJsonPath('data.birth_date', null)->assertJsonPath('data.city', null)
            ->assertJsonPath('data.name', $client->name);

        $before = ActivityEvent::query()->where('event', 'profile_updated')->count();
        $this->as($client)->patchJson(self::URL, [])->assertOk();
        $this->assertSame($before, ActivityEvent::query()->where('event', 'profile_updated')->count()); // nothing changed, no entry
    }

    public function test_validation(): void
    {
        $client = User::factory()->client()->create();
        $inactive = $this->city(false);
        $minor = now('Asia/Riyadh')->subYears(18)->addDay()->toDateString();
        $adult = now('Asia/Riyadh')->subYears(18)->toDateString();

        $this->as($client)->patchJson(self::URL, ['name' => '', 'email' => 'nope', 'city_id' => $inactive->id, 'birth_date' => $minor, 'locale' => 'fr'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['name', 'email', 'city_id', 'birth_date', 'locale'])
            ->assertJsonPath('errors.birth_date.0', 'You must be at least 18 years old.');
        $this->as($client)->patchJson(self::URL, ['birth_date' => '01/02/1990'])->assertJsonValidationErrors('birth_date');
        $this->as($client)->patchJson(self::URL, ['birth_date' => $adult])->assertOk()->assertJsonPath('data.birth_date', $adult);
        $this->as($client)->patchJson(self::URL, ['locale' => null])->assertJsonValidationErrors('locale');
    }

    public function test_a_provider_edits_email_birth_date_and_language_only(): void
    {
        $user = $this->applicant();
        $city = $this->city();

        $this->as($user)->patchJson(self::URL, ['email' => 'p@example.com', 'birth_date' => '1990-05-05', 'locale' => 'en'])->assertOk()
            ->assertJsonPath('data.email', 'p@example.com')->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.avatar_url', null);

        $this->as($user)->patchJson(self::URL, ['name' => 'New', 'city_id' => $city->id])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'city_id'])
            ->assertJsonPath('errors.name.0', 'This detail can only be changed through support.');
        $this->assertNotSame('New', $user->refresh()->name);
    }

    public function test_access(): void
    {
        $this->patchJson(self::URL, ['locale' => 'en'])->assertUnauthorized();
        $this->as(User::factory()->admin()->create())->patchJson(self::URL, ['locale' => 'en'])->assertForbidden();
        $this->as(User::factory()->client()->suspended()->create())->patchJson(self::URL, ['locale' => 'en'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
        $this->as(User::factory()->admin()->create())->putJson(self::AVATAR, ['media_id' => null])->assertForbidden();
        $this->as($this->applicant())->putJson(self::AVATAR, ['media_id' => null])->assertForbidden();
    }

    public function test_client_photo_set_replace_and_remove(): void
    {
        $client = User::factory()->client()->create();
        $first = $this->uploaded($client, UploadContext::Avatar);

        $this->as($client)->putJson(self::AVATAR, [])->assertJsonValidationErrors('media_id');
        $this->as($client)->putJson(self::AVATAR, ['media_id' => $this->uploaded($client, UploadContext::Portfolio)->id])
            ->assertJsonValidationErrors('media_id');
        $other = User::factory()->client()->create();
        $this->as($client)->putJson(self::AVATAR, ['media_id' => $this->uploaded($other, UploadContext::Avatar)->id])
            ->assertJsonValidationErrors('media_id');

        $url = $this->as($client)->putJson(self::AVATAR, ['media_id' => $first->id])->assertOk()
            ->assertJsonPath('message', 'Your photo has been updated.')->json('data.avatar_url');
        $this->assertNotNull($url);
        $this->as($client)->getJson('/api/v1/auth/me')->assertJsonPath('data.avatar_url', $url);

        // An attached file cannot be reused; a new one replaces it and the old one is detached, not deleted.
        $this->as($client)->putJson(self::AVATAR, ['media_id' => $first->id])->assertJsonValidationErrors('media_id');
        $second = $this->uploaded($client, UploadContext::Avatar);
        $this->as($client)->putJson(self::AVATAR, ['media_id' => $second->id])->assertOk();
        $this->assertNull($first->refresh()->attachable_id);
        $this->assertSame($client->id, $second->refresh()->attachable_id);

        $this->as($client)->putJson(self::AVATAR, ['media_id' => null])->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->assertNull($second->refresh()->attachable_id);
        $this->assertSame(0, $client->refresh()->media()->count()); // none attached
        $this->assertSame(2, Media::query()->whereKey([$first->id, $second->id])->count()); // both files kept
    }
}
