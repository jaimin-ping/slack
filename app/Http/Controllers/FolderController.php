<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\FolderItem;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Folder::where('user_id', $request->user()->id)
            ->withCount('items')
            ->latest();

        if ($request->has('workspace_id')) {
            $query->where('workspace_id', $request->workspace_id);
        }

        $folders = $query->get();

        return response()->json($folders->map(fn ($folder) => [
            'id' => $folder->id,
            'name' => $folder->name,
            'color' => $folder->color,
            'items_count' => $folder->items_count,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:7',
        ]);

        $folder = Folder::create([
            'user_id' => $request->user()->id,
            'workspace_id' => $request->workspace_id ?? null,
            'name' => $request->name,
            'color' => $request->color ?? '#3b82f6',
            'icon' => $request->icon ?? 'folder',
        ]);

        return response()->json([
            'id' => $folder->id,
            'name' => $folder->name,
            'color' => $folder->color,
            'items_count' => 0,
        ], 201);
    }

    public function update(Request $request, Folder $folder): JsonResponse
    {
        if ($folder->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:100',
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:50',
        ]);

        $folder->update($request->only(['name', 'color', 'icon']));

        return response()->json([
            'success' => true,
            'message' => 'Folder updated successfully',
            'data' => $folder->fresh()->toPayload(),
        ]);
    }

    public function destroy(Request $request, Folder $folder): JsonResponse
    {
        if ($folder->user_id !== $request->user()->id) {
            abort(403);
        }

        $folder->delete();

        return response()->json(['success' => true]);
    }

    public function items(Request $request, Folder $folder): JsonResponse
    {
        if ($folder->user_id !== $request->user()->id) {
            abort(403);
        }

        $items = $folder->items()->with('attachment')->get();

        return response()->json($items->map(fn ($item) => [
            'id' => $item->id,
            'attachment_id' => $item->attachment_id,
            'attachment' => $item->attachment?->toPayload(),
        ]));
    }

    public function addItem(Request $request, Folder $folder): JsonResponse
    {
        if ($folder->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $request->validate([
            'attachment_id' => 'required|exists:attachments,id',
        ]);

        $item = FolderItem::firstOrCreate([
            'folder_id' => $folder->id,
            'attachment_id' => $request->attachment_id,
        ], [
            'added_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'File added to folder',
            'data' => $item->load('attachment')->toPayload(),
        ], 201);
    }

    public function removeItem(Request $request, Folder $folder, FolderItem $item): JsonResponse
    {
        if ($folder->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($item->folder_id !== $folder->id) {
            abort(404);
        }

        $item->delete();

        return response()->json(['success' => true]);
    }
}
