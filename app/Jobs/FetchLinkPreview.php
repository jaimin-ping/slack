<?php

namespace App\Jobs;

use App\Events\MessageUpdated;
use App\Models\Message;
use App\Services\LinkPreviewer;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/** Runs after the response is sent, so a slow website never delays a message. */
class FetchLinkPreview
{
    use Dispatchable;

    public function __construct(public int $messageId) {}

    public function handle(LinkPreviewer $previewer): void
    {
        $message = Message::find($this->messageId);
        $url = $message ? $previewer->firstUrl($message->body) : null;
        $preview = $url ? $previewer->fetch($url) : null;

        if (! $preview) {
            return;
        }

        $message->update(['link_preview' => $preview]);
        $message->load(['user', 'attachments', 'reactions'])->loadCount('replies')->loadMax('replies', 'created_at');

        try {
            event(new MessageUpdated($message));
        } catch (Throwable $e) {
            report($e); // The preview is saved; it shows on the next page load.
        }
    }
}
