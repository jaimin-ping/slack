<?php

namespace App\Http\Controllers;

use App\Events\MemberUpdated;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AvatarController extends Controller
{
    public function show(Request $request, User $user): StreamedResponse
    {
        $viewer = $request->user();
        $sharesWorkspace = $viewer->is($user)
            || $user->workspaces()->whereIn('workspaces.id', $viewer->workspaces()->pluck('workspaces.id'))->exists();

        abort_unless($sharesWorkspace && $user->avatar_path, 404);

        return Storage::disk('local')->response($user->avatar_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function store(Request $request): Response
    {
        $request->validate(['photo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);

        $user = $request->user();
        $previous = $user->avatar_path;

        $user->update(['avatar_path' => $request->file('photo')->store('avatars', 'local')]);

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        $this->announce($user);

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
            $this->announce($user);
        }

        return response()->noContent();
    }

    private function announce(User $user): void
    {
        $workspaceIds = $user->workspaces()->pluck('workspaces.id')->all();

        if ($workspaceIds === []) {
            return;
        }

        try {
            event(new MemberUpdated($workspaceIds));
        } catch (Throwable $e) {
            report($e); // The photo is saved; the live update is best effort.
        }
    }
}
