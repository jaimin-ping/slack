<?php

namespace App\Http\Controllers;

use App\Events\MemberUpdated;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Throwable;

class StatusController extends Controller
{
    public function update(Request $request): Response
    {
        $data = $request->validate([
            'status_emoji' => ['nullable', 'string', 'max:16'],
            'status_text' => ['nullable', 'string', 'max:100'],
            'expires_in' => ['nullable', 'integer', Rule::in([30, 60, 240, 1440])],
        ]);

        $request->user()->update([
            'status_emoji' => $data['status_emoji'] ?? null,
            'status_text' => $data['status_text'] ?? null,
            'status_expires_at' => isset($data['expires_in']) ? now()->addMinutes($data['expires_in']) : null,
        ]);
        $this->announce($request->user());

        return response()->noContent();
    }

    public function away(Request $request): Response
    {
        $user = $request->user();
        $user->update(['is_away' => ! $user->is_away]);
        $this->announce($user);

        return response()->noContent();
    }

    private function announce(User $user): void
    {
        $workspaceIds = $user->workspaces()->pluck('workspaces.id')->all();

        if ($workspaceIds === []) {
            return;
        }

        try {
            event(new MemberUpdated($workspaceIds));
        } catch (Throwable $e) {
            report($e); // The change is saved; the live update is best effort.
        }
    }
}
