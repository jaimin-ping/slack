<?php

namespace App\Http\Controllers\AI\Concerns;

use App\Models\AiAgent;
use App\Models\Workspace;
use Illuminate\Http\Request;

trait AuthorizesAgentOwner
{
    /** A wrong workspace or someone else's agent looks like a missing one, so ids reveal nothing. */
    private function authorizeOwner(Request $request, Workspace $workspace, AiAgent $agent): void
    {
        abort_unless($agent->workspace_id === $workspace->id && $agent->isOwnedBy($request->user()), 404);
        abort_unless($workspace->hasMember($request->user()), 403);
    }
}
