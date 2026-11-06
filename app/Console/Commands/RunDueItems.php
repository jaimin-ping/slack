<?php

namespace App\Console\Commands;

use App\Events\SidebarChanged;
use App\Models\Channel;
use App\Models\Reminder;
use App\Models\ScheduledMessage;
use App\Models\Workflow;
use App\Services\MessagePoster;
use App\Services\WorkflowRunner;
use Illuminate\Console\Command;
use Throwable;

/** Sends everything whose time has come. The scheduler runs this every minute. */
class RunDueItems extends Command
{
    protected $signature = 'chat:run-due';

    protected $description = 'Send scheduled messages, reminders and daily workflows that are due';

    public function handle(MessagePoster $poster, WorkflowRunner $workflows): int
    {
        $this->scheduledMessages($poster);
        $this->reminders($poster);
        $this->workflows($workflows);
        $workflows->resumeDue();

        return self::SUCCESS;
    }

    private function scheduledMessages(MessagePoster $poster): void
    {
        ScheduledMessage::with(['channel', 'user'])->whereNull('sent_at')->where('send_at', '<=', now())->get()
            ->each(function (ScheduledMessage $item) use ($poster) {
                // Claiming the row first means two overlapping runs cannot both send it.
                if (! ScheduledMessage::whereKey($item->id)->whereNull('sent_at')->update(['sent_at' => now()])) {
                    return;
                }

                if ($item->channel->archived_at || ! $item->channel->hasMember($item->user)) {
                    $item->update(['failed' => true]);

                    return;
                }

                $poster->asUser($item->channel, $item->user, $item->body);
            });
    }

    private function reminders(MessagePoster $poster): void
    {
        Reminder::with(['user', 'workspace'])->whereNull('sent_at')->where('remind_at', '<=', now())->get()
            ->each(function (Reminder $reminder) use ($poster) {
                if (! Reminder::whereKey($reminder->id)->whereNull('sent_at')->update(['sent_at' => now()])) {
                    return;
                }

                // A removed person keeps no access, so their reminder is dropped.
                if ($reminder->workspace->hasMember($reminder->user)) {
                    $poster->asBot(Channel::selfDm($reminder->workspace, $reminder->user), 'Reminder', "⏰ {$reminder->text}");

                    // The first reminder creates the conversation, so the sidebar has to reload.
                    try {
                        event(new SidebarChanged($reminder->workspace_id, [$reminder->user_id]));
                    } catch (Throwable $e) {
                        report($e); // The reminder is saved; it shows on the next page load.
                    }
                }
            });
    }

    private function workflows(WorkflowRunner $runner): void
    {
        Workflow::where('enabled', true)->where('trigger', 'daily')->get()
            ->each(function (Workflow $workflow) use ($runner) {
                $now = now($workflow->timezone ?: config('app.timezone'));
                $today = $now->toDateString();

                if ($now->format('H:i') < $workflow->time || $workflow->last_run_on === $today) {
                    return;
                }

                $claimed = Workflow::whereKey($workflow->id)
                    ->where(fn ($query) => $query->whereNull('last_run_on')->orWhere('last_run_on', '!=', $today))
                    ->update(['last_run_on' => $today]);

                if ($claimed) {
                    $runner->start($workflow);
                }
            });
    }
}
