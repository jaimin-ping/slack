<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WorkspaceInvite extends Mailable
{
    public function __construct(public Workspace $workspace, public User $inviter) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviter->name} invited you to {$this->workspace->name}");
    }

    public function content(): Content
    {
        $link = route('workspaces.invite', $this->workspace->invite_code);
        $inviter = e($this->inviter->name);
        $workspace = e($this->workspace->name);

        return new Content(htmlString: "<p>{$inviter} invited you to join <strong>{$workspace}</strong> on Ping Chat.</p>"
            .'<p><a href="'.e($link).'">Join the workspace</a></p>');
    }
}
