<?php

namespace App\Http\Controllers;

use App\Events\HuddleReplied;
use App\Events\HuddleStarted;
use App\Events\SidebarChanged;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\CustomEmoji;
use App\Models\Draft;
use App\Models\Message;
use App\Models\SavedMessage;
use App\Models\SidebarSection;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ChannelController extends Controller
{
    private const PAGE_SIZE = 50;

    private const THREAD_LIMIT = 200;

    public function show(Request $request, Workspace $workspace, Channel $channel): Response
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user) && $workspace->hasMember($user), 403);

        $page = $this->page($channel);
        $lastReadId = $channel->members()->whereKey($user->id)->first()->pivot->last_read_message_id;
        $this->markRead($channel, $user);

        return Inertia::render('Workspace/Show', [
            'workspace' => fn () => [
                ...$workspace->only('id', 'name', 'slug'),
                'is_owner' => $workspace->owner_id === $user->id,
                'is_admin' => $workspace->isAdmin($user),
            ],
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type,
                'dm_user_id' => $channel->dmPartnerId($user->id),
                'topic' => $channel->topic,
                'can_manage' => $channel->canManage($user, $workspace),
                'can_delete' => $channel->canManage($user, $workspace) && ! $channel->isDefault(),
                'can_leave' => $channel->type !== Channel::DM && ! $channel->isDefault(),
                'archived' => $channel->archived_at !== null,
            ],
            'sidebar' => fn () => $this->sidebar($workspace, $user),
            'members' => fn () => $this->memberList($workspace),
            'channelMemberIds' => fn () => $channel->members()->pluck('users.id'),
            'messages' => $page['messages'],
            'hasMore' => $page['hasMore'],
            'lastReadId' => $lastReadId,
            'draft' => Draft::where(['user_id' => $user->id, 'channel_id' => $channel->id])->value('body') ?? '',
            'openThreadId' => $request->integer('thread') ?: null,
            'vapidPublicKey' => config('services.webpush.public_key'),
            'extras' => fn () => $this->extras($workspace, $user),
            'savedIds' => fn () => SavedMessage::where('user_id', $user->id)->pluck('message_id'),
            'activityUnread' => fn () => Activity::where('user_id', $user->id)
                ->where('workspace_id', $workspace->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function update(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user) && $channel->type !== Channel::DM, 403);

        $data = $request->validate([
            'topic' => ['nullable', 'string', 'max:250'],
            'name' => ['sometimes', 'required', 'string', 'max:60'],
        ]);

        if ($request->has('topic')) {
            $channel->update(['topic' => $data['topic'] ?? null]);
        }

        if (isset($data['name'])) {
            abort_unless($channel->canManage($user, $workspace), 403);

            $name = Str::slug($data['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Use letters or numbers in the channel name.']);
            }
            if ($workspace->channels()->where('name', $name)->whereKeyNot($channel->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'A channel with this name already exists.']);
            }

            AuditLog::record($workspace, $user, 'channel.renamed', "#{$channel->name} to #{$name}");
            $channel->update(['name' => $name]);
            $this->notifyOthers($workspace, $channel, $user);
        }

        return back();
    }

    /** Public channels for the Browse page, plus private ones this person is already in. */
    public function browse(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $joined = $user->channels()->pluck('channels.id')->flip();

        $channels = $workspace->channels()
            ->where(fn ($query) => $query
                ->where('type', Channel::PUBLIC)
                ->orWhere(fn ($private) => $private
                    ->where('type', Channel::PRIVATE)
                    ->whereHas('members', fn ($members) => $members->whereKey($user->id))))
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (Channel $channel) => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type,
                'topic' => $channel->topic,
                'member_count' => $channel->members_count,
                'joined' => $joined->has($channel->id),
                'archived' => $channel->archived_at !== null,
                'can_manage' => $channel->canManage($user, $workspace),
            ]);

        return response()->json($channels);
    }

    public function join(Request $request, Workspace $workspace, Channel $channel): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user) && $channel->type === Channel::PUBLIC && ! $channel->archived_at, 403);

        $channel->members()->syncWithoutDetaching([$user->id]);

        return $this->toChannel($request, $workspace, $channel);
    }

    public function archive(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user) && $channel->canManage($user, $workspace), 403);
        abort_if($channel->isDefault(), 422);

        $channel->update(['archived_at' => now()]);
        AuditLog::record($workspace, $user, 'channel.archived', '#'.$channel->name);
        $this->notifyOthers($workspace, $channel, $user);

        return redirect()->route('workspaces.show', $workspace->slug);
    }

    public function unarchive(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user) && $channel->canManage($user, $workspace), 403);

        $channel->update(['archived_at' => null]);
        AuditLog::record($workspace, $user, 'channel.restored', '#'.$channel->name);
        $channel->members()->syncWithoutDetaching([$user->id]);
        $this->notifyOthers($workspace, $channel, $user);

        return redirect()->route('channels.show', [$workspace->slug, $channel->id]);
    }

    public function mute(Request $request, Workspace $workspace, Channel $channel): HttpResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user), 403);

        $current = (bool) $channel->members()->whereKey($user->id)->first()->pivot->muted;
        $channel->members()->updateExistingPivot($user->id, ['muted' => ! $current]);

        return response()->noContent();
    }

    /** The web app follows a redirect; the mobile app wants the id. */
    private function toChannel(Request $request, Workspace $workspace, Channel $channel): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['channel_id' => $channel->id])
            : redirect()->route('channels.show', [$workspace->slug, $channel->id]);
    }

    private function notifyOthers(Workspace $workspace, Channel $channel, User $user): void
    {
        $this->notifySidebar(
            $workspace,
            $channel->members()->pluck('users.id')->reject(fn ($id) => $id === $user->id)->values()->all(),
        );
    }

    public function addMembers(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        abort_unless($channel->hasMember($request->user()) && $channel->type === Channel::PRIVATE, 403);

        $data = $request->validate(['member_ids' => ['required', 'array'], 'member_ids.*' => ['integer']]);

        $ids = $workspace->members()->whereIn('users.id', $data['member_ids'])->pluck('users.id')->all();
        $channel->members()->syncWithoutDetaching($ids);
        $this->notifySidebar($workspace, $ids);

        return back();
    }

    public function leave(Request $request, Workspace $workspace, Channel $channel): RedirectResponse|HttpResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user) && $channel->type !== Channel::DM && ! $channel->isDefault(), 403);

        if ($channel->type === Channel::PRIVATE && $channel->members()->count() === 1) {
            throw ValidationException::withMessages(['leave' => 'You are the last member. Delete the channel instead.']);
        }

        $channel->members()->detach($user->id);

        return $request->expectsJson() ? response()->noContent() : redirect()->route('workspaces.show', $workspace->slug);
    }

    public function destroy(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user) && $channel->canManage($user, $workspace), 403);
        abort_if($channel->isDefault(), 422);

        $memberIds = $channel->members()->pluck('users.id')->reject(fn ($id) => $id === $user->id)->values()->all();

        AuditLog::record($workspace, $user, 'channel.deleted', '#'.$channel->name);
        $channel->deleteFiles();
        $channel->delete();
        $this->notifySidebar($workspace, $memberIds);

        return redirect()->route('workspaces.show', $workspace->slug);
    }

    /** Everything the mobile app needs to draw a workspace. */
    public function bootstrap(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        return response()->json([
            'workspace' => [
                ...$workspace->only('id', 'name', 'slug'),
                'is_owner' => $workspace->owner_id === $user->id,
                'is_admin' => $workspace->isAdmin($user),
            ],
            'channels' => $this->sidebar($workspace, $user),
            'members' => $this->memberList($workspace),
            'extras' => $this->extras($workspace, $user),
        ]);
    }

    public function notify(Request $request, Workspace $workspace, Channel $channel): HttpResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user), 403);

        $current = (bool) $channel->members()->whereKey($user->id)->first()->pivot->notify_all;
        $channel->members()->updateExistingPivot($user->id, ['notify_all' => ! $current]);

        return response()->noContent();
    }

    public function star(Request $request, Workspace $workspace, Channel $channel): HttpResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user), 403);

        $starred = $channel->members()->whereKey($user->id)->first()->pivot->starred_at;
        $channel->members()->updateExistingPivot($user->id, ['starred_at' => $starred ? null : now()]);

        return response()->noContent();
    }

    public function search(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $pattern = '%'.addcslashes(mb_strtolower($term), '\\%_').'%';

        $results = Message::query()
            ->with(['user:id,name', 'channel', 'reactions', 'attachments'])
            ->whereHas('channel', fn ($query) => $query
                ->where('workspace_id', $workspace->id)
                ->whereHas('members', fn ($members) => $members->whereKey($user->id)))
            ->whereRaw("lower(body) like ? escape '\\'", [$pattern])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $names = $workspace->members()->pluck('users.name', 'users.id');

        return response()->json($results->map(fn (Message $message) => [
            ...$message->toPayload(),
            'channel_label' => $message->channel->type === Channel::DM
                ? ($names[$message->channel->dmPartnerId($user->id)] ?? 'Direct message')
                : '#'.$message->channel->name,
        ]));
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'is_private' => ['boolean'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer'],
        ]);

        $name = Str::slug($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Use letters or numbers in the channel name.']);
        }
        if ($workspace->channels()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'A channel with this name already exists.']);
        }

        $isPrivate = $data['is_private'] ?? false;
        $memberIds = $isPrivate
            ? $workspace->members()->whereIn('users.id', [...($data['member_ids'] ?? []), $user->id])->pluck('users.id')
            : $workspace->members()->pluck('users.id');

        $channel = DB::transaction(function () use ($workspace, $name, $isPrivate, $user, $memberIds) {
            $channel = $workspace->channels()->create([
                'name' => $name,
                'type' => $isPrivate ? Channel::PRIVATE : Channel::PUBLIC,
                'created_by' => $user->id,
            ]);
            $channel->members()->attach($memberIds);

            return $channel;
        });

        $this->notifySidebar($workspace, $memberIds->reject(fn ($id) => $id === $user->id)->values()->all());
        AuditLog::record($workspace, $user, 'channel.created', ($isPrivate ? 'Private: ' : '#').$name);

        return $this->toChannel($request, $workspace, $channel);
    }

    public function direct(Request $request, Workspace $workspace, User $user): RedirectResponse|JsonResponse
    {
        $me = $request->user();
        abort_unless($workspace->hasMember($me) && $workspace->hasMember($user), 403);
        if ($me->id === $user->id) {
            $channel = Channel::selfDm($workspace, $me);

            return $request->expectsJson()
                ? response()->json(['channel_id' => $channel->id])
                : redirect()->route('channels.show', [$workspace->slug, $channel->id]);
        }

        $channel = $workspace->channels()->createOrFirst(
            ['dm_key' => Channel::dmKey($me->id, $user->id)],
            ['type' => Channel::DM, 'created_by' => $me->id],
        );

        if ($channel->wasRecentlyCreated) {
            $channel->members()->attach([$me->id, $user->id]);
            $this->notifySidebar($workspace, [$user->id]);
        }

        if ($request->expectsJson()) {
            return response()->json(['channel_id' => $channel->id]);
        }

        return redirect()->route('channels.show', [$workspace->slug, $channel->id]);
    }

    public function messages(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        abort_unless($channel->hasMember($request->user()), 403);

        return response()->json($this->page($channel, $request->integer('before') ?: null));
    }

    public function thread(Request $request, Workspace $workspace, Channel $channel, Message $message): JsonResponse
    {
        abort_unless($channel->hasMember($request->user()), 403);
        abort_if($message->parent_id !== null, 404);

        $message->loadCount('replies')->loadMax('replies', 'created_at');

        return response()->json([
            'parent' => $message->toPayload(),
            'replies' => $message->replies()
                ->with(['user:id,name', 'reactions', 'attachments'])
                ->orderBy('id')
                ->limit(self::THREAD_LIMIT)
                ->get()
                ->map(fn (Message $reply) => $reply->toPayload()),
        ]);
    }

    public function pins(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        abort_unless($channel->hasMember($request->user()), 403);

        return response()->json(
            $channel->messages()
                ->whereNotNull('pinned_at')
                ->with(['user:id,name', 'reactions', 'attachments'])
                ->latest('pinned_at')
                ->limit(self::PAGE_SIZE)
                ->get()
                ->map(fn (Message $message) => $message->toPayload()),
        );
    }

    public function read(Request $request, Workspace $workspace, Channel $channel): HttpResponse
    {
        abort_unless($channel->hasMember($request->user()), 403);
        $this->markRead($channel, $request->user());

        return response()->noContent();
    }

    /** Rings the other people in the conversation. */
    public function huddle(Request $request, Workspace $workspace, Channel $channel): HttpResponse
    {
        abort_unless($channel->hasMember($request->user()), 403);

        event(new HuddleStarted($channel, $request->user()));

        return response()->noContent();
    }

    /** Tells the person who rang that someone declined or is on their way. */
    public function huddleReply(Request $request, Workspace $workspace, Channel $channel): HttpResponse
    {
        abort_unless($channel->hasMember($request->user()), 403);

        $data = $request->validate([
            'to' => ['required', 'integer'],
            'status' => ['required', 'in:declined,soon'],
        ]);

        event(new HuddleReplied($channel, $request->user(), $data['to'], $data['status']));

        return response()->noContent();
    }

    /** @return array{messages: array<int, array<string, mixed>>, hasMore: bool} */
    private function page(Channel $channel, ?int $before = null): array
    {
        $rows = $channel->messages()
            ->whereNull('parent_id')
            ->with(['user:id,name', 'reactions', 'attachments'])
            ->withCount('replies')
            ->withMax('replies', 'created_at')
            ->when($before, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        return [
            'messages' => $rows->take(self::PAGE_SIZE)->reverse()->values()
                ->map(fn (Message $message) => $message->toPayload())->all(),
            'hasMore' => $rows->count() > self::PAGE_SIZE,
        ];
    }

    private function markRead(Channel $channel, User $user): void
    {
        $latest = $channel->messages()->whereNull('parent_id')->max('id');

        if ($latest) {
            $channel->members()->updateExistingPivot($user->id, ['last_read_message_id' => $latest]);
        }
    }

    /** @return array<string, mixed> */
    private function extras(Workspace $workspace, User $user): array
    {
        return [
            'groups' => UserGroup::with('members:users.id')->where('workspace_id', $workspace->id)->orderBy('handle')->get()
                ->map(fn (UserGroup $group) => [
                    'id' => $group->id,
                    'handle' => $group->handle,
                    'member_ids' => $group->members->pluck('id'),
                ]),
            'emojis' => CustomEmoji::where('workspace_id', $workspace->id)->orderBy('name')->get()
                ->map(fn (CustomEmoji $emoji) => [
                    'id' => $emoji->id,
                    'name' => $emoji->name,
                    'url' => route('emoji.show', $emoji->id, false),
                ]),
            'sections' => SidebarSection::where(['user_id' => $user->id, 'workspace_id' => $workspace->id])
                ->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function memberList(Workspace $workspace)
    {
        return $workspace->members()->orderBy('name')
            ->get([
                'users.id', 'users.name', 'users.status_emoji', 'users.status_text', 'users.status_expires_at', 'users.is_away',
                'users.avatar_path', 'users.title', 'users.phone', 'users.dnd_until', 'users.dnd_from', 'users.dnd_to', 'users.timezone',
            ])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'status_emoji' => $member->statusIsActive() ? $member->status_emoji : null,
                'status_text' => $member->statusIsActive() ? $member->status_text : null,
                'is_away' => $member->is_away,
                'in_dnd' => $member->isDnd(),
                'title' => $member->title,
                'phone' => $member->phone,
                'avatar_url' => $member->avatarUrl(),
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function sidebar(Workspace $workspace, User $user): array
    {
        $unread = DB::table('channel_user as cu')
            ->join('messages as m', 'm.channel_id', '=', 'cu.channel_id')
            ->where('cu.user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('m.user_id')->orWhere('m.user_id', '!=', $user->id))
            ->whereNull('m.parent_id')
            ->whereNull('m.removed_at')
            ->whereRaw('m.id > coalesce(cu.last_read_message_id, 0)')
            ->groupBy('cu.channel_id')
            ->selectRaw('cu.channel_id, count(*) as unread')
            ->pluck('unread', 'channel_id');

        $settings = DB::table('channel_user')
            ->where('user_id', $user->id)
            ->get(['channel_id', 'starred_at', 'notify_all', 'muted', 'section_id'])
            ->keyBy('channel_id');

        return $workspace->channels()
            ->whereHas('members', fn ($query) => $query->whereKey($user->id))
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Channel $channel) => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type,
                'dm_user_id' => $channel->dmPartnerId($user->id),
                'unread' => (int) ($unread[$channel->id] ?? 0),
                'starred' => isset($settings[$channel->id]->starred_at),
                'notify_all' => (bool) ($settings[$channel->id]->notify_all ?? false),
                'muted' => (bool) ($settings[$channel->id]->muted ?? false),
                'section_id' => $settings[$channel->id]->section_id ?? null,
            ])
            ->all();
    }

    /** @param array<int, int> $userIds */
    private function notifySidebar(Workspace $workspace, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        try {
            event(new SidebarChanged($workspace->id, $userIds));
        } catch (Throwable $e) {
            report($e); // The change is saved; the live update is best effort.
        }
    }
}
