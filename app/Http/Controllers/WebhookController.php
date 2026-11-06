<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Workspace admins manage incoming webhooks here. */
class WebhookController extends Controller
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeOwner($request, $workspace);

        return response()->json(
            Webhook::with('channel')->where('workspace_id', $workspace->id)->latest('id')->get()
                ->map(fn (Webhook $webhook) => $webhook->toPayload()),
        );
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeOwner($request, $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'channel_id' => ['required', 'integer'],
        ]);

        $channel = $workspace->channels()->whereKey($data['channel_id'])->where('type', '!=', Channel::DM)->first();

        // The owner can only connect channels they can read themselves.
        abort_unless($channel && $channel->hasMember($request->user()), 422, 'Choose a channel you are a member of.');

        $token = Str::random(48);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'channel_id' => $channel->id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'token_hash' => Webhook::hashToken($token),
        ]);

        AuditLog::record($workspace, $request->user(), 'webhook.created', "{$webhook->name} to #{$channel->name}");

        return response()->json([
            'webhook' => $webhook->load('channel')->toPayload(),
            'url' => url('/hooks/'.$token), // Shown once; only the hash is stored.
        ], 201);
    }

    public function destroy(Request $request, Workspace $workspace, Webhook $webhook): Response
    {
        $this->authorizeOwner($request, $workspace);
        abort_unless($webhook->workspace_id === $workspace->id, 404);

        AuditLog::record($workspace, $request->user(), 'webhook.deleted', $webhook->name);
        $webhook->delete();

        return response()->noContent();
    }

    private function authorizeOwner(Request $request, Workspace $workspace): void
    {
        abort_unless($workspace->isAdmin($request->user()), 403);
    }
}
