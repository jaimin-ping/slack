import AgentAvatar from '@/Components/AI/AgentAvatar';
import {
    errorText,
    formatDue,
    PRIORITY_STYLES,
    relative,
    STATUS_LABELS,
    STATUS_STYLES,
    TaskCounts,
    TaskItem,
    TaskPriority,
    TaskScope,
    tasksApi,
    TaskStatus,
    toLocalInput,
} from '@/Components/AI/api';
import { usePersonalEvents } from '@/Components/AI/live';
import Avatar from '@/Components/Chat/Avatar';
import { BackIcon, PlusIcon, SearchIcon } from '@/Components/Chat/Icons';
import { ChatUser } from '@/types/chat';
import { router } from '@inertiajs/react';
import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';

/** What "Create task" on a message passes in. */
export interface TaskDraft {
    title: string;
    source_message_id: number;
}

interface Props {
    slug: string;
    workspaceId: number;
    me: ChatUser;
    members: ChatUser[];
    draft: TaskDraft | null;
    onDraftUsed: () => void;
    onClose: () => void;
}

const TABS: { scope: TaskScope; label: string }[] = [
    { scope: 'mine', label: 'My tasks' },
    { scope: 'assigned_by_me', label: 'I assigned' },
    { scope: 'pending_approval', label: 'Suggestions' },
    { scope: 'team', label: 'Team' },
    { scope: 'completed', label: 'Done' },
];

const PRIORITIES: TaskPriority[] = ['low', 'normal', 'high', 'urgent'];
const SETTABLE: TaskStatus[] = ['pending', 'in_progress', 'blocked', 'completed', 'cancelled'];

export default function TasksPane({ slug, workspaceId, me, members, draft, onDraftUsed, onClose }: Props) {
    const api = useMemo(() => tasksApi(slug), [slug]);
    const [scope, setScope] = useState<TaskScope>('mine');
    const [q, setQ] = useState('');
    const [due, setDue] = useState('');
    const [sort, setSort] = useState<'recent' | 'due' | 'priority'>('due');
    const [tasks, setTasks] = useState<TaskItem[] | null>(null);
    const [counts, setCounts] = useState<TaskCounts>({});
    const [openId, setOpenId] = useState<number | null>(null);
    const [creating, setCreating] = useState<TaskDraft | 'blank' | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(async () => {
        try {
            const params: Record<string, unknown> = { scope, sort, per_page: 100 };
            if (q.trim()) params.q = q.trim();
            if (due) params.due = due;
            const [page, c] = await Promise.all([api.list(params), api.counts()]);
            setTasks(page.data);
            setCounts(c);
            setError(null);
        } catch (e) {
            setError(errorText(e, 'Could not load tasks.'));
        }
    }, [api, scope, q, due, sort]);

    useEffect(() => {
        const timer = setTimeout(load, q ? 250 : 0);
        return () => clearTimeout(timer);
    }, [load, q]);

    useEffect(() => {
        if (draft) {
            setCreating(draft);
            setOpenId(null);
            onDraftUsed();
        }
    }, [draft, onDraftUsed]);

    useEffect(() => {
        const key = (e: KeyboardEvent) => e.key === 'Escape' && !openId && !creating && onClose();
        document.addEventListener('keydown', key);
        return () => document.removeEventListener('keydown', key);
    }, [onClose, openId, creating]);

    // Live: any change to a task you can see refreshes the list (the event carries only the id).
    const [changed, setChanged] = useState(0);
    usePersonalEvents(me.id, {
        'task.changed': (event: { workspace_id: number }) => {
            if (event.workspace_id === workspaceId) {
                load();
                setChanged((n) => n + 1);
            }
        },
    });

    return (
        <aside aria-label="Tasks" className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden border-l border-slate-200 bg-white md:w-[26rem] md:flex-none">
            <header className="flex h-12 shrink-0 items-center justify-between gap-2 border-b border-slate-200 px-4">
                <h2 className="font-bold text-slate-900">Tasks</h2>
                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        onClick={() => {
                            setCreating('blank');
                            setOpenId(null);
                        }}
                        className="flex items-center gap-1 rounded-md bg-teal-700 px-2 py-1 text-sm font-semibold text-white hover:bg-teal-800"
                    >
                        <PlusIcon className="h-4 w-4" /> New
                    </button>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close tasks"
                        className="rounded px-2 py-0.5 text-lg leading-none text-slate-600 hover:bg-slate-100"
                    >
                        ×
                    </button>
                </div>
            </header>

            {creating ? (
                <TaskForm
                    api={api}
                    members={members}
                    me={me}
                    draft={creating === 'blank' ? null : creating}
                    onCancel={() => setCreating(null)}
                    onSaved={(task) => {
                        setCreating(null);
                        setOpenId(task.id);
                        load();
                    }}
                />
            ) : openId ? (
                <TaskDetail
                    key={`${openId}-${changed}`}
                    api={api}
                    id={openId}
                    me={me}
                    members={members}
                    onBack={() => setOpenId(null)}
                    onChanged={load}
                    onDeleted={() => {
                        setOpenId(null);
                        load();
                    }}
                />
            ) : (
                <>
                    <nav aria-label="Task lists" className="flex gap-1 overflow-x-auto border-b border-slate-200 px-2 py-1.5">
                        {TABS.map(({ scope: s, label }) => (
                            <button
                                key={s}
                                type="button"
                                onClick={() => setScope(s)}
                                aria-current={scope === s ? 'page' : undefined}
                                className={`whitespace-nowrap rounded-md px-2 py-1 text-xs font-semibold ${
                                    scope === s ? 'bg-teal-50 text-teal-800' : 'text-slate-600 hover:bg-slate-100'
                                }`}
                            >
                                {label}
                                {counts[s] ? <span className="ml-1 text-slate-500">{counts[s]}</span> : null}
                            </button>
                        ))}
                    </nav>

                    <div className="flex items-center gap-2 border-b border-slate-100 px-3 py-2">
                        <label className="relative min-w-0 flex-1">
                            <span className="sr-only">Search tasks</span>
                            <SearchIcon className="pointer-events-none absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Search tasks"
                                className="w-full rounded-md border-slate-300 py-1 pl-7 text-sm"
                            />
                        </label>
                        <select
                            aria-label="Due"
                            value={due}
                            onChange={(e) => setDue(e.target.value)}
                            className="rounded-md border-slate-300 py-1 text-xs"
                        >
                            <option value="">Any time</option>
                            <option value="overdue">Overdue{counts.overdue ? ` (${counts.overdue})` : ''}</option>
                            <option value="today">Due today</option>
                            <option value="week">This week</option>
                            <option value="none">No deadline</option>
                        </select>
                        <select
                            aria-label="Sort"
                            value={sort}
                            onChange={(e) => setSort(e.target.value as typeof sort)}
                            className="rounded-md border-slate-300 py-1 text-xs"
                        >
                            <option value="due">By deadline</option>
                            <option value="priority">By priority</option>
                            <option value="recent">Newest</option>
                        </select>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {error && <p className="m-3 rounded-md bg-rose-50 p-2 text-sm text-rose-700">{error}</p>}
                        {tasks === null && !error && <p className="p-4 text-sm text-slate-500">Loading…</p>}
                        {tasks?.length === 0 && (
                            <p className="p-6 text-center text-sm text-slate-500">
                                {scope === 'pending_approval'
                                    ? 'No suggestions waiting. Tasks your agents find will appear here for you to approve.'
                                    : 'No tasks here yet.'}
                            </p>
                        )}
                        <ul>
                            {tasks?.map((task) => (
                                <li key={task.id}>
                                    <TaskRow task={task} onOpen={() => setOpenId(task.id)} />
                                </li>
                            ))}
                        </ul>
                    </div>
                </>
            )}
        </aside>
    );
}

function StatusPill({ status }: { status: TaskStatus }) {
    return <span className={`rounded px-1.5 py-0.5 text-[11px] font-semibold ${STATUS_STYLES[status]}`}>{STATUS_LABELS[status]}</span>;
}

function TaskRow({ task, onOpen }: { task: TaskItem; onOpen: () => void }) {
    return (
        <button type="button" onClick={onOpen} className="flex w-full gap-3 border-b border-slate-100 px-4 py-2.5 text-left hover:bg-slate-50">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <StatusPill status={task.status} />
                    {task.priority !== 'normal' && (
                        <span className={`text-[11px] uppercase ${PRIORITY_STYLES[task.priority]}`}>{task.priority}</span>
                    )}
                    {task.agent && (
                        <span className="rounded bg-violet-50 px-1 text-[10px] font-semibold text-violet-700" title={`Found by ${task.agent.display_name}`}>
                            AI
                        </span>
                    )}
                </div>
                <p className={`mt-1 truncate text-sm font-medium ${task.status === 'cancelled' ? 'text-slate-400 line-through' : 'text-slate-900'}`}>
                    {task.title}
                </p>
                <p className="mt-0.5 truncate text-xs text-slate-500">
                    {task.due_at && (
                        <span className={task.is_overdue ? 'font-semibold text-rose-700' : ''}>
                            {task.is_overdue ? 'Overdue · ' : 'Due '}
                            {formatDue(task.due_at)}
                        </span>
                    )}
                    {task.due_at && task.source?.channel_label && ' · '}
                    {task.source?.channel_label}
                </p>
            </div>
            <div className="flex shrink-0 -space-x-1.5 self-center">
                {task.assignees.slice(0, 3).map((person) => (
                    <Avatar key={person.id} user={person} className="h-6 w-6 rounded-full ring-2 ring-white" />
                ))}
            </div>
        </button>
    );
}

type Api = ReturnType<typeof tasksApi>;

function AssigneePicker({ members, value, onChange }: { members: ChatUser[]; value: number[]; onChange: (ids: number[]) => void }) {
    const [query, setQuery] = useState('');
    const matches = members.filter((m) => !value.includes(m.id) && m.name.toLowerCase().includes(query.toLowerCase())).slice(0, 6);

    return (
        <div>
            <div className="flex flex-wrap gap-1">
                {value.map((id) => {
                    const person = members.find((m) => m.id === id);
                    return (
                        <span key={id} className="flex items-center gap-1 rounded-full bg-slate-100 py-0.5 pl-1 pr-2 text-xs">
                            {person && <Avatar user={person} className="h-4 w-4 rounded-full" />}
                            {person?.name ?? `#${id}`}
                            <button type="button" aria-label={`Remove ${person?.name}`} onClick={() => onChange(value.filter((v) => v !== id))}>
                                ×
                            </button>
                        </span>
                    );
                })}
            </div>
            <input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Add a person…"
                aria-label="Add assignee"
                className="mt-1 w-full rounded-md border-slate-300 py-1 text-sm"
            />
            {query && (
                <ul className="mt-1 rounded-md border border-slate-200 bg-white shadow-sm">
                    {matches.map((m) => (
                        <li key={m.id}>
                            <button
                                type="button"
                                onClick={() => {
                                    onChange([...value, m.id]);
                                    setQuery('');
                                }}
                                className="flex w-full items-center gap-2 px-2 py-1 text-left text-sm hover:bg-slate-50"
                            >
                                <Avatar user={m} className="h-5 w-5 rounded" /> {m.name}
                            </button>
                        </li>
                    ))}
                    {matches.length === 0 && <li className="px-2 py-1 text-sm text-slate-500">Nobody matches.</li>}
                </ul>
            )}
        </div>
    );
}

function TaskForm({
    api,
    members,
    me,
    draft,
    onCancel,
    onSaved,
}: {
    api: Api;
    members: ChatUser[];
    me: ChatUser;
    draft: TaskDraft | null;
    onCancel: () => void;
    onSaved: (task: TaskItem) => void;
}) {
    const [title, setTitle] = useState(draft?.title ?? '');
    const [description, setDescription] = useState('');
    const [priority, setPriority] = useState<TaskPriority>('normal');
    const [dueAt, setDueAt] = useState('');
    const [assignees, setAssignees] = useState<number[]>([me.id]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        try {
            onSaved(
                await api.create({
                    title,
                    description: description || null,
                    priority,
                    due_at: dueAt ? new Date(dueAt).toISOString() : null,
                    assignee_ids: assignees,
                    ...(draft ? { source_message_id: draft.source_message_id } : {}),
                }),
            );
        } catch (err) {
            setError(errorText(err));
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
            <h3 className="font-semibold text-slate-900">{draft ? 'Task from message' : 'New task'}</h3>
            {draft && <p className="rounded-md bg-slate-50 p-2 text-xs text-slate-600">It will link back to the message it came from.</p>}
            <label className="block text-sm">
                <span className="font-medium text-slate-700">Title</span>
                <input required maxLength={200} value={title} onChange={(e) => setTitle(e.target.value)} className="mt-1 w-full rounded-md border-slate-300 text-sm" />
            </label>
            <label className="block text-sm">
                <span className="font-medium text-slate-700">Details</span>
                <textarea rows={3} value={description} onChange={(e) => setDescription(e.target.value)} className="mt-1 w-full rounded-md border-slate-300 text-sm" />
            </label>
            <div className="grid grid-cols-2 gap-2">
                <label className="block text-sm">
                    <span className="font-medium text-slate-700">Priority</span>
                    <select value={priority} onChange={(e) => setPriority(e.target.value as TaskPriority)} className="mt-1 w-full rounded-md border-slate-300 text-sm">
                        {PRIORITIES.map((p) => (
                            <option key={p} value={p}>
                                {p}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="block text-sm">
                    <span className="font-medium text-slate-700">Deadline</span>
                    <input type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} className="mt-1 w-full rounded-md border-slate-300 text-sm" />
                </label>
            </div>
            <div className="text-sm">
                <span className="font-medium text-slate-700">Assignees</span>
                <AssigneePicker members={members} value={assignees} onChange={setAssignees} />
            </div>
            {error && <p className="rounded-md bg-rose-50 p-2 text-sm text-rose-700">{error}</p>}
            <div className="flex justify-end gap-2">
                <button type="button" onClick={onCancel} className="rounded-md px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">
                    Cancel
                </button>
                <button disabled={busy} className="rounded-md bg-teal-700 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">
                    Create task
                </button>
            </div>
        </form>
    );
}

function describeEvent(event: { type: string; data: Record<string, unknown>; body: string | null }): string {
    const d = event.data;
    switch (event.type) {
        case 'created':
            return 'created the task';
        case 'status':
            return `changed status to ${STATUS_LABELS[(d.to as TaskStatus) ?? 'pending'] ?? d.to}`;
        case 'assigned':
            return 'changed the assignees';
        case 'updated':
            return `updated ${((d.fields as string[]) ?? []).map((f) => f.replace(/_/g, ' ')).join(', ') || 'the task'}`;
        case 'paused':
            return 'paused the task';
        case 'resumed':
            return 'resumed the task';
        case 'approved':
            return 'approved the suggestion';
        case 'rejected':
            return 'dismissed the suggestion';
        case 'comment':
            return 'commented';
        case 'message_linked':
            return d.relation === 'completion' ? 'marked it done from a message' : 'linked an update from a message';
        case 'dependencies':
            return 'changed what it depends on';
        case 'source_edited':
            return 'source message was edited';
        case 'source_removed':
            return 'source message was deleted';
        default:
            return event.type.replace(/_/g, ' ');
    }
}

function TaskDetail({
    api,
    id,
    me,
    members,
    onBack,
    onChanged,
    onDeleted,
}: {
    api: Api;
    id: number;
    me: ChatUser;
    members: ChatUser[];
    onBack: () => void;
    onChanged: () => void;
    onDeleted: () => void;
}) {
    const [task, setTask] = useState<TaskItem | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [comment, setComment] = useState('');
    const [editing, setEditing] = useState(false);

    useEffect(() => {
        api.show(id).then(setTask).catch((e) => setError(errorText(e, 'This task is no longer available.')));
    }, [api, id]);

    const act = async (call: () => Promise<TaskItem | unknown>) => {
        try {
            const result = await call();
            if (result && typeof result === 'object' && 'id' in result && 'title' in result) setTask(result as TaskItem);
            else setTask(await api.show(id));
            setError(null);
            onChanged();
        } catch (e) {
            setError(errorText(e));
        }
    };

    if (!task) {
        return (
            <div className="p-4">
                <button type="button" onClick={onBack} className="mb-3 flex items-center gap-1 text-sm text-slate-600">
                    <BackIcon className="h-4 w-4" /> All tasks
                </button>
                <p className="text-sm text-slate-500">{error ?? 'Loading…'}</p>
            </div>
        );
    }

    const manage = task.can.manage;
    const work = task.can.work;

    return (
        <div className="min-h-0 flex-1 overflow-y-auto">
            <div className="space-y-4 p-4">
                <button type="button" onClick={onBack} className="flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
                    <BackIcon className="h-4 w-4" /> All tasks
                </button>

                {editing && manage ? (
                    <EditFields
                        task={task}
                        onCancel={() => setEditing(false)}
                        onSave={(data) => act(() => api.update(task.id, data)).then(() => setEditing(false))}
                    />
                ) : (
                    <div>
                        <div className="flex items-center gap-2">
                            <StatusPill status={task.status} />
                            <span className={`text-xs uppercase ${PRIORITY_STYLES[task.priority]}`}>{task.priority}</span>
                            {task.archived && <span className="text-xs text-slate-500">Archived</span>}
                        </div>
                        <h3 className="mt-2 text-lg font-bold text-slate-900">{task.title}</h3>
                        {task.description && <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">{task.description}</p>}
                        <p className="mt-2 text-sm text-slate-600">
                            {task.due_at ? (
                                <span className={task.is_overdue ? 'font-semibold text-rose-700' : ''}>
                                    {task.is_overdue ? 'Overdue — was due ' : 'Due '}
                                    {formatDue(task.due_at)}
                                </span>
                            ) : (
                                'No deadline'
                            )}
                        </p>
                        {manage && (
                            <button type="button" onClick={() => setEditing(true)} className="mt-2 text-sm font-medium text-teal-700 hover:underline">
                                Edit details
                            </button>
                        )}
                    </div>
                )}

                {task.status === 'suggested' && manage && (
                    <div className="rounded-lg border border-violet-200 bg-violet-50 p-3">
                        <p className="text-sm text-violet-900">
                            {task.agent ? `${task.agent.display_name} suggested this task` : 'This task is a suggestion'}
                            {task.confidence !== null && ` (confidence ${Math.round(task.confidence * 100)}%)`}. Approve it to make it a real task.
                        </p>
                        <div className="mt-2 flex gap-2">
                            <button type="button" onClick={() => act(() => api.action(task.id, 'approve'))} className="rounded-md bg-violet-700 px-3 py-1 text-sm font-semibold text-white">
                                Approve
                            </button>
                            <button type="button" onClick={() => act(() => api.action(task.id, 'reject'))} className="rounded-md bg-white px-3 py-1 text-sm text-slate-700 ring-1 ring-slate-300">
                                Dismiss
                            </button>
                        </div>
                    </div>
                )}

                {work && task.status !== 'suggested' && (
                    <div className="flex flex-wrap items-center gap-2">
                        {task.status === 'paused' ? (
                            <button type="button" onClick={() => act(() => api.action(task.id, 'resume'))} className="rounded-md bg-teal-700 px-3 py-1 text-sm font-semibold text-white">
                                Resume
                            </button>
                        ) : (
                            <>
                                <label className="text-sm text-slate-700">
                                    <span className="sr-only">Status</span>
                                    <select
                                        value={task.status}
                                        onChange={(e) => act(() => api.status(task.id, e.target.value as TaskStatus))}
                                        className="rounded-md border-slate-300 py-1 text-sm"
                                    >
                                        {SETTABLE.map((s) => (
                                            <option key={s} value={s}>
                                                {STATUS_LABELS[s]}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                {['pending', 'in_progress', 'blocked'].includes(task.status) && (
                                    <button type="button" onClick={() => act(() => api.action(task.id, 'pause'))} className="rounded-md px-2 py-1 text-sm text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                                        Pause
                                    </button>
                                )}
                            </>
                        )}
                        {manage && (
                            <button
                                type="button"
                                onClick={() => act(() => api.action(task.id, task.archived ? 'unarchive' : 'archive'))}
                                className="rounded-md px-2 py-1 text-sm text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50"
                            >
                                {task.archived ? 'Unarchive' : 'Archive'}
                            </button>
                        )}
                        {manage && (
                            <button
                                type="button"
                                onClick={async () => {
                                    if (!confirm('Delete this task for everyone?')) return;
                                    try {
                                        await api.remove(task.id);
                                        onDeleted();
                                    } catch (e) {
                                        setError(errorText(e));
                                    }
                                }}
                                className="ml-auto rounded-md px-2 py-1 text-sm text-rose-700 hover:bg-rose-50"
                            >
                                Delete
                            </button>
                        )}
                    </div>
                )}

                {error && <p className="rounded-md bg-rose-50 p-2 text-sm text-rose-700">{error}</p>}

                <section>
                    <h4 className="text-xs font-semibold uppercase text-slate-500">Assignees</h4>
                    {manage ? (
                        <AssigneePicker members={members} value={task.assignees.map((a) => a.id)} onChange={(ids) => act(() => api.assign(task.id, ids))} />
                    ) : (
                        <div className="mt-1 flex flex-wrap gap-2">
                            {task.assignees.length === 0 && <span className="text-sm text-slate-500">Nobody yet</span>}
                            {task.assignees.map((p) => (
                                <span key={p.id} className="flex items-center gap-1 text-sm">
                                    <Avatar user={p} className="h-5 w-5 rounded-full" /> {p.name}
                                </span>
                            ))}
                        </div>
                    )}
                </section>

                <section className="text-sm text-slate-600">
                    <h4 className="text-xs font-semibold uppercase text-slate-500">Created</h4>
                    <p className="mt-1 flex items-center gap-2">
                        {task.agent ? (
                            <>
                                <AgentAvatar agent={task.agent} className="h-5 w-5 rounded" />
                                {task.agent.display_name}, for {task.creator?.name}
                            </>
                        ) : (
                            task.creator?.name
                        )}
                        <span className="text-slate-400">· {relative(task.created_at)}</span>
                    </p>
                </section>

                {task.source && (
                    <section>
                        <h4 className="text-xs font-semibold uppercase text-slate-500">From a message</h4>
                        {task.source.available ? (
                            <div className="mt-1 rounded-md border-l-4 border-slate-300 bg-slate-50 p-2 text-sm">
                                {task.source.quote && <p className="italic text-slate-700">“{task.source.quote}”</p>}
                                <p className="mt-1 text-xs text-slate-500">
                                    {task.source.channel_label}
                                    {task.source.state === 'edited' && ' · the message was edited since'}
                                    {task.source.state === 'removed' && ' · the message was deleted'}
                                </p>
                                {task.source.url && task.source.state !== 'removed' && (
                                    <button type="button" onClick={() => router.visit(task.source!.url!)} className="mt-1 text-xs font-medium text-teal-700 hover:underline">
                                        Open message
                                    </button>
                                )}
                            </div>
                        ) : (
                            <p className="mt-1 text-sm text-slate-500">From a conversation you are not in.</p>
                        )}
                    </section>
                )}

                {task.dependencies && task.dependencies.length > 0 && (
                    <section>
                        <h4 className="text-xs font-semibold uppercase text-slate-500">Depends on</h4>
                        <ul className="mt-1 space-y-1 text-sm">
                            {task.dependencies.map((d) => (
                                <li key={d.id} className="flex items-center gap-2">
                                    <StatusPill status={d.status} /> {d.visible ? d.title : 'A task you cannot see'}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section>
                    <h4 className="text-xs font-semibold uppercase text-slate-500">Activity</h4>
                    <ol className="mt-2 space-y-2">
                        {task.history?.map((event) => (
                            <li key={event.id} className="text-sm">
                                <span className="font-medium text-slate-800">{event.agent?.name ?? event.actor?.name ?? 'Someone'}</span>{' '}
                                <span className="text-slate-600">{describeEvent(event)}</span>{' '}
                                <span className="text-xs text-slate-400">{relative(event.created_at)}</span>
                                {event.body && <p className="mt-0.5 whitespace-pre-wrap rounded-md bg-slate-50 p-2 text-slate-700">{event.body}</p>}
                            </li>
                        ))}
                    </ol>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!comment.trim()) return;
                            act(() => api.comment(task.id, comment.trim())).then(() => setComment(''));
                        }}
                        className="mt-3 flex gap-2"
                    >
                        <input
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            placeholder={`Comment as ${me.name}`}
                            aria-label="Comment"
                            className="min-w-0 flex-1 rounded-md border-slate-300 py-1 text-sm"
                        />
                        <button className="rounded-md bg-slate-800 px-3 py-1 text-sm font-semibold text-white">Post</button>
                    </form>
                </section>
            </div>
        </div>
    );
}

function EditFields({ task, onCancel, onSave }: { task: TaskItem; onCancel: () => void; onSave: (data: Record<string, unknown>) => void }) {
    const [title, setTitle] = useState(task.title);
    const [description, setDescription] = useState(task.description ?? '');
    const [priority, setPriority] = useState(task.priority);
    const [dueAt, setDueAt] = useState(toLocalInput(task.due_at));

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSave({ title, description: description || null, priority, due_at: dueAt ? new Date(dueAt).toISOString() : null });
            }}
            className="space-y-2"
        >
            <input aria-label="Title" required maxLength={200} value={title} onChange={(e) => setTitle(e.target.value)} className="w-full rounded-md border-slate-300 text-sm font-semibold" />
            <textarea aria-label="Details" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} className="w-full rounded-md border-slate-300 text-sm" />
            <div className="grid grid-cols-2 gap-2">
                <select aria-label="Priority" value={priority} onChange={(e) => setPriority(e.target.value as TaskPriority)} className="rounded-md border-slate-300 text-sm">
                    {PRIORITIES.map((p) => (
                        <option key={p}>{p}</option>
                    ))}
                </select>
                <input aria-label="Deadline" type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} className="rounded-md border-slate-300 text-sm" />
            </div>
            <div className="flex justify-end gap-2">
                <button type="button" onClick={onCancel} className="rounded-md px-3 py-1 text-sm text-slate-700 hover:bg-slate-100">
                    Cancel
                </button>
                <button className="rounded-md bg-teal-700 px-3 py-1 text-sm font-semibold text-white">Save</button>
            </div>
        </form>
    );
}
