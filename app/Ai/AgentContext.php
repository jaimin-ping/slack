<?php

namespace App\Ai;

use App\Ai\Exceptions\AgentHalted;
use App\Models\AiAgent;
use App\Models\AiAgentRun;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AgentCapabilities;
use Illuminate\Support\Carbon;

/**
 * Everything one run is allowed to do, checked again before every step.
 * An agent can never reach further than its owner: its access is the owner's access, narrowed to the
 * conversations granted to it.
 */
final class AgentContext
{
    /**
     * @param  bool  $readOnly  the owner is chatting with a paused agent: it may answer, but take no action
     */
    public function __construct(
        public AiAgent $agent,
        public AiAgentRun $run,
        public bool $readOnly = false,
    ) {}

    /**
     * Set once this run has read anything written by other people (messages, tasks, channel topics).
     * From then on, messages to other people need the owner's approval even where they are allowed,
     * so text planted in a workspace message can never make the agent message anyone on its own.
     */
    public bool $readWorkspaceContent = false;

    public function owner(): User
    {
        return $this->agent->owner;
    }

    public function workspace(): Workspace
    {
        return $this->agent->workspace;
    }

    /**
     * Reads the agent's status from the database, so a pause made while this run is in flight
     * stops it before its next action.
     */
    public function ensureCanAct(): void
    {
        $status = AiAgent::whereKey($this->agent->id)->value('status');

        if ($status === null) {
            throw new AgentHalted('The agent was deleted.');
        }

        $this->agent->status = $status;
        $allowed = $this->readOnly
            ? $status !== AgentCapabilities::STATUS_INACTIVE
            : $status === AgentCapabilities::STATUS_ACTIVE;

        if (! $allowed) {
            throw new AgentHalted("The agent is {$status}.");
        }

        if (! $this->workspace()->hasMember($this->owner())) {
            throw new AgentHalted('The agent\'s owner is no longer in this workspace.');
        }
    }

    /** The current policy for an action, read fresh so a change takes effect mid-run. */
    public function policy(string $action): string
    {
        return $this->agent->permissions()->where('action', $action)->value('policy') ?? AgentCapabilities::DENY;
    }

    /**
     * Conversations this agent may read right now: granted to it, in its workspace, not archived,
     * and still including its owner.
     *
     * @return array<int, int>
     */
    public function readableChannelIds(): array
    {
        return $this->agent->readableChannelIds();
    }

    /**
     * A date-time from the model, as UTC. One written without a time zone ("2026-11-20 18:00") means the
     * owner's local time, never the server's.
     */
    public function toUtc(string $value): string
    {
        $value = trim($value);
        $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value);
        $zone = $this->owner()->timezone ?: config('app.timezone');

        return ($hasZone ? Carbon::parse($value) : Carbon::parse($value, $zone))->utc()->toIso8601String();
    }

    public function canRead(Channel|int $channel): bool
    {
        $id = $channel instanceof Channel ? $channel->id : $channel;

        return in_array($id, $this->readableChannelIds(), true);
    }
}
