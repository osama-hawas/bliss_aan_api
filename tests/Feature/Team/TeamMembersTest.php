<?php

namespace Tests\Feature\Team;

use App\Enums\ProviderStatus;
use App\Enums\TeamMemberStatus;
use App\Enums\UploadContext;
use App\Models\ActivityEvent;
use App\Models\BookingAssignment;
use App\Models\Media;
use App\Models\Provider;
use App\Models\ProviderTeamMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTeam;
use Tests\TestCase;

/**
 * Slice 13 §4 (D230, D233, D235, D236): her team is records only; add, edit, disable / enable; never deleted; the
 * photo is a private `team_photo` file she uploaded herself.
 */
class TeamMembersTest extends TestCase
{
    use BuildsTeam, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
    }

    public function test_she_adds_a_member_with_phone_and_photo(): void
    {
        $photo = $this->photo($this->noura->user);

        $res = $this->asProvider($this->noura)->postJson(self::TEAM, [
            'name' => 'Reem', 'title' => 'Photo assistant', 'phone' => '0551234567', 'photo_media_id' => $photo->id,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Reem')
            ->assertJsonPath('data.title', 'Photo assistant')
            ->assertJsonPath('data.phone', '+966551234567')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_label', 'Active')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.disabled_at', null)
            ->assertJsonPath('data.active_assignments_count', 0)
            ->assertJsonPath('data.photo.id', $photo->id);

        $this->assertStringContainsString('signature=', $res->json('data.photo.url')); // private file (D235)
        $member = ProviderTeamMember::query()->sole();
        $this->assertSame($this->noura->id, $member->provider_id);
        $this->assertNull($member->user_id); // never a login (D230)
        $this->assertSame($member->id, $photo->fresh()->attachable_id);
    }

    public function test_phone_and_photo_are_optional_and_name_and_title_required(): void
    {
        $this->asProvider($this->noura)->postJson(self::TEAM, ['name' => 'Lama', 'title' => 'Coordinator'])->assertCreated()
            ->assertJsonPath('data.phone', null)->assertJsonPath('data.photo', null);

        $this->asProvider($this->noura)->postJson(self::TEAM, [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'title']);
        $this->asProvider($this->noura)->postJson(self::TEAM, ['name' => 'Lama', 'title' => str_repeat('x', 61)])
            ->assertUnprocessable()->assertJsonValidationErrors(['title']);
        $this->asProvider($this->noura)->postJson(self::TEAM, ['name' => str_repeat('x', 101), 'title' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->asProvider($this->noura)->postJson(self::TEAM, ['name' => 'Lama', 'title' => 'x', 'phone' => '12345'])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone']);
    }

    public function test_the_role_label_is_free_text_up_to_60_characters(): void
    {
        $title = str_repeat('م', 60);

        $this->asProvider($this->noura)->postJson(self::TEAM, ['name' => 'Lama', 'title' => $title])->assertCreated()
            ->assertJsonPath('data.title', $title);
    }

    public function test_photo_must_be_her_own_unattached_team_photo(): void
    {
        $foreign = $this->photo($this->huda->user);
        $portfolio = $this->photo($this->noura->user, UploadContext::Portfolio);
        $used = $this->photo($this->noura->user);
        $this->teamMember($this->noura)->media()->save($used);

        foreach ([$foreign, $portfolio, $used] as $media) {
            $this->asProvider($this->noura)->postJson(self::TEAM, ['name' => 'Lama', 'title' => 'x', 'photo_media_id' => $media->id])
                ->assertUnprocessable()->assertJsonValidationErrors(['photo_media_id']);
        }
        $this->assertSame(1, ProviderTeamMember::query()->count());
    }

    public function test_list_is_hers_only_active_first_then_by_name_and_filters_by_status(): void
    {
        $this->teamMember($this->noura, ['name' => 'Zainab']);
        $this->disabledMember($this->noura, ['name' => 'Abeer']);
        $this->teamMember($this->noura, ['name' => 'Dana']);
        $this->teamMember($this->huda, ['name' => 'Other']);

        $this->asProvider($this->noura)->getJson(self::TEAM)->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'Dana')
            ->assertJsonPath('data.1.name', 'Zainab')
            ->assertJsonPath('data.2.name', 'Abeer')
            ->assertJsonPath('data.2.status', 'disabled');

        $this->asProvider($this->noura)->getJson(self::TEAM.'?status=disabled')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Abeer');
        $this->asProvider($this->noura)->getJson(self::TEAM.'?status=gone')->assertUnprocessable();
    }

    public function test_another_providers_member_is_a_404(): void
    {
        $other = $this->teamMember($this->huda);

        $this->asProvider($this->noura)->getJson(self::TEAM."/{$other->id}")->assertNotFound();
        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$other->id}", ['name' => 'Taken'])->assertNotFound();
        $this->assertSame('Reem', $other->fresh()->name);
    }

    public function test_an_inactive_provider_cannot_manage_her_team(): void
    {
        $member = $this->teamMember($this->noura);
        $this->noura->update(['status' => ProviderStatus::Suspended]);

        $this->asProvider($this->noura)->getJson(self::TEAM)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->asProvider($this->noura)->postJson(self::TEAM, ['name' => 'Lama', 'title' => 'x'])->assertStatus(409);
        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$member->id}", ['name' => 'x'])->assertStatus(409);
    }

    public function test_a_client_cannot_reach_team_routes(): void
    {
        $this->actingAsApi($this->client)->getJson(self::TEAM)->assertForbidden();
    }

    public function test_edit_changes_only_the_sent_fields_and_null_clears_phone(): void
    {
        $member = $this->teamMember($this->noura, ['phone' => '+966551234567']);

        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$member->id}", ['title' => 'Lead'])->assertOk()
            ->assertJsonPath('data.title', 'Lead')->assertJsonPath('data.name', 'Reem')->assertJsonPath('data.phone', '+966551234567');
        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$member->id}", ['phone' => null])->assertOk()
            ->assertJsonPath('data.phone', null);
        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$member->id}", ['name' => ''])->assertUnprocessable();
    }

    public function test_replacing_or_removing_the_photo_keeps_the_old_file(): void
    {
        $first = $this->photo($this->noura->user);
        $second = $this->photo($this->noura->user);
        $member = $this->teamMember($this->noura);
        $member->media()->save($first->forceFill(['collection' => ProviderTeamMember::PHOTO]));

        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$member->id}", ['photo_media_id' => $second->id])->assertOk()
            ->assertJsonPath('data.photo.id', $second->id);
        $this->assertNull($first->fresh()->attachable_id);
        $this->assertNotNull(Media::query()->find($first->id)); // detached, not deleted

        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$member->id}", ['photo_media_id' => null])->assertOk()
            ->assertJsonPath('data.photo', null);
        $this->assertNull($second->fresh()->attachable_id);
    }

    public function test_disable_and_enable_keep_everything_and_write_provider_history(): void
    {
        $member = $this->teamMember($this->noura);
        $booking = $this->booking($this->noura);
        $this->setTeam($this->noura, $booking, [$member->id])->assertOk();

        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$member->id}", ['status' => 'disabled'])->assertOk()
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.disabled_at', now()->toIso8601ZuluString())
            ->assertJsonPath('data.active_assignments_count', 1);
        $this->assertSame(1, BookingAssignment::query()->count()); // D233: kept

        // Same value again: no-op, no second history row.
        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$member->id}", ['status' => 'disabled'])->assertOk();
        $this->asProvider($this->noura)->patchJson(self::TEAM."/{$member->id}", ['status' => 'active'])->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.disabled_at', null);

        $events = ActivityEvent::query()->where('subject_type', $member->getMorphClass())->where('subject_id', $member->id)->pluck('event')->all();
        $this->assertSame(['team_member_disabled', 'team_member_enabled'], $events);
        $this->assertSame(['provider'], ActivityEvent::query()->whereIn('event', $events)->pluck('visibility')->map->value->unique()->values()->all());
        $this->assertSame(TeamMemberStatus::Active, $member->fresh()->status);
    }

    public function test_there_is_no_delete_route(): void
    {
        $member = $this->teamMember($this->noura);

        $this->asProvider($this->noura)->deleteJson(self::TEAM."/{$member->id}")->assertStatus(405);
        $this->assertNotNull($member->fresh());
    }
}
