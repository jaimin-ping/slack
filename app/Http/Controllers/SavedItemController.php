<?php

namespace App\Http\Controllers;

use App\Models\SavedItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SavedItem::where('user_id', $request->user()->id)
            ->with('saveable')
            ->latest();

        if ($request->has('type') && $request->type !== 'all') {
            $typeMap = [
                'message' => 'App\\Models\\Message',
                'attachment' => 'App\\Models\\Attachment',
            ];
            if (isset($typeMap[$request->type])) {
                $query->where('saveable_type', $typeMap[$request->type]);
            }
        }

        $perPage = $request->integer('per_page', 20);
        $items = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $items->map(fn ($item) => $item->toPayload()),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'saveable_type' => 'required|in:message,attachment',
            'saveable_id' => 'required|integer',
        ]);

        $typeMap = [
            'message' => 'App\\Models\\Message',
            'attachment' => 'App\\Models\\Attachment',
        ];

        $savedItem = SavedItem::firstOrCreate([
            'user_id' => $request->user()->id,
            'saveable_type' => $typeMap[$request->saveable_type],
            'saveable_id' => $request->saveable_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item saved successfully',
            'data' => $savedItem->toPayload(),
        ], 201);
    }

    public function destroy(Request $request, SavedItem $savedItem): JsonResponse
    {
        if ($savedItem->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $savedItem->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removed from saved',
        ]);
    }

    public function destroyByItem(Request $request): JsonResponse
    {
        $request->validate([
            'saveable_type' => 'required|in:message,attachment',
            'saveable_id' => 'required|integer',
        ]);

        $typeMap = [
            'message' => 'App\\Models\\Message',
            'attachment' => 'App\\Models\\Attachment',
        ];

        $deleted = SavedItem::where('user_id', $request->user()->id)
            ->where('saveable_type', $typeMap[$request->saveable_type])
            ->where('saveable_id', $request->saveable_id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => $deleted ? 'Item removed from saved' : 'Item not found',
        ]);
    }
}
