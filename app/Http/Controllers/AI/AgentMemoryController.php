<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\AI\Concerns\AuthorizesAgentOwner;
use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\AiAgentMemory;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The owner reviews, adds, edits and deletes what their agent remembers, including notes the agent saved itself. */
class AgentMemoryController extends Controller
{
    use AuthorizesAgentOwner;

    public function index(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);

        return response()->json($agent->memories()->orderByDesc('pinned')->latest('id')->get()->map->toPayload());
    }

    public function store(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);

        $data = $request->validate($this->rules(true));
        $limit = (int) config('ai.limits.memories_per_agent');

        if ($agent->memories()->count() >= $limit) {
            throw ValidationException::withMessages(['content' => "An agent can keep up to {$limit} memories. Delete some first."]);
        }

        $memory = $agent->memories()->create($data + ['origin' => 'owner', 'pinned' => $data['pinned'] ?? false]);

        return response()->json($memory->toPayload(), 201);
    }

    public function update(Request $request, Workspace $workspace, AiAgent $agent, AiAgentMemory $memory): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);
        abort_unless($memory->ai_agent_id === $agent->id, 404);

        // Editing an agent's note makes it the owner's: from then on it is treated as a preference.
        $memory->update($request->validate($this->rules(false)) + ['origin' => 'owner']);

        return response()->json($memory->toPayload());
    }

    public function destroy(Request $request, Workspace $workspace, AiAgent $agent, AiAgentMemory $memory): Response
    {
        $this->authorizeOwner($request, $workspace, $agent);
        abort_unless($memory->ai_agent_id === $agent->id, 404);

        $memory->delete();

        return response()->noContent();
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'kind' => [$required, Rule::in(AiAgentMemory::KINDS)],
            'content' => [$required, 'string', 'min:2', 'max:2000'],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }
}
