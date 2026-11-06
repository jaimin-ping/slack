<?php

namespace App\Mail;

use App\Services\LoginCodes;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LoginCode extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} is your Ping Chat code");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Your Ping Chat sign in code is:</p>'
            ."<p style=\"font-size:24px;font-weight:bold;letter-spacing:4px\">{$this->code}</p>"
            .'<p>It works for '.LoginCodes::MINUTES.' minutes. If you did not ask for it, you can ignore this email.</p>');
    }
}
