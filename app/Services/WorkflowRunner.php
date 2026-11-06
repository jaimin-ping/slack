<?php

namespace App\Services;

use App\Events\SidebarChanged;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Throwable;

/** Walks a workflow's steps in order, pausing the run at each wait. */
class WorkflowRunner
{
    public function __construct(private MessagePoster $poster) {}

    public function start(Workflow $workflow, ?User $member = null): void
    {
        $this->advance(WorkflowRun::create(['workflow_id' => $workflow->id, 'member_id' => $member?->id, 'step' => 0]));
    }

    public function resumeDue(): void
    {
        WorkflowRun::whereNotNull('resume_at')->where('resume_at', '<=', now())->get()
            ->each(function (WorkflowRun $run) {
                // Claiming the row first means two overlapping runs cannot both continue it.
                if (WorkflowRun::whereKey($run->id)->where('resume_at', $run->resume_at)->update(['resume_at' => null])) {
                    $this->advance($run);
                }
            });
    }

    private function advance(WorkflowRun $run): void
    {
        try {
            $workflow = $run->workflow;

            // Pausing a workflow also stops the runs that were waiting.
            $steps = $workflow->enabled ? $workflow->steps : [];

            for ($index = $run->step; $index < count($steps); $index++) {
                $step = $steps[$index];

                if ($step['type'] === 'wait') {
                    $run->update(['step' => $index + 1, 'resume_at' => now()->addMinutes($step['minutes'])]);

                    return;
                }

                $this->perform($workflow, $step, $run->member);
            }
        } catch (Throwable $e) {
            report($e); // One bad step must not stop the other workflows.
        }

        $run->delete();
    }

    /** A step whose channel or person has gone is skipped, and the rest still run. */
    private function perform(Workflow $workflow, array $step, ?User $member): void
    {
        $text = $member ? str_replace('{name}', $member->name, $step['text']) : $step['text'];

        if ($step['type'] === 'message') {
            $channel = Channel::where('workspace_id', $workflow->workspace_id)->find($step['channel_id']);

            if ($channel && ! $channel->archived_at) {
                $this->poster->asBot($channel, $workflow->name, $text);
            }

            return;
        }

        $workspace = $workflow->workspace;

        if ($member && $workspace->hasMember($member)) {
            $this->poster->asBot(Channel::selfDm($workspace, $member), $workflow->name, $text);

            // The first message creates the conversation, so the sidebar has to reload.
            try {
                event(new SidebarChanged($workspace->id, [$member->id]));
            } catch (Throwable $e) {
                report($e); // The message is saved; it shows on the next page load.
            }
        }
    }
}
