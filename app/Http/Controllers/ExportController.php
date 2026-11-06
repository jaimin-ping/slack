<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ExportController extends Controller
{
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** Everything this person wrote, and nothing other people wrote. */
    public function personal(Request $request): BinaryFileResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();
        $zip = $this->newZip();

        $zip->archive->addFromString('profile.json', json_encode([
            'name' => $user->name,
            'email' => $user->email,
            'title' => $user->title,
            'phone' => $user->phone,
            'timezone' => $user->timezone,
            'theme' => $user->theme,
            'joined_at' => $user->created_at?->toIso8601String(),
            'workspaces' => $user->workspaces()->pluck('name'),
        ], self::FLAGS));

        $temp = $this->writeMessages(
            Message::with(['channel', 'attachments', 'reactions'])->where('user_id', $user->id)->whereNull('removed_at'),
            fn (Message $message) => ['channel' => $this->label($message->channel)],
        );

        $zip->archive->addFile($temp, 'messages.jsonl');
        $zip->archive->close();
        @unlink($temp);

        return $this->download($zip->path, 'ping-chat-my-data.zip');
    }

    /** Public channels and the owner's own private channels. Direct messages stay private. */
    public function workspace(Request $request, Workspace $workspace): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($workspace->owner_id === $user->id, 403);

        $request->validate(['password' => ['required', 'current_password']]);

        AuditLog::record($workspace, $user, 'workspace.exported');

        $channels = $workspace->channels()
            ->where(fn ($query) => $query
                ->where('type', Channel::PUBLIC)
                ->orWhere(fn ($private) => $private
                    ->where('type', Channel::PRIVATE)
                    ->whereHas('members', fn ($members) => $members->whereKey($user->id))))
            ->orderBy('name')
            ->get();

        $zip = $this->newZip();
        $temps = [];

        $zip->archive->addFromString('README.txt', "Ping Chat export of {$workspace->name}\n"
            ."Created: ".now()->toIso8601String()."\n\n"
            ."Included: public channels, and private channels the workspace owner belongs to.\n"
            ."Not included: direct messages, other private channels, and file contents (file names are listed).\n"
            ."Each channel is a .jsonl file with one message per line.\n");

        $zip->archive->addFromString('workspace.json', json_encode($workspace->only('name', 'slug', 'created_at'), self::FLAGS));

        $zip->archive->addFromString('members.json', json_encode(
            $workspace->members()->orderBy('name')->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($member) => [
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->id === $workspace->owner_id ? 'owner' : $member->pivot->role,
                ]),
            self::FLAGS,
        ));

        $zip->archive->addFromString('channels.json', json_encode(
            $channels->map(fn (Channel $channel) => [
                'name' => $channel->name,
                'type' => $channel->type,
                'topic' => $channel->topic,
                'archived' => $channel->archived_at !== null,
            ]),
            self::FLAGS,
        ));

        foreach ($channels as $channel) {
            $temp = $this->writeMessages(
                $channel->messages()->with(['user:id,name', 'attachments', 'reactions'])->whereNull('removed_at')->getQuery(),
                fn (Message $message) => ['author' => $message->user?->name ?? $message->bot_name],
            );

            $zip->archive->addFile($temp, 'channels/'.Str::slug($channel->name).'.jsonl');
            $temps[] = $temp;
        }

        $zip->archive->close();
        array_map('unlink', $temps);

        return $this->download($zip->path, Str::slug($workspace->name).'-export.zip');
    }

    /** Streams to a temporary file, so a large history never sits in memory. */
    private function writeMessages($query, callable $extra): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export');
        $file = fopen($path, 'w');

        foreach ($query->lazyById(500) as $message) {
            fwrite($file, json_encode([
                ...$extra($message),
                'id' => $message->id,
                'thread_of' => $message->parent_id,
                'text' => $message->body,
                'cards' => $message->cards,
                'files' => $message->attachments->pluck('name'),
                'reactions' => $message->reactions->pluck('emoji'),
                'created_at' => $message->created_at->toIso8601String(),
                'edited_at' => $message->edited_at?->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }

        fclose($file);

        return $path;
    }

    private function label(Channel $channel): string
    {
        return $channel->type === Channel::DM ? 'Direct message' : '#'.$channel->name;
    }

    private function newZip(): object
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::OVERWRITE);

        return (object) ['path' => $path, 'archive' => $archive];
    }

    private function download(string $path, string $name): BinaryFileResponse
    {
        return response()->download($path, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
