<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\AttachmentShare;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->message->channel->hasMember($request->user()), 403);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404, 'File not found');

        return Storage::disk('local')->response(
            $attachment->path,
            $attachment->name,
            ['Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff'],
            $attachment->isInlineImage() ? 'inline' : 'attachment',
        );
    }

    public function details(Request $request, Attachment $attachment): JsonResponse
    {
        abort_unless($attachment->message->channel->hasMember($request->user()), 403);

        return response()->json([
            'success' => true,
            'data' => $attachment->toDetailPayload($request->user()->id),
        ]);
    }

    public function update(Request $request, Attachment $attachment): JsonResponse
    {
        abort_unless($attachment->message->channel->hasMember($request->user()), 403);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
        ]);

        $attachment->update($request->only(['name', 'description']));

        return response()->json([
            'success' => true,
            'message' => 'Attachment updated successfully',
            'data' => $attachment->fresh()->toDetailPayload($request->user()->id),
        ]);
    }

    public function destroy(Request $request, Attachment $attachment): JsonResponse
    {
        $message = $attachment->message;
        $isOwner = $message->user_id === $request->user()->id;
        $isAdmin = $message->channel->workspace->members()
            ->where('user_id', $request->user()->id)
            ->where('role', 'admin')
            ->exists();

        if (!$isOwner && !$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => "You don't have permission to delete this file",
            ], 403);
        }

        // Delete file from storage
        if (Storage::disk('local')->exists($attachment->path)) {
            Storage::disk('local')->delete($attachment->path);
        }

        $attachment->delete();

        return response()->json([
            'success' => true,
            'message' => 'File deleted successfully',
        ]);
    }

    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->message->channel->hasMember($request->user()), 403);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404, 'File not found');

        $attachment->incrementDownloadCount();

        return Storage::disk('local')->download(
            $attachment->path,
            $attachment->name,
            ['Content-Type' => $attachment->mime],
        );
    }

    public function share(Request $request, Attachment $attachment): JsonResponse
    {
        abort_unless($attachment->message->channel->hasMember($request->user()), 403);

        $request->validate([
            'share_to' => 'required|array|min:1',
            'share_to.*.type' => 'required|in:channel,dm',
            'share_to.*.id' => 'required|integer',
            'message' => 'nullable|string',
        ]);

        $sharedTo = [];
        $user = $request->user();

        foreach ($request->share_to as $target) {
            $channel = Channel::find($target['id']);
            if (!$channel || !$channel->hasMember($user)) {
                continue;
            }

            // Create a message with the shared attachment reference
            $messageBody = $request->message ?? "Shared a file: {$attachment->name}";
            $newMessage = Message::create([
                'channel_id' => $channel->id,
                'user_id' => $user->id,
                'body' => $messageBody,
            ]);

            // Record the share
            $share = AttachmentShare::create([
                'attachment_id' => $attachment->id,
                'shared_by' => $user->id,
                'shared_to_type' => $target['type'],
                'shared_to_id' => $target['id'],
                'message_id' => $newMessage->id,
                'message' => $request->message,
            ]);

            $sharedTo[] = [
                'type' => $target['type'],
                'id' => $target['id'],
                'name' => $channel->name,
                'message_id' => $newMessage->id,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'File shared successfully',
            'data' => [
                'shared_to' => $sharedTo,
            ],
        ]);
    }

    public function shares(Request $request, Attachment $attachment): JsonResponse
    {
        abort_unless($attachment->message->channel->hasMember($request->user()), 403);

        $shares = $attachment->shares()
            ->with('sharedBy')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $shares->map(fn ($share) => $share->toPayload()),
        ]);
    }
}
