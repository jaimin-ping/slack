<?php

namespace App\Http\Controllers;

use App\Events\CanvasUpdated;
use App\Models\Canvas;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CanvasController extends Controller
{
    public function show(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        abort_unless($channel->hasMember($request->user()), 403);

        $canvas = Canvas::with('editor:id,name')->where('channel_id', $channel->id)->first();

        return response()->json($canvas?->toPayload() ?? ['body' => '', 'version' => 0, 'updated_at' => null, 'updated_by' => null]);
    }

    public function update(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        $user = $request->user();
        abort_unless($channel->hasMember($user), 403);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:50000'],
            'version' => ['required', 'integer', 'min:0'],
        ]);

        // Two people editing at once: the second save is refused instead of overwriting the first.
        $canvas = DB::transaction(function () use ($channel, $user, $data) {
            $canvas = Canvas::where('channel_id', $channel->id)->lockForUpdate()->first()
                ?? new Canvas(['channel_id' => $channel->id, 'body' => '', 'version' => 0]);

            if ((int) $canvas->version !== (int) $data['version']) {
                return null;
            }

            $canvas->fill([
                'body' => $data['body'] ?? '',
                'version' => $canvas->version + 1,
                'updated_by' => $user->id,
            ])->save();

            return $canvas;
        });

        if (! $canvas) {
            return response()->json(['message' => 'Someone else changed this canvas.'], 409);
        }

        try {
            event(new CanvasUpdated($channel->id, $canvas->version));
        } catch (Throwable $e) {
            report($e); // The canvas is saved; the live update is best effort.
        }

        return response()->json($canvas->load('editor:id,name')->toPayload());
    }
}
