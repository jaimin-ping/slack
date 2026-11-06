<?php

namespace App\Http\Controllers;

use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Events\ReactionUpdated;
use App\Jobs\FetchLinkPreview;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Draft;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\SavedMessage;
use App\Models\Workspace;
use App\Services\ActivityRecorder;
use App\Services\LinkPreviewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class MessageController extends Controller
{
    private const MAX_FILES = 3;

    private const MAX_FILE_KB = 10240;

    public function store(Request $request, Workspace $workspace, Channel $channel, ActivityRecorder $activity): JsonResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user) && ! $channel->archived_at, 403);

        $data = $request->validate([
            'body' => ['nullable', 'required_without:files', 'string', 'max:4000'],
            'files' => ['array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.self::MAX_FILE_KB],
            'parent_id' => [
                'nullable',
                'integer',
                // Replies attach to a top-level message in this channel; threads do not nest.
                Rule::exists('messages', 'id')->where('channel_id', $channel->id)->whereNull('parent_id'),
            ],
        ]);

        $parentId = $data['parent_id'] ?? null;

        $message = DB::transaction(function () use ($request, $channel, $user, $data, $parentId) {
            $message = $channel->messages()->create([
                'user_id' => $user->id,
                'body' => trim($data['body'] ?? ''),
                'parent_id' => $parentId,
            ]);

            foreach ($request->file('files', []) as $file) {
                $message->attachments()->create([
                    'name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'path' => $file->store('attachments/'.$channel->id, 'local'),
                    'mime' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);
            }

            return $message;
        });

        $message->setRelation('user', $user);

        if ($parentId) {
            $message->parent_reply_count = Message::where('parent_id', $parentId)->count();
        } else {
            $channel->members()->updateExistingPivot($user->id, ['last_read_message_id' => $message->id]);
        }

        if (! $parentId) {
            Draft::where(['user_id' => $user->id, 'channel_id' => $channel->id])->delete();
        }

        try {
            event(new MessageSent($message));
        } catch (Throwable $e) {
            report($e); // The message is saved even if the WebSocket server is down.
        }

        $activity->record($message);

        if (app(LinkPreviewer::class)->firstUrl($message->body)) {
            FetchLinkPreview::dispatch($message->id)->afterResponse();
        }

        return response()->json($message->toPayload(), 201);
    }

    public function pin(Request $request, Workspace $workspace, Channel $channel, Message $message): JsonResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user), 403);
        abort_if($message->removed_at !== null, 404);

        $message->update([
            'pinned_at' => $message->pinned_at ? null : now(),
            'pinned_by' => $message->pinned_at ? null : $user->id,
        ]);

        return response()->json($this->announceUpdate($message));
    }

    public function save(Request $request, Workspace $workspace, Channel $channel, Message $message): JsonResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user), 403);

        $key = ['user_id' => $user->id, 'message_id' => $message->id];
        $existing = SavedMessage::where($key)->first();
        $existing ? $existing->delete() : SavedMessage::create($key);

        return response()->json(['saved' => ! $existing]);
    }

    public function forward(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
        ActivityRecorder $activity,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($channel->hasMember($user), 403);
        abort_if($message->removed_at !== null, 404);

        $data = $request->validate([
            'channel_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $target = $workspace->channels()->whereKey($data['channel_id'])->whereNull('archived_at')->first();
        abort_unless($target && $target->hasMember($user), 403);

        $author = $message->user?->name ?? $message->bot_name ?? 'App';
        $source = $channel->type === Channel::DM ? 'a direct message' : '#'.$channel->name;

        $forwarded = $target->messages()->create([
            'user_id' => $user->id,
            'body' => trim($data['comment'] ?? ''),
            'cards' => [[
                'color' => '#94a3b8',
                'title' => "Forwarded from {$author} in {$source}",
                'text' => $message->body ?: ($message->cards[0]['title'] ?? ''),
                'fields' => [],
            ]],
        ]);
        $forwarded->setRelation('user', $user);

        try {
            event(new MessageSent($forwarded));
        } catch (Throwable $e) {
            report($e); // The message is saved even if the WebSocket server is down.
        }

        $activity->record($forwarded);

        return response()->json($forwarded->toPayload(), 201);
    }

    public function update(Request $request, Workspace $workspace, Channel $channel, Message $message): JsonResponse
    {
        $user = $request->user();
        abort_unless($message->user_id === $user->id && $channel->hasMember($user), 403);
        abort_if($message->removed_at !== null, 404);

        $hasFiles = $message->attachments()->exists();
        $data = $request->validate(['body' => [$hasFiles ? 'nullable' : 'required', 'string', 'max:4000']]);

        $message->update(['body' => trim($data['body'] ?? ''), 'edited_at' => now()]);

        return response()->json($this->announceUpdate($message));
    }

    public function destroy(Request $request, Workspace $workspace, Channel $channel, Message $message): HttpResponse
    {
        $user = $request->user();
        $isAuthor = $message->user_id === $user->id;
        abort_unless(($isAuthor || $workspace->isAdmin($user)) && $channel->hasMember($user), 403);

        // An admin removing someone else's message is recorded, without its text.
        if (! $isAuthor) {
            $author = $message->user?->name ?? $message->bot_name ?? 'App';
            $place = $channel->type === Channel::DM ? 'a direct message' : '#'.$channel->name;
            AuditLog::record($workspace, $user, 'message.moderated', "Message by {$author} in {$place}");
        }

        $message->deleteAttachments();

        // A message with replies leaves a placeholder, so the conversation stays readable.
        if ($message->replies()->exists()) {
            $message->reactions()->delete();
            $message->update(['body' => '', 'cards' => null, 'removed_at' => now()]);
            $this->announceUpdate($message);

            return response()->noContent();
        }

        $parent = $message->parent_id ? Message::find($message->parent_id) : null;
        $message->delete();

        $count = $parent ? $parent->replies()->count() : null;
        $this->announceDelete($channel, $message->id, $parent?->id, $count);

        // The last reply under a deleted parent removes the placeholder as well.
        if ($parent?->removed_at && $count === 0) {
            $parent->delete();
            $this->announceDelete($channel, $parent->id);
        }

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function announceUpdate(Message $message): array
    {
        $message->loadMissing(['user', 'attachments'])->load('reactions')
            ->loadCount('replies')->loadMax('replies', 'created_at');

        try {
            event(new MessageUpdated($message));
        } catch (Throwable $e) {
            report($e); // The change is saved even if the WebSocket server is down.
        }

        return $message->toPayload();
    }

    private function announceDelete(Channel $channel, int $id, ?int $parentId = null, ?int $count = null): void
    {
        try {
            event(new MessageDeleted($channel->id, $id, $parentId, $count));
        } catch (Throwable $e) {
            report($e); // The delete is saved even if the WebSocket server is down.
        }
    }

    public function react(Request $request, Workspace $workspace, Channel $channel, Message $message): JsonResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user), 403);
        abort_if($message->removed_at !== null, 404);

        $data = $request->validate(['emoji' => ['required', 'string', 'max:16', 'regex:'.Reaction::EMOJI_PATTERN]]);

        $existing = $message->reactions()->where(['user_id' => $user->id, 'emoji' => $data['emoji']])->first();
        $existing ? $existing->delete() : $message->reactions()->create(['user_id' => $user->id, 'emoji' => $data['emoji']]);

        $message->load('reactions');

        try {
            event(new ReactionUpdated($message));
        } catch (Throwable $e) {
            report($e); // The reaction is saved even if the WebSocket server is down.
        }

        return response()->json(['id' => $message->id, 'reactions' => $message->reactionSummary()]);
    }
}
