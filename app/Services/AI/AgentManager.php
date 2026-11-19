<?php

namespace App\Services\AI;

use App\Events\AI\AgentStatusChanged;
use App\Models\AiAgent;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Every change to an agent's profile, access and permissions goes through here. */
class AgentManager
{
    private const PROFILE_FIELDS = ['name', 'description', 'personality', 'instructions', 'responsibilities'];

    /** @param  array<string, mixed>  $data */
    public function create(Workspace $workspace, User $owner, array $data): AiAgent
    {
        $limit = (int) config('ai.limits.agents_per_member');

        if (AiAgent::where(['workspace_id' => $workspace->id, 'owner_id' => $owner->id])->count() >= $limit) {
            throw ValidationException::withMessages(['name' => "You can have up to {$limit} agents in this workspace."]);
        }

        $mode = $data['mode'] ?? 'suggest';

        $agent = DB::transaction(function () use ($workspace, $owner, $data, $mode) {
            $agent = AiAgent::create([
                ...array_intersect_key($data, array_flip(self::PROFILE_FIELDS)),
                'workspace_id' => $workspace->id,
                'owner_id' => $owner->id,
                'avatar_preset' => $data['avatar_preset'] ?? AgentCapabilities::AVATAR_PRESETS[0],
                'status' => AgentCapabilities::STATUS_ACTIVE,
                'mode' => $mode,
                'settings' => AgentCapabilities::mergeSettings([], $data['settings'] ?? []),
            ]);

            $this->writePermissions($agent, AgentCapabilities::preset($mode), $data['permissions'] ?? []);
            $this->writeSources($agent, $data['source_channel_ids'] ?? []);

            return $agent;
        });

        AuditLog::record($workspace, $owner, 'agent.created', $agent->name);

        return $agent->fresh();
    }

    /** @param  array<string, mixed>  $data */
    public function update(AiAgent $agent, array $data): AiAgent
    {
        DB::transaction(function () use ($agent, $data) {
            $changes = array_intersect_key($data, array_flip(self::PROFILE_FIELDS));

            if (array_key_exists('avatar_preset', $data)) {
                $changes['avatar_preset'] = $data['avatar_preset'];
            }

            if (array_key_exists('settings', $data)) {
                $changes['settings'] = AgentCapabilities::mergeSettings($agent->settings ?? [], $data['settings'] ?? []);
            }

            $agent->update($changes);

            if (array_key_exists('mode', $data) || array_key_exists('permissions', $data)) {
                $this->setPermissions($agent, $data['mode'] ?? null, $data['permissions'] ?? []);
            }

            if (array_key_exists('source_channel_ids', $data)) {
                $this->writeSources($agent, $data['source_channel_ids'] ?? []);
            }
        });

        return $agent->fresh();
    }

    /**
     * Choosing a mode resets every action to that mode's preset; overrides are then applied on top.
     *
     * @param  array<string, string>  $overrides
     */
    public function setPermissions(AiAgent $agent, ?string $mode, array $overrides = []): void
    {
        if ($mode !== null) {
            $agent->update(['mode' => $mode]);
            $base = AgentCapabilities::preset($mode);
        } else {
            $base = $agent->permissions()->pluck('policy', 'action')->all() + AgentCapabilities::preset($agent->mode);
        }

        $this->writePermissions($agent, $base, $overrides);
        $agent->unsetRelation('permissions');
    }

    /** Pausing keeps tasks, memory and history; running work checks the status again before each step. */
    public function setStatus(AiAgent $agent, string $status, User $actor): AiAgent
    {
        if ($agent->status === $status) {
            return $agent;
        }

        $agent->update([
            'status' => $status,
            'paused_at' => $status === AgentCapabilities::STATUS_PAUSED ? now() : null,
        ]);

        AuditLog::record($agent->workspace, $actor, "agent.{$status}", $agent->name);

        try {
            event(new AgentStatusChanged($agent));
        } catch (Throwable $e) {
            report($e); // The status is saved; the live update is best effort.
        }

        return $agent;
    }

    /** @param  array<int, int>  $channelIds */
    public function syncSources(AiAgent $agent, array $channelIds): AiAgent
    {
        DB::transaction(fn () => $this->writeSources($agent, $channelIds));

        return $agent->fresh();
    }

    public function setAvatarUpload(AiAgent $agent, UploadedFile $file): AiAgent
    {
        $previous = $agent->avatar_path;
        $agent->update(['avatar_path' => $file->store('agent-avatars', 'local')]);

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return $agent;
    }

    public function removeAvatar(AiAgent $agent): AiAgent
    {
        if ($agent->avatar_path) {
            Storage::disk('local')->delete($agent->avatar_path);
        }

        $agent->update(['avatar_path' => null]);

        return $agent;
    }

    public function delete(AiAgent $agent, User $actor): void
    {
        AuditLog::record($agent->workspace, $actor, 'agent.deleted', $agent->name);
        $this->removeAvatar($agent);
        $agent->delete();
    }

    /**
     * @param  array<string, string>  $base
     * @param  array<string, string>  $overrides
     */
    private function writePermissions(AiAgent $agent, array $base, array $overrides): void
    {
        foreach (array_keys(AgentCapabilities::ACTIONS) as $action) {
            $policy = $overrides[$action] ?? $base[$action] ?? AgentCapabilities::DENY;

            $agent->permissions()->updateOrCreate(['action' => $action], ['policy' => $policy]);
        }
    }

    /**
     * An agent can only be given conversations its owner belongs to, in its own workspace.
     *
     * @param  array<int, int>  $channelIds
     */
    private function writeSources(AiAgent $agent, array $channelIds): void
    {
        $channelIds = array_values(array_unique(array_map('intval', $channelIds)));
        $limit = (int) config('ai.limits.sources_per_agent');

        if (count($channelIds) > $limit) {
            throw ValidationException::withMessages(['source_channel_ids' => "An agent can watch up to {$limit} conversations."]);
        }

        $owner = $agent->owner;
        $allowed = Channel::where('workspace_id', $agent->workspace_id)
            ->whereIn('id', $channelIds)
            ->whereHas('members', fn ($query) => $query->whereKey($owner->id))
            ->pluck('id')
            ->all();

        if (count($allowed) !== count($channelIds)) {
            throw ValidationException::withMessages([
                'source_channel_ids' => 'Choose only conversations you are a member of in this workspace.',
            ]);
        }

        $agent->sources()->whereNotIn('channel_id', $allowed)->delete();

        foreach ($allowed as $channelId) {
            $agent->sources()->firstOrCreate(['channel_id' => $channelId]);
        }

        $agent->unsetRelation('sources');
    }
}
