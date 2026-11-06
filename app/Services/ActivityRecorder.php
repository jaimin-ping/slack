<?php

namespace App\Services;

use App\Events\ActivityCreated;
use App\Jobs\SendPushNotification;
use App\Models\Activity;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Str;
use Throwable;

class ActivityRecorder
{
    /** Records who should hear about a new message. Only channel members can be notified. */
    public function record(Message $message): void
    {
        $channel = $message->channel;
        $members = $channel->members()->get(['users.id', 'users.name', 'users.is_away']);
        $memberIds = $members->pluck('id')->all();
        $recipients = [];

        if ($message->parent_id) {
            $parent = Message::find($message->parent_id);
            $participants = Message::where('parent_id', $message->parent_id)->pluck('user_id')
                ->push($parent?->user_id)
                ->filter()
                ->unique();

            foreach ($participants as $id) {
                $recipients[$id] = 'reply';
            }
        }

        $members->each(function (User $member) use ($message, &$recipients) {
            $pattern = '/(?<![\w@])@'.preg_quote($member->name, '/').'(?!\w)/u';

            if (preg_match($pattern, $message->body)) {
                $recipients[$member->id] = 'mention';
            }
        });

        // @channel reaches every member; @here skips people marked away. Neither applies to a DM.
        if ($channel->type !== Channel::DM) {
            $everyone = preg_match('/(?<![\w@])@channel(?!\w)/u', $message->body);
            $present = preg_match('/(?<![\w@])@here(?!\w)/u', $message->body);

            if ($everyone || $present) {
                $members->reject(fn (User $member) => ! $everyone && $member->is_away)
                    ->each(function (User $member) use (&$recipients) {
                        $recipients[$member->id] = 'mention';
                    });
            }
        }

        UserGroup::with('members:users.id')->where('workspace_id', $channel->workspace_id)->get()
            ->filter(fn (UserGroup $group) => preg_match('/(?<![\w@])@'.preg_quote($group->handle, '/').'(?![\w-])/u', $message->body))
            ->each(function (UserGroup $group) use (&$recipients) {
                foreach ($group->members as $member) {
                    $recipients[$member->id] = 'mention';
                }
            });

        unset($recipients[$message->user_id]);
        $recipients = array_intersect_key($recipients, array_flip($memberIds));

        foreach ($recipients as $userId => $type) {
            Activity::create([
                'user_id' => $userId,
                'workspace_id' => $channel->workspace_id,
                'message_id' => $message->id,
                'type' => $type,
            ]);
        }

        if ($recipients !== []) {
            try {
                event(new ActivityCreated($message, $channel->workspace_id, array_keys($recipients)));
            } catch (Throwable $e) {
                report($e); // The activity is saved; the live alert is best effort.
            }
        }

        $this->push($message, $channel, $members, array_keys($recipients));
    }

    /** @param array<int, int> $activityUserIds */
    private function push(Message $message, Channel $channel, $members, array $activityUserIds): void
    {
        // Top-level messages also reach both people in a DM and anyone who asked for every message.
        $followers = [];

        if (! $message->parent_id) {
            $followers = $channel->type === Channel::DM
                ? $members->pluck('id')->all()
                : $channel->members()->wherePivot('notify_all', true)->wherePivot('muted', false)->pluck('users.id')->all();
        }

        $userIds = array_values(array_diff(array_unique([...$activityUserIds, ...$followers]), [$message->user_id]));

        if ($userIds === []) {
            return;
        }

        $sender = $message->user?->name ?? $message->bot_name ?? 'App';

        SendPushNotification::dispatch($userIds, [
            'title' => $channel->type === Channel::DM ? $sender : "{$sender} in #{$channel->name}",
            'body' => Str::limit($message->body ?: ($message->cards[0]['title'] ?? 'New message'), 140),
            'tag' => 'message-'.$message->id,
            'slug' => $channel->workspace->slug,
            'channel_id' => $channel->id,
            'thread' => $message->parent_id,
            'url' => route('channels.show', array_filter([
                'workspace' => $channel->workspace->slug,
                'channel' => $channel->id,
                'thread' => $message->parent_id,
            ]), false),
        ])->afterResponse();
    }
}
