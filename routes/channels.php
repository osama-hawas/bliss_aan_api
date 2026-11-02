<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Notifications and status changes for one user.
Broadcast::channel('user.{id}', fn (User $user, int $id) => $user->id === $id);

// Chat channel: authorized by the conversation Policy in Slice 7. Deny until then.
Broadcast::channel('conversation.{id}', fn (User $user, int $id) => false);
