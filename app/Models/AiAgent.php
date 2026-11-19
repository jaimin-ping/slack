<?php

namespace App\Models;

use App\Services\AI\AgentCapabilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A member's personal AI assistant. It acts only for its owner, inside one workspace. */
class AiAgent extends Model
{
    protected $fillable = [
        'workspace_id', 'owner_id', 'name', 'description', 'personality', 'instructions', 'responsibilities',
        'avatar_path', 'avatar_preset', 'status', 'mode', 'settings', 'paused_at', 'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'paused_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(AiAgentPermission::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AiAgentSource::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(AiAgentMemory::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiAgentRun::class);
    }

    public function isActive(): bool
    {
        return $this->status === AgentCapabilities::STATUS_ACTIVE;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    /** Fails closed: an action with no stored policy is denied. */
    public function policyFor(string $action): string
    {
        $permissions = $this->relationLoaded('permissions') ? $this->permissions : $this->permissions()->get();

        return $permissions->firstWhere('action', $action)?->policy ?? AgentCapabilities::DENY;
    }

    /** @return array<string, mixed> */
    public function resolvedSettings(): array
    {
        return AgentCapabilities::mergeSettings($this->settings ?? [], []);
    }

    /** How the agent is named wherever other people see it, so it is never mistaken for a person. */
    /**
     * Conversations this agent may read or post in right now: granted to it, in its workspace, not archived,
     * and still including its owner.
     *
     * @return array<int, int>
     */
    public function readableChannelIds(): array
    {
        return Channel::query()
            ->where('workspace_id', $this->workspace_id)
            ->whereNull('archived_at')
            ->whereIn('id', $this->sources()->select('channel_id'))
            ->whereHas('members', fn ($query) => $query->whereKey($this->owner_id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function displayName(): string
    {
        return "{$this->name} (AI Assistant)";
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? route('agents.avatar', ['agent' => $this->id, 'v' => substr(md5($this->avatar_path), 0, 8)], false)
            : null;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $this->loadMissing(['permissions', 'sources.channel']);

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'owner_id' => $this->owner_id,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'description' => $this->description,
            'personality' => $this->personality,
            'instructions' => $this->instructions,
            'responsibilities' => $this->responsibilities,
            'avatar_url' => $this->avatarUrl(),
            'avatar_preset' => $this->avatar_preset,
            'status' => $this->status,
            'mode' => $this->mode,
            'settings' => $this->resolvedSettings(),
            'permissions' => collect(AgentCapabilities::ACTIONS)
                ->map(fn ($label, $action) => $this->policyFor($action))
                ->all(),
            'sources' => $this->sources
                ->filter(fn (AiAgentSource $source) => $source->channel !== null)
                ->map(fn (AiAgentSource $source) => $source->toPayload($this->owner_id))
                ->values()
                ->all(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
