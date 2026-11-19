<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\AI\Concerns\AuthorizesAgentOwner;
use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\AiAgentRun;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Execution history and AI cost, per agent, per member and (for admins) per workspace. */
class AgentActivityController extends Controller
{
    use AuthorizesAgentOwner;

    public function runs(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in([AiAgentRun::RUNNING, AiAgentRun::SUCCEEDED, AiAgentRun::FAILED, AiAgentRun::SKIPPED, AiAgentRun::CANCELLED])],
            'trigger' => ['sometimes', Rule::in(['message', 'history', 'chat', 'schedule'])],
        ]);

        $page = $agent->runs()->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['trigger'] ?? null, fn ($q, $trigger) => $q->where('trigger', $trigger))
            ->latest('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (AiAgentRun $run) => $run->toPayload())->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function run(Request $request, Workspace $workspace, AiAgent $agent, AiAgentRun $run): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);
        abort_unless($run->ai_agent_id === $agent->id, 404);

        return response()->json($run->load('stepRecords')->toPayload(withSteps: true));
    }

    /** Your own agents' usage; workspace admins can also ask for the whole workspace, broken down by member. */
    public function usage(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $data = $request->validate(['scope' => ['sometimes', Rule::in(['mine', 'workspace'])], 'days' => ['sometimes', 'integer', 'between:1,90']]);
        $scope = $data['scope'] ?? 'mine';
        abort_if($scope === 'workspace' && ! $workspace->isAdmin($user), 403);

        $since = now()->subDays(($data['days'] ?? 30) - 1)->startOfDay();
        $group = $scope === 'workspace' ? 'owner_id' : 'ai_agent_id';

        $rows = AiAgentRun::where('workspace_id', $workspace->id)
            ->when($scope === 'mine', fn ($q) => $q->where('owner_id', $user->id))
            ->where('created_at', '>=', $since)
            ->groupBy($group)
            ->selectRaw("{$group} as id, COUNT(*) as runs, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, "
                .'SUM(cache_read_tokens) as cache_read_tokens, SUM(cache_write_tokens) as cache_write_tokens, SUM(cost_micros) as cost_micros')
            ->get()
            ->map(fn ($row) => [
                $scope === 'workspace' ? 'owner_id' : 'agent_id' => (int) $row->id,
                'runs' => (int) $row->runs,
                'tokens' => (int) $row->input_tokens + (int) $row->output_tokens + (int) $row->cache_read_tokens + (int) $row->cache_write_tokens,
                'cost_usd' => round(((int) $row->cost_micros) / 1_000_000, 4),
            ]);

        return response()->json([
            'since' => $since->toIso8601String(),
            'scope' => $scope,
            'rows' => $rows->values(),
            'total_cost_usd' => round($rows->sum('cost_usd'), 4),
        ]);
    }
}
