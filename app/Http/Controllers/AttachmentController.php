<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->message->channel->hasMember($request->user()), 403);

        return Storage::disk('local')->response(
            $attachment->path,
            $attachment->name,
            ['Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff'],
            $attachment->isInlineImage() ? 'inline' : 'attachment',
        );
    }
}
