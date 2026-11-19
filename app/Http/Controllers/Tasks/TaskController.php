<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TaskQuery;
use App\Services\Tasks\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Workspace tasks for the web app and the mobile API. Permission checks live in TaskService and TaskAccess. */
class TaskController extends Controller
{
    public function __construct(
        private TaskService $tasks,
        private TaskAccess $access,
        private TaskPresenter $presenter,
        private TaskQuery $query,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $filters = $request->validate([
            'scope' => ['sometimes', Rule::in(TaskQuery::SCOPES)],
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::in(Task::STATUSES)],
            'priority' => ['sometimes', 'array'],
            'priority.*' => [Rule::in(Task::PRIORITIES)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'agent_id' => ['sometimes', 'integer'],
            'channel_id' => ['sometimes', 'integer'],
            'due' => ['sometimes', Rule::in(['overdue', 'today', 'week', 'none'])],
            'archived' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['recent', 'due', 'priority'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = $this->query->filtered($user, $workspace, $filters);
        $this->query->sort($query, $filters['sort'] ?? 'recent');

        $page = $query->with(TaskPresenter::RELATIONS)->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Task $task) => $this->presenter->present($task, $user))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Badge counts for each tab of the task panel. */
    public function counts(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $counts = collect(TaskQuery::SCOPES)->reject(fn ($scope) => $scope === 'all')
            ->mapWithKeys(fn ($scope) => [$scope => $this->query->filtered($user, $workspace, ['scope' => $scope])->count()]);

        $counts['overdue'] = $this->query->filtered($user, $workspace, ['scope' => 'mine', 'due' => 'overdue'])->count();

        return response()->json($counts);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate([
            ...$this->fieldRules(true),
            'assignee_ids' => ['sometimes', 'array', 'max:20'],
            'assignee_ids.*' => ['integer'],
            'source_message_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $task = $this->tasks->create($workspace, $request->user(), $data);

        return response()->json($this->presenter->present($task->fresh(), $request->user(), true), 201);
    }

    public function show(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        return response()->json($this->presenter->present($task, $request->user(), true));
    }

    public function update(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        $data = $request->validate($this->fieldRules(false));

        return $this->detailed($request, $this->tasks->update($task, $request->user(), $data));
    }

    public function destroy(Request $request, Workspace $workspace, Task $task): Response
    {
        $this->authorizeView($request, $workspace, $task);

        $this->tasks->delete($task, $request->user());

        return response()->noContent();
    }

    public function status(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        $data = $request->validate(['status' => ['required', Rule::in(Task::SETTABLE_STATUSES)]]);

        return $this->detailed($request, $this->tasks->changeStatus($task, $request->user(), $data['status']));
    }

    public function pause(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        return $this->detailed($request, $this->tasks->pause($task, $request->user()));
    }

    public function resume(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        return $this->detailed($request, $this->tasks->resume($task, $request->user()));
    }

    public function approve(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        return $this->detailed($request, $this->tasks->approve($task, $request->user()));
    }

    public function reject(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->detailed($request, $this->tasks->reject($task, $request->user(), $data['reason'] ?? null));
    }

    public function assignees(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:20'],
            'user_ids.*' => ['integer'],
        ]);

        return $this->detailed($request, $this->tasks->assign($task, $request->user(), $data['user_ids']));
    }

    public function comment(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $event = $this->tasks->comment($task, $request->user(), $data['body']);

        return response()->json($event->load('actor:id,name')->toPayload(), 201);
    }

    public function archive(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        return $this->detailed($request, $this->tasks->archive($task, $request->user(), true));
    }

    public function unarchive(Request $request, Workspace $workspace, Task $task): JsonResponse
    {
        $this->authorizeView($request, $workspace, $task);

        return $this->detailed($request, $this->tasks->archive($task, $request->user(), false));
    }

    /** A task you cannot see, or one from another workspace, looks the same as one that does not exist. */
    private function authorizeView(Request $request, Workspace $workspace, Task $task): void
    {
        abort_unless($task->workspace_id === $workspace->id && $this->access->canView($request->user(), $task), 404);
    }

    private function detailed(Request $request, Task $task): JsonResponse
    {
        return response()->json($this->presenter->present($task->fresh(), $request->user(), true));
    }

    private function fieldRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'depends_on_ids' => ['sometimes', 'array', 'max:20'],
            'depends_on_ids.*' => ['integer'],
        ];
    }
}
