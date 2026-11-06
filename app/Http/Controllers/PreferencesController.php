<?php

namespace App\Http\Controllers;

use App\Events\MemberUpdated;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Throwable;

class PreferencesController extends Controller
{
    public function update(Request $request): Response
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
            // Browsers still report older names such as Asia/Calcutta.
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'title' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'dnd_from' => ['nullable', 'date_format:H:i', 'required_with:dnd_to'],
            'dnd_to' => ['nullable', 'date_format:H:i', 'required_with:dnd_from'],
        ]);

        $request->user()->update($data);
        $this->announce($request->user());

        return response()->noContent();
    }

    /** Pauses notifications for a while; 0 turns the pause off. */
    public function pause(Request $request): Response
    {
        $data = $request->validate(['minutes' => ['required', Rule::in([0, 30, 60, 120, 480, 1440])]]);
        $minutes = (int) $data['minutes'];

        $request->user()->update(['dnd_until' => $minutes ? now()->addMinutes($minutes) : null]);
        $this->announce($request->user());

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
            report($e); // The change is saved; the live update is best effort.
        }
    }
}
