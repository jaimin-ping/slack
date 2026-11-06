<?php

namespace App\Http\Controllers;

use App\Events\MemberRemoved;
use App\Events\MemberUpdated;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/** Workspace membership: admins see and remove people, only the owner changes roles. */
class MemberController extends Controller
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless($workspace->isAdmin($request->user()), 403);

        return response()->json(
            $workspace->members()->orderBy('name')->get(['users.id', 'users.name', 'users.email'])
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->id === $workspace->owner_id ? 'owner' : $member->pivot->role,
                ]),
        );
    }

    public function update(Request $request, Workspace $workspace, User $user): Response
    {
        abort_unless($workspace->owner_id === $request->user()->id, 403);
        abort_unless($workspace->hasMember($user) && $user->id !== $workspace->owner_id, 422);

        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'member'])]]);

        $workspace->members()->updateExistingPivot($user->id, ['role' => $data['role']]);
        AuditLog::record($workspace, $request->user(), 'member.role_changed', "{$user->name} is now {$data['role']}");
        $this->announce(new MemberUpdated([$workspace->id]));

        return response()->noContent();
    }

    public function destroy(Request $request, Workspace $workspace, User $user): Response
    {
        $actor = $request->user();
        abort_unless($workspace->isAdmin($actor), 403);
        abort_unless($workspace->hasMember($user) && $user->id !== $workspace->owner_id && $user->id !== $actor->id, 422);

        // An admin can remove members, but only the owner can remove another admin.
        abort_if($workspace->isAdmin($user) && $workspace->owner_id !== $actor->id, 403);

        DB::transaction(function () use ($workspace, $user) {
            $user->channels()->detach($workspace->channels()->pluck('id'));
            $workspace->members()->detach($user->id);
            Activity::where(['user_id' => $user->id, 'workspace_id' => $workspace->id])->delete();
        });

        AuditLog::record($workspace, $actor, 'member.removed', $user->name);

        $this->announce(new MemberRemoved($workspace->id, $user->id));
        $this->announce(new MemberUpdated([$workspace->id]));

        return response()->noContent();
    }

    private function announce(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            report($e); // The change is saved; the live update is best effort.
        }
    }
}
