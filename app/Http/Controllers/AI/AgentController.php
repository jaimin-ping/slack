<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AgentCapabilities;
use App\Services\AI\AgentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Members manage their own agents here. The web app and the mobile API share these actions.
 * An agent is personal: only its owner can see or change its configuration.
 */
class AgentController extends Controller
{
    public function __construct(private AgentManager $agents) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless($workspace->hasMember($request->user()), 403);

        return response()->json(
            AiAgent::with(['permissions', 'sources.channel'])
                ->where(['workspace_id' => $workspace->id, 'owner_id' => $request->user()->id])
                ->orderBy('name')
                ->get()
                ->map(fn (AiAgent $agent) => $agent->toPayload()),
        );
    }

    /** Everything the create/edit form needs: actions, modes, presets and the conversations you can grant. */
    public function options(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $channels = $workspace->channels()
            ->whereHas('members', fn ($query) => $query->whereKey($user->id))
            ->whereNull('archived_at')
            ->orderBy('type')->orderBy('name')
            ->get(['id', 'name', 'type', 'dm_key']);

        $partnerNames = User::whereIn('id', $channels->map(fn (Channel $c) => $c->dmPartnerId($user->id))->filter())
            ->pluck('name', 'id');

        return response()->json([
            'statuses' => AgentCapabilities::STATUSES,
            'modes' => AgentCapabilities::MODES,
            'policies' => AgentCapabilities::POLICIES,
            'actions' => AgentCapabilities::ACTIONS,
            'presets' => collect(AgentCapabilities::MODES)->mapWithKeys(fn ($mode) => [$mode => AgentCapabilities::preset($mode)]),
            'avatar_presets' => AgentCapabilities::AVATAR_PRESETS,
            'default_settings' => AgentCapabilities::defaultSettings(),
            'channels' => $channels->map(function (Channel $channel) use ($user, $partnerNames) {
                $partnerId = $channel->dmPartnerId($user->id);

                return [
                    'id' => $channel->id,
                    'type' => $channel->type,
                    'label' => match (true) {
                        $channel->type !== Channel::DM => '#'.$channel->name,
                        $partnerId === $user->id => 'Notes to yourself',
                        default => $partnerNames[$partnerId] ?? 'Direct message',
                    },
                ];
            })->values(),
        ]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $data = $request->validate($this->rules($workspace, $user));

        return response()->json($this->agents->create($workspace, $user, $data)->toPayload(), 201);
    }

    public function show(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);

        return response()->json($agent->toPayload());
    }

    public function update(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);

        $data = $request->validate($this->rules($workspace, $request->user(), $agent));

        return response()->json($this->agents->update($agent, $data)->toPayload());
    }

    public function destroy(Request $request, Workspace $workspace, AiAgent $agent): Response
    {
        $this->authorizeOwner($request, $workspace, $agent);

        $this->agents->delete($agent, $request->user());

        return response()->noContent();
    }

    /** The owner can set any status. A workspace admin can stop someone else's agent, but not restart it. */
    public function status(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $user = $request->user();
        abort_unless($agent->workspace_id === $workspace->id && $workspace->hasMember($user), 404);

        $data = $request->validate(['status' => ['required', Rule::in(AgentCapabilities::STATUSES)]]);

        $isOwner = $agent->isOwnedBy($user);
        $adminStop = $workspace->isAdmin($user) && $data['status'] !== AgentCapabilities::STATUS_ACTIVE;
        abort_unless($isOwner || $adminStop, 403);

        $this->agents->setStatus($agent, $data['status'], $user);

        return response()->json($isOwner ? $agent->toPayload() : ['id' => $agent->id, 'status' => $agent->status]);
    }

    public function sources(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);

        $data = $request->validate([
            'channel_ids' => ['present', 'array'],
            'channel_ids.*' => ['integer'],
        ]);

        return response()->json($this->agents->syncSources($agent, $data['channel_ids'])->toPayload());
    }

    public function permissions(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);

        $data = $request->validate($this->permissionRules());

        $this->agents->setPermissions($agent, $data['mode'] ?? null, $data['permissions'] ?? []);

        return response()->json($agent->fresh()->toPayload());
    }

    /** Upload a photo, or pick one of the built-in avatars instead. */
    public function storeAvatar(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);

        $data = $request->validate([
            'photo' => ['required_without:preset', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'preset' => ['required_without:photo', Rule::in(AgentCapabilities::AVATAR_PRESETS)],
        ]);

        if ($request->hasFile('photo')) {
            $this->agents->setAvatarUpload($agent, $request->file('photo'));
        } else {
            $this->agents->removeAvatar($agent);
            $agent->update(['avatar_preset' => $data['preset']]);
        }

        return response()->json($agent->fresh()->toPayload());
    }

    public function destroyAvatar(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);

        return response()->json($this->agents->removeAvatar($agent)->fresh()->toPayload());
    }

    /** Anyone in the agent's workspace can see its avatar, since its messages appear in shared conversations. */
    public function avatar(Request $request, AiAgent $agent): StreamedResponse
    {
        abort_unless($agent->avatar_path && $agent->workspace->hasMember($request->user()), 404);

        return Storage::disk('local')->response($agent->avatar_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function authorizeOwner(Request $request, Workspace $workspace, AiAgent $agent): void
    {
        // A wrong workspace looks like a missing agent, so ids from other workspaces reveal nothing.
        abort_unless($agent->workspace_id === $workspace->id && $agent->isOwnedBy($request->user()), 404);
        abort_unless($workspace->hasMember($request->user()), 403);
    }

    private function rules(Workspace $workspace, User $owner, ?AiAgent $agent = null): array
    {
        $required = $agent ? 'sometimes' : 'required';

        return [
            'name' => [
                $required, 'string', 'min:2', 'max:80', 'regex:/^[\pL\pN][\pL\pN \-_.]*$/u',
                Rule::unique('ai_agents', 'name')
                    ->where(fn ($query) => $query->where('workspace_id', $workspace->id)->where('owner_id', $owner->id))
                    ->ignore($agent?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'personality' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'responsibilities' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'avatar_preset' => ['sometimes', 'nullable', Rule::in(AgentCapabilities::AVATAR_PRESETS)],
            'source_channel_ids' => ['sometimes', 'array'],
            'source_channel_ids.*' => ['integer'],
            ...AgentCapabilities::settingsRules(),
            ...$this->permissionRules(),
        ];
    }

    private function permissionRules(): array
    {
        return [
            'mode' => ['sometimes', Rule::in(AgentCapabilities::MODES)],
            'permissions' => ['sometimes', 'array:'.implode(',', array_keys(AgentCapabilities::ACTIONS))],
            'permissions.*' => [Rule::in(AgentCapabilities::POLICIES)],
        ];
    }
}
