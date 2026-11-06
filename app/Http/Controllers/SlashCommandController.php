<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MessagePoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Runs "/command" text. Replies are shown only to the person who typed them. */
class SlashCommandController extends Controller
{
    private const HELP = "/remind 30m Call the bank — remind yourself (m, h or d)\n"
        ."/remind list — show your reminders\n"
        ."/remind cancel 12 — cancel a reminder by its number\n"
        ."/me is at lunch — post an action\n"
        ."/topic New topic — set this channel's topic\n"
        ."/mute — mute or unmute this channel\n"
        ."/away — switch between active and away\n"
        .'/help — show this list';

    public function run(Request $request, Workspace $workspace, Channel $channel, MessagePoster $poster): JsonResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user) && ! $channel->archived_at, 403);

        $data = $request->validate(['text' => ['required', 'string', 'max:1000']]);

        [$command, $args] = array_pad(preg_split('/\s+/', trim($data['text']), 2), 2, '');

        return match (strtolower($command)) {
            '/remind' => $this->remind($workspace, $user, trim($args)),
            '/me' => $this->action($channel, $user, trim($args), $poster),
            '/topic' => $this->topic($channel, trim($args)),
            '/mute' => $this->mute($channel, $user),
            '/away' => $this->away($user),
            '/help' => $this->reply(self::HELP),
            default => $this->reply("{$command} is not a command. Type /help to see what works."),
        };
    }

    private function remind(Workspace $workspace, User $user, string $args): JsonResponse
    {
        if ($args === 'list') {
            $pending = Reminder::where(['user_id' => $user->id, 'workspace_id' => $workspace->id])
                ->whereNull('sent_at')
                ->orderBy('remind_at')
                ->get()
                ->map(fn (Reminder $reminder) => "#{$reminder->id} {$reminder->remind_at->diffForHumans()}: {$reminder->text}");

            return $this->reply($pending->isEmpty()
                ? 'You have no reminders.'
                : $pending->implode("\n")."\nTo cancel one: /remind cancel 12");
        }

        if (preg_match('/^cancel\b\s*#?(\d*)$/iu', $args, $match)) {
            return $this->cancelReminder($workspace, $user, $match[1]);
        }

        if (! preg_match('/^(\d{1,4})\s*(m|min|mins|h|hr|hrs|d|day|days)\s+(.{1,500})$/iu', $args, $match)) {
            return $this->reply('Try: /remind 30m Call the bank');
        }

        $unit = strtolower($match[2])[0];
        $minutes = (int) $match[1] * ['m' => 1, 'h' => 60, 'd' => 1440][$unit];

        if ($minutes < 1 || $minutes > 525600) {
            return $this->reply('Choose a time between 1 minute and 1 year.');
        }

        $reminder = Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'text' => $match[3],
            'remind_at' => now()->addMinutes($minutes),
        ]);

        return $this->reply("Okay, I'll remind you {$reminder->remind_at->diffForHumans()}.");
    }

    /** Numbers are reminder ids, so a reminder firing cannot shift them onto a different one. */
    private function cancelReminder(Workspace $workspace, User $user, string $id): JsonResponse
    {
        if ($id === '') {
            return $this->reply('Try: /remind cancel 12. /remind list shows the numbers.');
        }

        $reminder = Reminder::where(['user_id' => $user->id, 'workspace_id' => $workspace->id])
            ->whereNull('sent_at')
            ->find((int) $id);

        if (! $reminder) {
            return $this->reply("You have no reminder #{$id}. /remind list shows the numbers.");
        }

        $reminder->delete();

        return $this->reply("Reminder cancelled: {$reminder->text}");
    }

    private function action(Channel $channel, User $user, string $args, MessagePoster $poster): JsonResponse
    {
        if ($args === '') {
            return $this->reply('Try: /me is at lunch');
        }

        $message = $poster->asUser($channel, $user, "_{$args}_");

        return response()->json(['message' => $message->load(['reactions', 'attachments'])->toPayload()]);
    }

    private function topic(Channel $channel, string $args): JsonResponse
    {
        if ($channel->type === Channel::DM) {
            return $this->reply('Direct messages have no topic.');
        }

        if (mb_strlen($args) > 250) {
            return $this->reply('A topic can have up to 250 characters.');
        }

        $channel->update(['topic' => $args ?: null]);

        return response()->json(['text' => $args ? 'Topic updated.' : 'Topic cleared.', 'reload' => true]);
    }

    private function mute(Channel $channel, User $user): JsonResponse
    {
        $muted = ! $channel->members()->whereKey($user->id)->first()->pivot->muted;
        $channel->members()->updateExistingPivot($user->id, ['muted' => $muted]);

        return response()->json(['text' => $muted ? 'Channel muted.' : 'Channel unmuted.', 'reload' => true]);
    }

    private function away(User $user): JsonResponse
    {
        $user->update(['is_away' => ! $user->is_away]);

        return response()->json(['text' => $user->is_away ? 'You are now away.' : 'You are now active.', 'reload' => true]);
    }

    private function reply(string $text): JsonResponse
    {
        return response()->json(['text' => $text]);
    }
}
