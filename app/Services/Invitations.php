<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** Who may join a workspace. People only ever get in through an invitation to their email address. */
class Invitations
{
    public function __construct(private WorkflowRunner $workflows) {}

    public function isInvited(string $email): bool
    {
        return Invitation::pending()->where('email', strtolower($email))->exists();
    }

    /** Only a confirmed address counts, because anyone can type an address into their profile. */
    public function acceptFor(User $user): void
    {
        if (! $user->email_verified_at) {
            return;
        }

        Invitation::pending()->where('email', strtolower($user->email))->with('workspace')->get()
            ->each(function (Invitation $invitation) use ($user) {
                // Two open tabs can arrive together, and only one of them may do the joining.
                $claimed = Invitation::pending()->whereKey($invitation->id)->update(['accepted_at' => now()]);

                if ($claimed) {
                    $this->admit($invitation->workspace, $user);
                }
            });
    }

    public function admit(Workspace $workspace, User $user): void
    {
        $isNew = ! $workspace->hasMember($user);

        DB::transaction(function () use ($workspace, $user) {
            $workspace->members()->syncWithoutDetaching([$user->id]);
            $workspace->channels()->where('type', Channel::PUBLIC)->get()
                ->each(fn (Channel $channel) => $channel->members()->syncWithoutDetaching([$user->id]));
        });

        if ($isNew) {
            AuditLog::record($workspace, $user, 'member.joined', $user->name);

            Workflow::where(['workspace_id' => $workspace->id, 'trigger' => 'member_joined', 'enabled' => true])->get()
                ->each(fn (Workflow $workflow) => $this->workflows->start($workflow, $user));
        }
    }
}
