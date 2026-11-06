<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin views: usage numbers and the audit log. Neither shows message text. */
class InsightsController extends Controller
{
    private const TOP = 5;

    private const AUDIT_PAGE = 100;

    public function analytics(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless($workspace->isAdmin($request->user()), 403);

        $days = (int) ($request->validate(['days' => ['nullable', Rule::in([7, 30, 90])]])['days'] ?? 30);
        $since = now()->subDays($days - 1)->startOfDay();

        $channelIds = $workspace->channels()->pluck('id');
        $publicIds = $workspace->channels()->where('type', Channel::PUBLIC)->pluck('id');

        // Column names are qualified because the rankings join other tables onto this query.
        $messages = fn () => Message::whereIn('messages.channel_id', $channelIds)
            ->whereNull('messages.removed_at')
            ->where('messages.created_at', '>=', $since);

        $counts = $messages()
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupByRaw('date(created_at)')
            ->pluck('total', 'day');

        $daily = collect(range($days - 1, 0))->map(function (int $ago) use ($counts) {
            $day = now()->subDays($ago)->toDateString();

            return ['day' => $day, 'messages' => (int) ($counts[$day] ?? 0)];
        })->values();

        $files = Attachment::whereHas('message', fn ($query) => $query->whereIn('channel_id', $channelIds))
            ->where('created_at', '>=', $since);

        // Rankings use public channels only, so nobody's private or direct activity is singled out.
        $publicMessages = fn () => $messages()->whereIn('messages.channel_id', $publicIds);

        return response()->json([
            'days' => $days,
            'members' => $workspace->members()->count(),
            'active_members' => (int) $messages()->whereNotNull('user_id')->distinct()->count('user_id'),
            'messages' => $messages()->count(),
            'bot_messages' => $messages()->whereNull('user_id')->count(),
            'files' => (clone $files)->count(),
            'file_bytes' => (int) (clone $files)->sum('size'),
            'daily' => $daily,
            'top_channels' => $publicMessages()
                ->join('channels', 'channels.id', '=', 'messages.channel_id')
                ->selectRaw('channels.name as name, count(*) as total')
                ->groupBy('channels.name')
                ->orderByDesc('total')
                ->limit(self::TOP)
                ->get(['name', 'total']),
            'top_members' => $publicMessages()
                ->whereNotNull('messages.user_id')
                ->join('users', 'users.id', '=', 'messages.user_id')
                ->selectRaw('users.name as name, count(*) as total')
                ->groupBy('users.id', 'users.name')
                ->orderByDesc('total')
                ->limit(self::TOP)
                ->get(['name', 'total']),
        ]);
    }

    public function audit(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless($workspace->isAdmin($request->user()), 403);

        $rows = AuditLog::where('workspace_id', $workspace->id)
            ->when($request->integer('before'), fn ($query, $before) => $query->where('id', '<', $before))
            ->when($request->query('action'), fn ($query, $action) => $query->where('action', $action))
            ->orderByDesc('id')
            ->limit(self::AUDIT_PAGE + 1)
            ->get();

        return response()->json([
            'entries' => $rows->take(self::AUDIT_PAGE)->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'actor' => $log->actor_name,
                'action' => $log->action,
                'detail' => $log->detail,
                'ip' => $log->ip,
                'created_at' => $log->created_at->toIso8601String(),
            ])->values(),
            'hasMore' => $rows->count() > self::AUDIT_PAGE,
        ]);
    }
}
