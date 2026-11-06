<?php

namespace App\Http\Controllers;

use App\Events\MemberUpdated;
use App\Mail\WorkspaceInvite;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\CustomEmoji;
use App\Models\Draft;
use App\Models\Invitation;
use App\Models\Reminder;
use App\Models\ScheduledMessage;
use App\Models\SidebarSection;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Scheduled messages, groups, custom emoji, sidebar sections, email invites and workflows. */
class WorkspaceToolsController extends Controller
{
    /** Tells open pages that the workspace's groups or emoji changed. */
    private function announce(Workspace $workspace): void
    {
        try {
            event(new MemberUpdated([$workspace->id]));
        } catch (Throwable $e) {
            report($e); // The change is saved; it shows on the next page load.
        }
    }

    private function member(Request $request, Workspace $workspace): User
    {
        abort_unless($workspace->hasMember($request->user()), 403);

        return $request->user();
    }

    private function admin(Request $request, Workspace $workspace): User
    {
        abort_unless($workspace->isAdmin($request->user()), 403);

        return $request->user();
    }

    public function schedule(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user) && ! $channel->archived_at, 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'send_at' => ['required', 'date', 'after:now', 'before:+1 year'],
        ]);

        $item = ScheduledMessage::create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'body' => trim($data['body']),
            'send_at' => $data['send_at'],
        ]);

        // The text now lives in the schedule, so it must not come back as a draft.
        Draft::where(['user_id' => $user->id, 'channel_id' => $channel->id])->delete();

        return response()->json(['id' => $item->id, 'send_at' => $item->send_at->toIso8601String()], 201);
    }

    public function scheduled(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $this->member($request, $workspace);

        return response()->json(
            ScheduledMessage::with('channel')
                ->where('user_id', $user->id)
                ->whereHas('channel', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->where(fn ($query) => $query->whereNull('sent_at')->orWhere('failed', true))
                ->orderBy('send_at')
                ->get()
                ->map(fn (ScheduledMessage $item) => [
                    'id' => $item->id,
                    'channel_id' => $item->channel_id,
                    'channel_label' => $item->channel->type === Channel::DM ? 'Direct message' : '#'.$item->channel->name,
                    'body' => $item->body,
                    'send_at' => $item->send_at->toIso8601String(),
                    'failed' => $item->failed,
                ]),
        );
    }

    public function cancelScheduled(Request $request, Workspace $workspace, ScheduledMessage $item): Response
    {
        abort_unless($item->user_id === $request->user()->id, 403);

        $item->delete();

        return response()->noContent();
    }

    public function reminders(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $this->member($request, $workspace);

        return response()->json(
            Reminder::where(['user_id' => $user->id, 'workspace_id' => $workspace->id])
                ->whereNull('sent_at')
                ->orderBy('remind_at')
                ->get()
                ->map(fn (Reminder $reminder) => [
                    'id' => $reminder->id,
                    'text' => $reminder->text,
                    'remind_at' => $reminder->remind_at->toIso8601String(),
                ]),
        );
    }

    public function cancelReminder(Request $request, Workspace $workspace, Reminder $reminder): Response
    {
        abort_unless($reminder->user_id === $request->user()->id && $reminder->workspace_id === $workspace->id, 403);

        $reminder->delete();

        return response()->noContent();
    }

    public function storeGroup(Request $request, Workspace $workspace): JsonResponse
    {
        $this->admin($request, $workspace);

        $data = $request->validate([
            'handle' => [
                'required', 'regex:/^[a-z0-9-]{2,30}$/', Rule::notIn(['channel', 'here']),
                Rule::unique('user_groups')->where('workspace_id', $workspace->id),
            ],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer'],
        ]);

        $group = UserGroup::create(['workspace_id' => $workspace->id, 'handle' => $data['handle']]);
        $group->members()->sync($workspace->members()->whereIn('users.id', $data['member_ids'])->pluck('users.id'));
        $this->announce($workspace);

        return response()->json(['id' => $group->id], 201);
    }

    public function updateGroup(Request $request, Workspace $workspace, UserGroup $group): Response
    {
        $this->admin($request, $workspace);
        abort_unless($group->workspace_id === $workspace->id, 404);

        $data = $request->validate(['member_ids' => ['required', 'array', 'min:1'], 'member_ids.*' => ['integer']]);
        $group->members()->sync($workspace->members()->whereIn('users.id', $data['member_ids'])->pluck('users.id'));

        return response()->noContent();
    }

    public function destroyGroup(Request $request, Workspace $workspace, UserGroup $group): Response
    {
        $this->admin($request, $workspace);
        abort_unless($group->workspace_id === $workspace->id, 404);

        $group->delete();
        $this->announce($workspace);

        return response()->noContent();
    }

    public function storeEmoji(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $this->member($request, $workspace);

        $data = $request->validate([
            'name' => [
                'required', 'regex:/^[a-z0-9_-]{2,32}$/',
                Rule::unique('custom_emojis')->where('workspace_id', $workspace->id),
            ],
            'image' => ['required', 'file', 'mimes:png,gif,webp,jpg,jpeg', 'max:256'],
        ]);

        $emoji = CustomEmoji::create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => $data['name'],
            'path' => $request->file('image')->store('emoji/'.$workspace->id, 'local'),
        ]);

        $this->announce($workspace);

        return response()->json(['id' => $emoji->id], 201);
    }

    public function showEmoji(Request $request, CustomEmoji $emoji): StreamedResponse
    {
        abort_unless($request->user()->workspaces()->whereKey($emoji->workspace_id)->exists(), 404);

        return Storage::disk('local')->response($emoji->path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function destroyEmoji(Request $request, Workspace $workspace, CustomEmoji $emoji): Response
    {
        $user = $this->member($request, $workspace);
        abort_unless($emoji->workspace_id === $workspace->id, 404);
        abort_unless($emoji->created_by === $user->id || $workspace->isAdmin($user), 403);

        Storage::disk('local')->delete($emoji->path);
        $emoji->delete();
        $this->announce($workspace);

        return response()->noContent();
    }

    public function storeSection(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $this->member($request, $workspace);
        $data = $request->validate(['name' => ['required', 'string', 'max:40']]);

        $section = SidebarSection::create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'name' => trim($data['name'])]);

        return response()->json(['id' => $section->id, 'name' => $section->name], 201);
    }

    public function destroySection(Request $request, Workspace $workspace, SidebarSection $section): Response
    {
        abort_unless($section->user_id === $request->user()->id && $section->workspace_id === $workspace->id, 403);

        $section->delete();

        return response()->noContent();
    }

    public function moveToSection(Request $request, Workspace $workspace, Channel $channel): Response
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user), 403);

        $data = $request->validate(['section_id' => ['nullable', 'integer']]);
        $sectionId = $data['section_id'] ?? null;

        if ($sectionId) {
            abort_unless(SidebarSection::where(['id' => $sectionId, 'user_id' => $user->id, 'workspace_id' => $workspace->id])->exists(), 403);
        }

        $channel->members()->updateExistingPivot($user->id, ['section_id' => $sectionId]);

        return response()->noContent();
    }

    /** Invitations nobody has used yet. */
    public function invites(Request $request, Workspace $workspace): JsonResponse
    {
        $this->admin($request, $workspace);

        return response()->json(
            Invitation::pending()->where('workspace_id', $workspace->id)->latest('id')->get(['id', 'email']),
        );
    }

    public function invite(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $this->admin($request, $workspace);

        $data = $request->validate(['emails' => ['required', 'array', 'min:1', 'max:20'], 'emails.*' => ['required', 'email', 'max:255']]);

        $sent = 0;

        foreach (array_unique(array_map('strtolower', $data['emails'])) as $email) {
            if ($workspace->members()->whereRaw('lower(email) = ?', [$email])->exists()) {
                continue;
            }

            Invitation::updateOrCreate(
                ['workspace_id' => $workspace->id, 'email' => $email],
                ['invited_by' => $user->id, 'accepted_at' => null],
            );

            try {
                Mail::to($email)->send(new WorkspaceInvite($workspace, $user));
                $sent++;
            } catch (Throwable $e) {
                report($e); // One bad address must not stop the others.
            }
        }

        AuditLog::record($workspace, $user, 'invite.sent', "{$sent} email invites");

        return response()->json(['sent' => $sent]);
    }

    public function cancelInvite(Request $request, Workspace $workspace, Invitation $invitation): Response
    {
        $user = $this->admin($request, $workspace);

        abort_unless($invitation->workspace_id === $workspace->id && ! $invitation->accepted_at, 404);

        $invitation->delete();
        AuditLog::record($workspace, $user, 'invite.cancelled', $invitation->email);

        return response()->noContent();
    }

    public function workflows(Request $request, Workspace $workspace): JsonResponse
    {
        $this->admin($request, $workspace);

        return response()->json(
            Workflow::where('workspace_id', $workspace->id)->latest('id')->get()
                ->map(fn (Workflow $workflow) => $workflow->toPayload()),
        );
    }

    public function storeWorkflow(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $this->admin($request, $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'trigger' => ['required', Rule::in(['daily', 'member_joined'])],
            'time' => ['required_if:trigger,daily', 'nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'steps' => ['required', 'array', 'min:1', 'max:'.Workflow::MAX_STEPS],
            'steps.*.type' => ['required', Rule::in(['message', 'dm', 'wait'])],
            'steps.*.channel_id' => ['required_if:steps.*.type,message', 'nullable', 'integer'],
            'steps.*.text' => ['required_unless:steps.*.type,wait', 'nullable', 'string', 'max:2000'],
            'steps.*.minutes' => ['required_if:steps.*.type,wait', 'nullable', 'integer', 'min:1', 'max:'.Workflow::MAX_WAIT_MINUTES],
        ]);

        $data['steps'] = $this->cleanSteps($data['steps'], $data['trigger'], $workspace, $user);

        $workflow = Workflow::create([...$data, 'workspace_id' => $workspace->id, 'created_by' => $user->id]);
        AuditLog::record($workspace, $user, 'workflow.created', $workflow->name);

        return response()->json($workflow->toPayload(), 201);
    }

    /** Keeps only the fields each step type uses, and checks the order makes sense. */
    private function cleanSteps(array $steps, string $trigger, Workspace $workspace, User $user): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['steps' => $message]);

        if (end($steps)['type'] === 'wait') {
            $fail('A workflow cannot end with a wait.');
        }

        return array_map(function (array $step) use ($trigger, $workspace, $user, $fail) {
            if ($step['type'] === 'wait') {
                return ['type' => 'wait', 'minutes' => (int) $step['minutes']];
            }

            if ($step['type'] === 'dm') {
                if ($trigger !== 'member_joined') {
                    $fail('Only a workflow for new members can message the new member.');
                }

                return ['type' => 'dm', 'text' => $step['text']];
            }

            $channel = $workspace->channels()->whereKey($step['channel_id'])->where('type', '!=', Channel::DM)->first();

            if (! $channel || ! $channel->hasMember($user)) {
                $fail('Choose a channel you are a member of.');
            }

            return ['type' => 'message', 'channel_id' => $channel->id, 'text' => $step['text']];
        }, array_values($steps));
    }

    public function toggleWorkflow(Request $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->admin($request, $workspace);
        abort_unless($workflow->workspace_id === $workspace->id, 404);

        $workflow->update(['enabled' => ! $workflow->enabled]);

        return response()->json($workflow->toPayload());
    }

    public function destroyWorkflow(Request $request, Workspace $workspace, Workflow $workflow): Response
    {
        $user = $this->admin($request, $workspace);
        abort_unless($workflow->workspace_id === $workspace->id, 404);

        AuditLog::record($workspace, $user, 'workflow.deleted', $workflow->name);
        $workflow->delete();

        return response()->noContent();
    }
}
