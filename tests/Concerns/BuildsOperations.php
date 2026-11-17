<?php

namespace Tests\Concerns;

use App\Enums\AdminRole;
use App\Enums\MessageType;
use App\Enums\TicketStatus;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTickets;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Slice 17 helpers: staff with chosen permissions, tickets linked to a request/booking, conversations with messages.
 * Use together with BuildsBookings (market, providers, bookings).
 */
trait BuildsOperations
{
    protected function ops(AdminRole $role = AdminRole::OperationsSupport): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();
        $admin->assignRole($role->value);

        return $admin;
    }

    /**
     * A staff member holding exactly these permissions (no role).
     *
     * @param  list<string>  $permissions
     */
    protected function staffWith(array $permissions): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();
        $admin->givePermissionTo($permissions);

        return $admin;
    }

    protected function asStaff(User $staff): static
    {
        return $this->actingAsApi($staff)->withHeader('Accept-Language', 'en');
    }

    protected function ticket(User $user, ?string $relatedType = null, ?int $relatedId = null, TicketStatus $status = TicketStatus::Open): SupportTicket
    {
        [$ticket] = app(SupportTickets::class)->create($user, (string) Str::uuid(), [
            'topic' => 'service_quality', 'subject' => 'Late arrival', 'message' => 'She arrived late.',
            'related_type' => $relatedType, 'related_id' => $relatedId,
        ]);
        if ($status !== TicketStatus::Open) {
            $ticket->forceFill(['status' => $status])->save();
        }

        return $ticket->refresh();
    }

    protected function conversationFor(ServiceRequest $request, Provider $provider, int $messages = 3): Conversation
    {
        $conversation = Conversation::query()->create([
            'service_request_id' => $request->id, 'client_id' => $request->client_id, 'provider_id' => $provider->id,
        ]);
        for ($i = 1; $i <= $messages; $i++) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => $i % 2 ? $request->client_id : $provider->user_id,
                'type' => MessageType::Text,
                'body' => "Message {$i}",
                'client_uuid' => (string) Str::uuid(),
            ]);
            $conversation->update(['last_message_id' => $message->id, 'last_message_at' => now()]);
        }

        return $conversation;
    }

    protected function inProgress(Provider $provider, Booking $booking): Booking
    {
        $this->at(self::EVENT_STARTS);
        $this->startBooking($provider, $booking)->assertOk();

        return $booking->refresh();
    }
}
