<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\AI\Concerns\AuthorizesAgentOwner;
use App\Http\Controllers\Controller;
use App\Jobs\AI\AnalyzeHistory;
use App\Models\AiAgent;
use App\Models\AiAgentChatMessage;
use App\Models\Workspace;
use App\Services\AI\AgentCapabilities;
use App\Services\AI\AgentChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** The owner chats with their agent, or asks it to look back over past conversations. */
class AgentChatController extends Controller
{
    use AuthorizesAgentOwner;

    public function __construct(private AgentChat $chat) {}

    public function index(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);

        $messages = AiAgentChatMessage::where('ai_agent_id', $agent->id)
            ->when($request->integer('before'), fn ($q, $before) => $q->where('id', '<', $before))
            ->latest('id')->limit(50)->get()->reverse()->values();

        return response()->json($messages->map->toPayload());
    }

    public function store(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);
        abort_if($agent->status === AgentCapabilities::STATUS_INACTIVE, 422, 'Reactivate this agent to chat with it.');

        $data = $request->validate(['message' => ['required', 'string', 'max:4000']]);

        $key = 'agent-chat:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('ai.limits.chat_messages_per_minute'))) {
            throw ValidationException::withMessages(['message' => 'You are sending messages too quickly. Wait a moment.']);
        }

        RateLimiter::hit($key, 60);

        return response()->json($this->chat->send($agent, $request->user(), $data['message'])->toPayload(), 202);
    }

    /** Historical analysis covers only conversations the agent was given, and a range the owner chose. */
    public function analyze(Request $request, Workspace $workspace, AiAgent $agent): JsonResponse
    {
        $this->authorizeOwner($request, $workspace, $agent);
        abort_unless($agent->isActive(), 422, 'Resume this agent before asking it to analyse history.');

        $days = (int) config('ai.limits.history_days');
        $data = $request->validate([
            'channel_ids' => ['required', 'array', 'min:1', 'max:20'],
            'channel_ids.*' => ['integer'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);

        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to'])->min(now());

        if ($from->diffInDays($to) > $days) {
            throw ValidationException::withMessages(['from' => "Choose a range of at most {$days} days."]);
        }

        $granted = $agent->sources()->pluck('channel_id')->map(fn ($id) => (int) $id)->all();
        $channelIds = array_values(array_unique(array_map('intval', $data['channel_ids'])));

        if (array_diff($channelIds, $granted) !== []) {
            throw ValidationException::withMessages(['channel_ids' => 'Choose only conversations this agent is allowed to read.']);
        }

        AnalyzeHistory::dispatch($agent->id, $channelIds, $from->toIso8601String(), $to->toIso8601String());

        return response()->json(['status' => 'queued', 'channel_ids' => $channelIds, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()], 202);
    }
}
