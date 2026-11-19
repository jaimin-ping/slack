<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\AiAgentApproval;
use App\Models\Workspace;
use App\Services\AI\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The owner's queue of actions their agents want to take. Decisions are enforced in ApprovalService. */
class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $approvals) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless($workspace->hasMember($request->user()), 403);

        $data = $request->validate(['status' => ['sometimes', Rule::in(['pending', 'executed', 'rejected', 'failed', 'expired'])]]);

        return response()->json(
            AiAgentApproval::with('agent')
                ->where(['workspace_id' => $workspace->id, 'owner_id' => $request->user()->id, 'status' => $data['status'] ?? 'pending'])
                ->latest('id')->limit(100)->get()->map->toPayload(),
        );
    }

    public function approve(Request $request, Workspace $workspace, AiAgentApproval $approval): JsonResponse
    {
        $this->authorizeView($request, $workspace, $approval);

        return response()->json($this->approvals->approve($approval, $request->user())->fresh('agent')->toPayload());
    }

    public function reject(Request $request, Workspace $workspace, AiAgentApproval $approval): JsonResponse
    {
        $this->authorizeView($request, $workspace, $approval);

        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return response()->json($this->approvals->reject($approval, $request->user(), $data['reason'] ?? null)->fresh('agent')->toPayload());
    }

    private function authorizeView(Request $request, Workspace $workspace, AiAgentApproval $approval): void
    {
        abort_unless($approval->workspace_id === $workspace->id && $approval->owner_id === $request->user()->id, 404);
        abort_unless($workspace->hasMember($request->user()), 403);
    }
}
