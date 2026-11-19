import axios from 'axios';

/* ---------- Types: these mirror the JSON from the Laravel controllers (same shapes as the mobile API). ---------- */

export type TaskStatus = 'suggested' | 'pending' | 'in_progress' | 'blocked' | 'paused' | 'completed' | 'cancelled';
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent';
export type TaskScope = 'all' | 'mine' | 'assigned_to_me' | 'assigned_by_me' | 'team' | 'pending_approval' | 'completed';

export interface Person {
    id: number;
    name: string;
    avatar_url: string | null;
}

export interface AgentBadge {
    id: number;
    name: string;
    display_name: string;
    avatar_url: string | null;
    avatar_preset: string | null;
}

export interface TaskSource {
    available: boolean;
    state: 'edited' | 'removed' | null;
    message_id?: number;
    channel_id?: number;
    channel_type?: 'public' | 'private' | 'dm';
    channel_label?: string;
    quote?: string | null;
    url?: string;
}

export interface TaskEventItem {
    id: number;
    type: string;
    data: Record<string, unknown>;
    body: string | null;
    actor: { id: number; name: string } | null;
    agent: { id: number; name: string } | null;
    created_at: string;
}

export interface TaskItem {
    id: number;
    workspace_id: number;
    title: string;
    description: string | null;
    status: TaskStatus;
    paused_from: TaskStatus | null;
    priority: TaskPriority;
    start_date: string | null;
    due_at: string | null;
    completed_at: string | null;
    archived: boolean;
    is_overdue: boolean;
    confidence: number | null;
    creator: Person | null;
    agent: AgentBadge | null;
    assignees: Person[];
    source: TaskSource | null;
    can: { manage: boolean; work: boolean };
    created_at: string;
    updated_at: string;
    dependencies?: { id: number; title: string | null; status: TaskStatus; visible: boolean }[];
    history?: TaskEventItem[];
}

export type TaskCounts = Partial<Record<TaskScope | 'overdue', number>>;

export type Policy = 'deny' | 'approve' | 'allow';
export type AgentStatus = 'active' | 'paused' | 'inactive';
export type AgentMode = 'suggest' | 'auto_tasks' | 'controlled';

export interface AgentSettings {
    task_detection: { enabled: boolean; realtime: boolean; min_confidence: number; only_mine: boolean };
    notifications: { notify_assignees: boolean; overdue_alerts: boolean };
    daily_summary: { enabled: boolean; time: string };
}

export interface Agent {
    id: number;
    workspace_id: number;
    owner_id: number;
    name: string;
    display_name: string;
    description: string | null;
    personality: string | null;
    instructions: string | null;
    responsibilities: string | null;
    avatar_url: string | null;
    avatar_preset: string | null;
    status: AgentStatus;
    mode: AgentMode;
    settings: AgentSettings;
    permissions: Record<string, Policy>;
    sources: { channel_id: number; label: string; type: string }[];
    paused_at: string | null;
    last_run_at: string | null;
}

export interface AgentOptions {
    statuses: AgentStatus[];
    modes: AgentMode[];
    policies: Policy[];
    actions: Record<string, string>;
    presets: Record<AgentMode, Record<string, Policy>>;
    avatar_presets: string[];
    default_settings: AgentSettings;
    channels: { id: number; type: string; label: string }[];
}

export interface ChatItem {
    id: number;
    agent_id: number;
    role: 'owner' | 'agent';
    content: string;
    run_id: number | null;
    created_at: string;
}

export interface MemoryItem {
    id: number;
    kind: 'preference' | 'context' | 'instruction' | 'fact';
    content: string;
    origin: 'owner' | 'agent';
    pinned: boolean;
    created_at: string;
    updated_at: string;
}

export interface RunStep {
    sequence: number;
    type: string;
    name: string | null;
    status: string;
    input: unknown;
    output: unknown;
    created_at: string;
}

export interface RunItem {
    id: number;
    agent_id: number;
    trigger: 'message' | 'history' | 'chat' | 'schedule';
    status: 'running' | 'succeeded' | 'failed' | 'skipped' | 'cancelled';
    model: string | null;
    steps: number;
    tokens: { input: number; output: number; cache_read: number; cache_write: number };
    cost_usd: number;
    summary: string | null;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
    step_log?: RunStep[];
}

export interface ApprovalItem {
    id: number;
    agent: { id: number; name: string } | null;
    action: string;
    summary: string;
    payload: Record<string, unknown>;
    task_id: number | null;
    status: 'pending' | 'executed' | 'rejected' | 'failed' | 'expired';
    result: Record<string, unknown> | null;
    error: string | null;
    decided_at: string | null;
    created_at: string;
}

export interface UsageReport {
    since: string;
    scope: 'mine' | 'workspace';
    rows: { agent_id?: number; owner_id?: number; runs: number; tokens: number; cost_usd: number }[];
    total_cost_usd: number;
}

/* ---------- Calls ---------- */

const w = (slug: string) => `/w/${slug}`;

export const tasksApi = (slug: string) => ({
    list: (params: Record<string, unknown>) =>
        axios.get<{ data: TaskItem[]; meta: { total: number; last_page: number } }>(`${w(slug)}/tasks`, { params }).then((r) => r.data),
    counts: () => axios.get<TaskCounts>(`${w(slug)}/tasks/counts`).then((r) => r.data),
    show: (id: number) => axios.get<TaskItem>(`${w(slug)}/tasks/${id}`).then((r) => r.data),
    create: (data: Record<string, unknown>) => axios.post<TaskItem>(`${w(slug)}/tasks`, data).then((r) => r.data),
    update: (id: number, data: Record<string, unknown>) => axios.patch<TaskItem>(`${w(slug)}/tasks/${id}`, data).then((r) => r.data),
    remove: (id: number) => axios.delete(`${w(slug)}/tasks/${id}`),
    status: (id: number, status: TaskStatus) => axios.post<TaskItem>(`${w(slug)}/tasks/${id}/status`, { status }).then((r) => r.data),
    action: (id: number, action: 'pause' | 'resume' | 'approve' | 'reject' | 'archive' | 'unarchive') =>
        axios.post<TaskItem>(`${w(slug)}/tasks/${id}/${action}`).then((r) => r.data),
    assign: (id: number, userIds: number[]) =>
        axios.put<TaskItem>(`${w(slug)}/tasks/${id}/assignees`, { user_ids: userIds }).then((r) => r.data),
    comment: (id: number, body: string) => axios.post<TaskEventItem>(`${w(slug)}/tasks/${id}/comments`, { body }).then((r) => r.data),
});

export const agentsApi = (slug: string) => {
    const a = (id: number) => `${w(slug)}/agents/${id}`;

    return {
        list: () => axios.get<Agent[]>(`${w(slug)}/agents`).then((r) => r.data),
        options: () => axios.get<AgentOptions>(`${w(slug)}/agents/options`).then((r) => r.data),
        create: (data: Record<string, unknown>) => axios.post<Agent>(`${w(slug)}/agents`, data).then((r) => r.data),
        update: (id: number, data: Record<string, unknown>) => axios.patch<Agent>(a(id), data).then((r) => r.data),
        remove: (id: number) => axios.delete(a(id)),
        status: (id: number, status: AgentStatus) => axios.post<Agent>(`${a(id)}/status`, { status }).then((r) => r.data),
        sources: (id: number, channelIds: number[]) => axios.put<Agent>(`${a(id)}/sources`, { channel_ids: channelIds }).then((r) => r.data),
        permissions: (id: number, mode: AgentMode | null, permissions: Record<string, Policy>) =>
            axios.put<Agent>(`${a(id)}/permissions`, { ...(mode ? { mode } : {}), permissions }).then((r) => r.data),
        avatarPreset: (id: number, preset: string) => axios.post<Agent>(`${a(id)}/avatar`, { preset }).then((r) => r.data),
        avatarUpload: (id: number, file: File) => {
            const form = new FormData();
            form.append('photo', file);
            return axios.post<Agent>(`${a(id)}/avatar`, form).then((r) => r.data);
        },
        chat: (id: number, before?: number) =>
            axios.get<ChatItem[]>(`${a(id)}/chat`, { params: before ? { before } : {} }).then((r) => r.data),
        send: (id: number, message: string) => axios.post<ChatItem>(`${a(id)}/chat`, { message }).then((r) => r.data),
        analyze: (id: number, channelIds: number[], from: string, to: string) =>
            axios.post(`${a(id)}/analyze`, { channel_ids: channelIds, from, to }).then((r) => r.data),
        memories: (id: number) => axios.get<MemoryItem[]>(`${a(id)}/memories`).then((r) => r.data),
        addMemory: (id: number, data: Partial<MemoryItem>) => axios.post<MemoryItem>(`${a(id)}/memories`, data).then((r) => r.data),
        updateMemory: (id: number, memoryId: number, data: Partial<MemoryItem>) =>
            axios.patch<MemoryItem>(`${a(id)}/memories/${memoryId}`, data).then((r) => r.data),
        removeMemory: (id: number, memoryId: number) => axios.delete(`${a(id)}/memories/${memoryId}`),
        runs: (id: number, page = 1) =>
            axios.get<{ data: RunItem[]; meta: { current_page: number; last_page: number; total: number } }>(`${a(id)}/runs`, { params: { page } }).then((r) => r.data),
        run: (id: number, runId: number) => axios.get<RunItem>(`${a(id)}/runs/${runId}`).then((r) => r.data),
        usage: (scope: 'mine' | 'workspace' = 'mine', days = 30) =>
            axios.get<UsageReport>(`${w(slug)}/agents/usage`, { params: { scope, days } }).then((r) => r.data),
    };
};

export const approvalsApi = (slug: string) => ({
    list: (status: ApprovalItem['status'] = 'pending') =>
        axios.get<ApprovalItem[]>(`${w(slug)}/approvals`, { params: { status } }).then((r) => r.data),
    approve: (id: number) => axios.post<ApprovalItem>(`${w(slug)}/approvals/${id}/approve`).then((r) => r.data),
    reject: (id: number, reason?: string) => axios.post<ApprovalItem>(`${w(slug)}/approvals/${id}/reject`, { reason }).then((r) => r.data),
});

/** The first readable message from a failed request (validation errors included). */
export function errorText(error: unknown, fallback = 'Something went wrong. Please try again.'): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        if (error.response?.status === 429) return 'Too many requests. Wait a moment and try again.';
        return first ?? data?.message ?? fallback;
    }
    return fallback;
}

/* ---------- Display helpers ---------- */

export const STATUS_LABELS: Record<TaskStatus, string> = {
    suggested: 'Suggested',
    pending: 'Pending',
    in_progress: 'In progress',
    blocked: 'Blocked',
    paused: 'Paused',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const STATUS_STYLES: Record<TaskStatus, string> = {
    suggested: 'bg-violet-100 text-violet-800',
    pending: 'bg-slate-100 text-slate-700',
    in_progress: 'bg-sky-100 text-sky-800',
    blocked: 'bg-rose-100 text-rose-800',
    paused: 'bg-amber-100 text-amber-800',
    completed: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-slate-100 text-slate-500 line-through',
};

export const PRIORITY_STYLES: Record<TaskPriority, string> = {
    low: 'text-slate-500',
    normal: 'text-slate-600',
    high: 'text-orange-600',
    urgent: 'text-rose-700 font-semibold',
};

export const POLICY_LABELS: Record<Policy, string> = { deny: 'Not allowed', approve: 'Ask me first', allow: 'Allowed' };

export const MODE_LABELS: Record<AgentMode, { title: string; text: string }> = {
    suggest: { title: 'Suggest only', text: 'Finds tasks and proposes them. Nothing happens without you.' },
    auto_tasks: { title: 'Create tasks', text: 'Creates confident tasks for you. Anything involving other people waits for your approval.' },
    controlled: { title: 'Full control', text: 'Acts within the permissions below without asking. Messages after reading others’ words still need approval.' },
};

export const formatDue = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';

export const relative = (iso: string | null) => {
    if (!iso) return '';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} h ago`;
    return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' });
};

/** A local "YYYY-MM-DDTHH:mm" value for <input type="datetime-local">, from an ISO string. */
export const toLocalInput = (iso: string | null) => {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
