<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\WorkflowRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'workspaces' => $request->user()->workspaces()->orderBy('name')->get(['workspaces.id', 'name', 'slug']),
            'code' => $request->query('code'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $user = $request->user();

        $workspace = DB::transaction(function () use ($data, $user) {
            $workspace = Workspace::create([
                'name' => $data['name'],
                'slug' => (Str::slug($data['name']) ?: 'workspace').'-'.Str::lower(Str::random(6)),
                'invite_code' => Str::random(16),
                'owner_id' => $user->id,
            ]);
            $workspace->members()->attach($user->id, ['role' => 'owner']);

            $general = $workspace->channels()->create([
                'name' => 'general',
                'type' => Channel::PUBLIC,
                'created_by' => $user->id,
            ]);
            $general->members()->attach($user->id);
            AuditLog::record($workspace, $user, 'workspace.created', $workspace->name);

            return $workspace;
        });

        return redirect()->route('workspaces.show', $workspace->slug);
    }

    public function join(Request $request, WorkflowRunner $workflows): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $workspace = Workspace::where('invite_code', trim($data['code']))->first();

        if (! $workspace) {
            throw ValidationException::withMessages(['code' => 'This invite code is not valid.']);
        }

        $user = $request->user();
        $isNew = ! $workspace->hasMember($user);

        DB::transaction(function () use ($workspace, $user) {
            $workspace->members()->syncWithoutDetaching([$user->id]);
            $workspace->channels()->where('type', Channel::PUBLIC)->get()
                ->each(fn (Channel $channel) => $channel->members()->syncWithoutDetaching([$user->id]));
        });

        if ($isNew) {
            AuditLog::record($workspace, $user, 'member.joined', $user->name);

            Workflow::where(['workspace_id' => $workspace->id, 'trigger' => 'member_joined', 'enabled' => true])->get()
                ->each(fn (Workflow $workflow) => $workflows->start($workflow, $user));
        }

        return redirect()->route('workspaces.show', $workspace->slug);
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($workspace->isAdmin($request->user()), 403);

        $previous = $workspace->name;
        $workspace->update($request->validate(['name' => ['required', 'string', 'max:80']]));
        AuditLog::record($workspace, $request->user(), 'workspace.renamed', "{$previous} to {$workspace->name}");

        return back();
    }

    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($workspace->owner_id === $request->user()->id, 403);

        $data = $request->validate(['confirm_name' => ['required', 'string']]);

        if ($data['confirm_name'] !== $workspace->name) {
            throw ValidationException::withMessages(['confirm_name' => 'Type the workspace name exactly to delete it.']);
        }

        $workspace->channels->each(fn (Channel $channel) => $channel->deleteFiles());
        $workspace->delete();

        return redirect()->route('dashboard');
    }

    public function show(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($workspace->hasMember($request->user()), 403);

        $channel = $workspace->channels()->where('type', Channel::PUBLIC)->oldest('id')->firstOrFail();

        return redirect()->route('channels.show', [$workspace->slug, $channel->id]);
    }
}
