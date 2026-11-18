<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Todo::where('user_id', $request->user()->id)
            ->with('linked')
            ->latest();

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
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
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
            'linked_type' => 'nullable|in:message,attachment',
            'linked_id' => 'nullable|integer',
        ]);

        $linkedType = null;
        if ($request->linked_type) {
            $typeMap = [
                'message' => 'App\\Models\\Message',
                'attachment' => 'App\\Models\\Attachment',
            ];
            $linkedType = $typeMap[$request->linked_type] ?? null;
        }

        $todo = Todo::create([
            'user_id' => $request->user()->id,
            'title' => $request->title,
            'description' => $request->description,
            'due_date' => $request->due_date,
            'linked_type' => $linkedType,
            'linked_id' => $request->linked_id,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'To-do created successfully',
            'data' => $todo->toPayload(),
        ], 201);
    }

    public function update(Request $request, Todo $todo): JsonResponse
    {
        if ($todo->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:pending,completed',
            'due_date' => 'nullable|date',
        ]);

        $data = $request->only(['title', 'description', 'due_date']);

        if ($request->has('status')) {
            $data['status'] = $request->status;
            if ($request->status === 'completed') {
                $data['completed_at'] = now();
            } else {
                $data['completed_at'] = null;
            }
        }

        $todo->update($data);

        return response()->json([
            'success' => true,
            'message' => 'To-do updated successfully',
            'data' => $todo->fresh()->toPayload(),
        ]);
    }

    public function destroy(Request $request, Todo $todo): JsonResponse
    {
        if ($todo->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $todo->delete();

        return response()->json([
            'success' => true,
            'message' => 'To-do deleted successfully',
        ]);
    }
}
