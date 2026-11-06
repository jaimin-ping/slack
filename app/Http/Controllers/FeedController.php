<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Draft;
use App\Models\Message;
use App\Models\SavedMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/** The workspace-wide lists: Activity, Threads, Drafts and Files. */
class FeedController extends Controller
{
    private const LIMIT = 50;

    public function activity(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $names = $this->names($workspace);

        $items = Activity::where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->with(['message.user:id,name', 'message.channel', 'message.reactions', 'message.attachments'])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'type' => $activity->type,
                'read' => $activity->read_at !== null,
                'message' => $activity->message->toPayload(),
                'channel_label' => $this->label($activity->message->channel, $user, $names),
            ]);

        return response()->json($items);
    }

    public function readActivity(Request $request, Workspace $workspace): Response
    {
        abort_unless($workspace->hasMember($request->user()), 403);

        Activity::where('user_id', $request->user()->id)
            ->where('workspace_id', $workspace->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function threads(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $names = $this->names($workspace);

        $threads = Message::query()
            ->whereNull('parent_id')
            ->whereHas('replies')
            ->whereHas('channel', fn (Builder $query) => $this->visibleChannels($query, $workspace, $user))
            ->where(fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->orWhereHas('replies', fn (Builder $replies) => $replies->where('user_id', $user->id)))
            ->with(['user:id,name', 'channel', 'reactions', 'attachments'])
            ->withCount('replies')
            ->withMax('replies', 'created_at')
            ->orderByDesc('replies_max_created_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Message $message) => [
                ...$message->toPayload(),
                'channel_label' => $this->label($message->channel, $user, $names),
            ]);

        return response()->json($threads);
    }

    public function drafts(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $names = $this->names($workspace);

        $drafts = Draft::where('user_id', $user->id)
            ->whereHas('channel', fn (Builder $query) => $this->visibleChannels($query, $workspace, $user))
            ->with('channel')
            ->latest('updated_at')
            ->get()
            ->map(fn (Draft $draft) => [
                'id' => $draft->id,
                'channel_id' => $draft->channel_id,
                'channel_label' => $this->label($draft->channel, $user, $names),
                'body' => $draft->body,
                'updated_at' => $draft->updated_at->toIso8601String(),
            ]);

        return response()->json($drafts);
    }

    public function saveDraft(Request $request, Workspace $workspace, Channel $channel): Response
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user), 403);

        $body = trim((string) $request->validate(['body' => ['nullable', 'string', 'max:4000']])['body']);
        $key = ['user_id' => $user->id, 'channel_id' => $channel->id];

        $body === '' ? Draft::where($key)->delete() : Draft::updateOrCreate($key, ['body' => $body]);

        return response()->noContent();
    }

    public function saved(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $names = $this->names($workspace);

        $saved = SavedMessage::where('user_id', $user->id)
            ->whereHas('message.channel', fn (Builder $query) => $this->visibleChannels($query, $workspace, $user))
            ->with(['message.user:id,name', 'message.channel', 'message.reactions', 'message.attachments'])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SavedMessage $item) => [
                ...$item->message->toPayload(),
                'channel_label' => $this->label($item->message->channel, $user, $names),
            ]);

        return response()->json($saved);
    }

    public function files(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $names = $this->names($workspace);

        $files = Attachment::query()
            ->whereHas('message.channel', fn (Builder $query) => $this->visibleChannels($query, $workspace, $user))
            ->with(['message.user:id,name', 'message.channel'])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Attachment $file) => [
                ...$file->toPayload(),
                'channel_id' => $file->message->channel_id,
                'channel_label' => $this->label($file->message->channel, $user, $names),
                'sender' => $file->message->user?->name ?? $file->message->bot_name ?? 'App',
                'created_at' => $file->created_at->toIso8601String(),
            ]);

        return response()->json($files);
    }

    private function visibleChannels(Builder $query, Workspace $workspace, User $user): void
    {
        $query->where('workspace_id', $workspace->id)
            ->whereHas('members', fn (Builder $members) => $members->whereKey($user->id));
    }

    private function names(Workspace $workspace): Collection
    {
        return $workspace->members()->pluck('users.name', 'users.id');
    }

    private function label(Channel $channel, User $user, Collection $names): string
    {
        return $channel->type === Channel::DM
            ? ($names[$channel->dmPartnerId($user->id)] ?? 'Direct message')
            : '#'.$channel->name;
    }
}
