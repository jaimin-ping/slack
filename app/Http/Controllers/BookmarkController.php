<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\ChannelBookmark;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The links bar under a channel header, like Slack's channel bookmarks. */
class BookmarkController extends Controller
{
    private const MAX_PER_CHANNEL = 50;

    public function store(Request $request, Workspace $workspace, Channel $channel): JsonResponse
    {
        $this->authorizeChannel($request, $channel);

        $data = $request->validate([
            'url' => ['required', 'string', 'max:2000', 'url:http,https'],
            'title' => ['nullable', 'string', 'max:100'],
        ]);

        abort_if($channel->bookmarks()->count() >= self::MAX_PER_CHANNEL, 422, 'This channel has too many bookmarks.');

        $title = trim($data['title'] ?? '') ?: (parse_url($data['url'], PHP_URL_HOST) ?: $data['url']);

        $bookmark = $channel->bookmarks()->create([
            'created_by' => $request->user()->id,
            'title' => mb_substr($title, 0, 100),
            'url' => $data['url'],
        ]);

        return response()->json($bookmark->toPayload(), 201);
    }

    public function destroy(Request $request, Workspace $workspace, Channel $channel, ChannelBookmark $bookmark): Response
    {
        $this->authorizeChannel($request, $channel);
        abort_unless($bookmark->channel_id === $channel->id, 404);

        $bookmark->delete();

        return response()->noContent();
    }

    private function authorizeChannel(Request $request, Channel $channel): void
    {
        abort_unless($channel->hasMember($request->user()) && $channel->type !== Channel::DM && ! $channel->archived_at, 403);
    }
}
