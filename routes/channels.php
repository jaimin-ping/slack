<?php

use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('channel.{channel}', function ($user, Channel $channel) {
    return $channel->hasMember($user);
});

// Presence channel for an audio huddle; WebRTC signalling travels over it as client events.
Broadcast::channel('huddle.{channel}', function ($user, Channel $channel) {
    return $channel->hasMember($user) ? ['id' => $user->id, 'name' => $user->name] : false;
});

// Presence channel: drives the online/offline dots.
Broadcast::channel('workspace.{workspace}', function ($user, Workspace $workspace) {
    return $workspace->hasMember($user) ? ['id' => $user->id, 'name' => $user->name] : false;
});
