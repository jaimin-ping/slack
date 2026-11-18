<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\SectionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Section::where('user_id', $request->user()->id)
            ->withCount('items')
            ->orderBy('sort_order')
            ->latest();

        if ($request->has('workspace_id')) {
            $query->where('workspace_id', $request->workspace_id);
        }

        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        $sections = $query->get();

        return response()->json([
            'success' => true,
            'data' => $sections->map(fn ($section) => $section->toPayload()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'icon' => 'nullable|string|max:50',
            'workspace_id' => 'required|exists:workspaces,id',
        ]);

        $maxOrder = Section::where('user_id', $request->user()->id)
            ->where('workspace_id', $request->workspace_id)
            ->max('sort_order') ?? 0;

        $section = Section::create([
            'user_id' => $request->user()->id,
            'workspace_id' => $request->workspace_id,
            'name' => $request->name,
            'icon' => $request->icon ?? 'bookmark',
            'type' => 'custom',
            'is_system' => false,
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Section created successfully',
            'data' => $section->toPayload(),
        ], 201);
    }

    public function update(Request $request, Section $section): JsonResponse
    {
        if ($section->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:100',
            'icon' => 'nullable|string|max:50',
        ]);

        $section->update($request->only(['name', 'icon']));

        return response()->json([
            'success' => true,
            'message' => 'Section updated successfully',
            'data' => $section->fresh()->toPayload(),
        ]);
    }

    public function destroy(Request $request, Section $section): JsonResponse
    {
        if ($section->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($section->is_system) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete system sections',
            ], 403);
        }

        $section->delete();

        return response()->json([
            'success' => true,
            'message' => 'Section deleted successfully',
        ]);
    }

    public function items(Request $request, Section $section): JsonResponse
    {
        if ($section->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $items = $section->items()->with('attachment')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'section' => [
                    'id' => $section->id,
                    'name' => $section->name,
                ],
                'items' => $items->map(fn ($item) => $item->toPayload()),
            ],
        ]);
    }

    public function addItem(Request $request, Section $section): JsonResponse
    {
        if ($section->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $request->validate([
            'attachment_id' => 'required|exists:attachments,id',
        ]);

        $item = SectionItem::firstOrCreate([
            'section_id' => $section->id,
            'attachment_id' => $request->attachment_id,
        ], [
            'added_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'File added to section',
            'data' => $item->load('attachment')->toPayload(),
        ], 201);
    }

    public function removeItem(Request $request, Section $section, SectionItem $item): JsonResponse
    {
        if ($section->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($item->section_id !== $section->id) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found in this section',
            ], 404);
        }

        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'File removed from section',
        ]);
    }
}
