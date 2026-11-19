<?php

namespace Tests\Feature\AI\Concerns;

use App\Ai\Providers\FakeLanguageModel;
use App\Models\AiAgent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AgentManager;
use App\Services\Invitations;

/** A workspace with Kirit (the agent owner), Jaimin and the workspace owner, all in #general. */
trait BuildsAgents
{
    protected Workspace $workspace;

    protected User $boss;

    protected User $kirit;

    protected User $jaimin;

    protected Channel $general;

    protected FakeLanguageModel $ai;

    protected function setUpWorkspace(): void
    {
        $this->boss = User::factory()->create(['name' => 'Boss']);
        $this->kirit = User::factory()->create(['name' => 'Kirit', 'email' => 'kirit@example.test', 'timezone' => 'UTC']);
        $this->jaimin = User::factory()->create(['name' => 'Jaimin']);

        $this->actingAs($this->boss)->post('/workspaces', ['name' => 'Acme']);
        $this->workspace = Workspace::first();
        app(Invitations::class)->admit($this->workspace, $this->kirit);
        app(Invitations::class)->admit($this->workspace, $this->jaimin);
        $this->general = $this->workspace->channels()->where('name', 'general')->first();

        $this->ai = FakeLanguageModel::fake();
    }

    protected function makeAgent(User $owner, array $data = [], ?array $sources = null): AiAgent
    {
        return app(AgentManager::class)->create($this->workspace, $owner, [
            'name' => 'Alex',
            'source_channel_ids' => $sources ?? [$this->general->id],
            ...$data,
        ]);
    }

    /** Posts like a person would, through the real endpoint, so observers and jobs run as in production. */
    protected function say(User $author, string $body, ?Channel $channel = null): Message
    {
        $channel ??= $this->general;
        $id = $this->actingAs($author)
            ->postJson("/w/{$this->workspace->slug}/c/{$channel->id}/messages", ['body' => $body])
            ->assertCreated()
            ->json('id');

        return Message::findOrFail($id);
    }

    /** One extracted task in the shape the model must return. */
    protected function extracted(array $task): array
    {
        return [
            'message_id' => 0,
            'title' => 'Task',
            'description' => null,
            'assignee_ids' => [],
            'unresolved_assignees' => [],
            'due_date' => null,
            'due_time' => null,
            'due_phrase' => null,
            'priority' => 'normal',
            'confidence' => 0.9,
            'evidence' => '',
            'needs_clarification' => false,
            'clarification_question' => null,
            ...$task,
        ];
    }

    /** A scripted extraction answer that points at the newest message. */
    protected function extractionFor(array $tasks, array $updates = []): \Closure
    {
        return function () use ($tasks, $updates) {
            $latest = Message::max('id');

            return FakeLanguageModel::json([
                'tasks' => array_map(fn ($task) => $this->extracted(['message_id' => $latest, ...$task]), $tasks),
                'updates' => array_map(fn ($update) => ['message_id' => $latest, 'confidence' => 0.9, ...$update], $updates),
            ]);
        };
    }
}
