import AgentAvatar from '@/Components/AI/AgentAvatar';
import { AccessForm, ActivityView, ChatView, HistoryForm, MemoryView, Notice, ProfileForm, useAgentsApi } from '@/Components/AI/AgentViews';
import { Agent, AgentOptions, AgentStatus, ApprovalItem, approvalsApi, errorText, relative, UsageReport } from '@/Components/AI/api';
import { usePersonalEvents } from '@/Components/AI/live';
import { BackIcon, PlusIcon } from '@/Components/Chat/Icons';
import { ChatUser } from '@/types/chat';
import { useCallback, useEffect, useMemo, useState } from 'react';

interface Props {
    slug: string;
    workspaceId: number;
    isAdmin: boolean;
    me: ChatUser;
    members: ChatUser[];
    onClose: () => void;
}

type Tab = 'chat' | 'profile' | 'access' | 'memory' | 'history' | 'activity';
type View = { kind: 'agent'; id: number } | { kind: 'new' } | { kind: 'approvals' } | { kind: 'usage' } | { kind: 'empty' };

const TABS: { tab: Tab; label: string }[] = [
    { tab: 'chat', label: 'Chat' },
    { tab: 'profile', label: 'Profile' },
    { tab: 'access', label: 'Access & permissions' },
    { tab: 'memory', label: 'Memory' },
    { tab: 'history', label: 'Look back' },
    { tab: 'activity', label: 'Activity' },
];

const STATUS_DOT: Record<AgentStatus, string> = {
    active: 'bg-emerald-500',
    paused: 'bg-amber-400',
    inactive: 'bg-slate-400',
};

/** Your personal AI agents: set them up, talk to them, decide what they may do, and see what they did. */
export default function AgentsPanel({ slug, workspaceId, isAdmin, me, members, onClose }: Props) {
    const api = useAgentsApi(slug);
    const approvals = useMemo(() => approvalsApi(slug), [slug]);
    const [agents, setAgents] = useState<Agent[] | null>(null);
    const [options, setOptions] = useState<AgentOptions | null>(null);
    const [view, setView] = useState<View>({ kind: 'empty' });
    const [tab, setTab] = useState<Tab>('chat');
    const [pending, setPending] = useState(0);
    const [error, setError] = useState<string | null>(null);

    const loadAgents = useCallback(
        () =>
            api.list().then((list) => {
                setAgents(list);
                setView((v) => (v.kind === 'empty' && list.length > 0 ? { kind: 'agent', id: list[0].id } : v));
            }),
        [api],
    );
    const loadPending = useCallback(() => approvals.list('pending').then((l) => setPending(l.length)), [approvals]);

    useEffect(() => {
        Promise.all([loadAgents(), api.options().then(setOptions), loadPending()]).catch((e) => setError(errorText(e, 'Could not load your agents.')));
    }, [api, loadAgents, loadPending]);

    useEffect(() => {
        const key = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', key);
        return () => document.removeEventListener('keydown', key);
    }, [onClose]);

    usePersonalEvents(me.id, {
        'agent.approval': (e: { workspace_id: number }) => e.workspace_id === workspaceId && loadPending(),
        'agent.status': (e: { workspace_id: number }) => e.workspace_id === workspaceId && loadAgents(),
    });

    const agent = view.kind === 'agent' ? agents?.find((a) => a.id === view.id) ?? null : null;
    const replace = (updated: Agent) => setAgents((list) => list?.map((a) => (a.id === updated.id ? updated : a)) ?? [updated]);

    const setStatus = async (status: AgentStatus) => {
        if (!agent) return;
        try {
            replace(await api.status(agent.id, status));
        } catch (e) {
            setError(errorText(e));
        }
    };

    const remove = async () => {
        if (!agent || !confirm(`Delete ${agent.name}? Its memory, chat and settings are removed. Tasks it created stay.`)) return;
        try {
            await api.remove(agent.id);
            setView({ kind: 'empty' });
            await loadAgents();
        } catch (e) {
            setError(errorText(e));
        }
    };

    const listButton = (active: boolean) =>
        `flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm ${active ? 'bg-teal-50 font-semibold text-teal-900' : 'text-slate-700 hover:bg-slate-100'}`;

    return (
        <section aria-label="AI agents" className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-white">
            <header className="flex h-12 shrink-0 items-center justify-between border-b border-slate-200 px-4">
                <h2 className="font-bold text-slate-900">AI agents</h2>
                <button type="button" onClick={onClose} aria-label="Close agents" className="rounded px-2 py-0.5 text-lg leading-none text-slate-600 hover:bg-slate-100">
                    ×
                </button>
            </header>

            <div className="flex min-h-0 flex-1 overflow-hidden">
                <nav
                    aria-label="Your agents"
                    className={`${view.kind === 'empty' ? 'flex' : 'hidden md:flex'} w-full shrink-0 flex-col gap-1 overflow-y-auto border-r border-slate-200 p-2 md:w-60`}
                >
                    {agents?.map((a) => (
                        <button key={a.id} type="button" onClick={() => setView({ kind: 'agent', id: a.id })} className={listButton(view.kind === 'agent' && view.id === a.id)}>
                            <span className="relative">
                                <AgentAvatar agent={a} className="h-7 w-7 rounded-md text-sm" />
                                <span className={`absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white ${STATUS_DOT[a.status]}`} />
                            </span>
                            <span className="min-w-0 flex-1 truncate">{a.name}</span>
                        </button>
                    ))}
                    {agents?.length === 0 && <p className="px-2 py-1 text-sm text-slate-500">You have no agents yet.</p>}
                    <button type="button" onClick={() => setView({ kind: 'new' })} className={`${listButton(view.kind === 'new')} text-teal-800`}>
                        <PlusIcon className="h-4 w-4" /> New agent
                    </button>
                    <hr className="my-2 border-slate-200" />
                    <button type="button" onClick={() => setView({ kind: 'approvals' })} className={listButton(view.kind === 'approvals')}>
                        Waiting for approval
                        {pending > 0 && <span className="ml-auto rounded-full bg-amber-400 px-1.5 text-xs font-bold text-slate-900">{pending}</span>}
                    </button>
                    <button type="button" onClick={() => setView({ kind: 'usage' })} className={listButton(view.kind === 'usage')}>
                        Usage and cost
                    </button>
                </nav>

                <div className={`${view.kind === 'empty' ? 'hidden md:flex' : 'flex'} min-h-0 min-w-0 flex-1 flex-col`}>
                    {view.kind !== 'empty' && (
                        <button type="button" onClick={() => setView({ kind: 'empty' })} className="flex items-center gap-1 px-4 pt-2 text-sm text-slate-600 md:hidden">
                            <BackIcon className="h-4 w-4" /> Agents
                        </button>
                    )}
                    <div className="px-4 pt-2">
                        <Notice kind="error" text={error} />
                    </div>

                    {view.kind === 'empty' && (
                        <div className="m-auto max-w-sm p-6 text-center text-sm text-slate-600">
                            <p className="text-3xl">🤖</p>
                            <p className="mt-2 font-semibold text-slate-900">Your own AI assistant</p>
                            <p className="mt-1">
                                It reads the conversations you choose, turns requests into tasks, and keeps you on top of deadlines. It acts only within the permissions you
                                give it, and everything it does is labelled as AI.
                            </p>
                            <button type="button" onClick={() => setView({ kind: 'new' })} className="mt-4 rounded-md bg-teal-700 px-3 py-1.5 font-semibold text-white">
                                Create an agent
                            </button>
                        </div>
                    )}

                    {view.kind === 'new' && options && (
                        <div className="relative min-h-0 flex-1 overflow-y-auto p-4">
                            <h3 className="mb-3 text-lg font-bold text-slate-900">New agent</h3>
                            <ProfileForm
                                api={api}
                                agent={null}
                                options={options}
                                onCancel={() => setView({ kind: 'empty' })}
                                onSaved={async (created) => {
                                    await loadAgents();
                                    setView({ kind: 'agent', id: created.id });
                                    setTab('chat');
                                }}
                            />
                        </div>
                    )}

                    {view.kind === 'approvals' && <ApprovalsView slug={slug} meId={me.id} workspaceId={workspaceId} onChanged={loadPending} />}
                    {view.kind === 'usage' && <UsageView slug={slug} agents={agents ?? []} members={members} isAdmin={isAdmin} />}

                    {agent && options && (
                        <>
                            <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-3">
                                <AgentAvatar agent={agent} className="h-10 w-10 rounded-lg text-xl" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-semibold text-slate-900">{agent.display_name}</p>
                                    <p className="truncate text-xs text-slate-500">{agent.description ?? 'Personal AI assistant'}</p>
                                </div>
                                <div role="group" aria-label="Status" className="flex overflow-hidden rounded-md ring-1 ring-slate-300">
                                    {(['active', 'paused', 'inactive'] as AgentStatus[]).map((s) => (
                                        <button
                                            key={s}
                                            type="button"
                                            aria-pressed={agent.status === s}
                                            onClick={() => setStatus(s)}
                                            className={`px-2.5 py-1 text-xs font-semibold capitalize ${agent.status === s ? 'bg-slate-800 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'}`}
                                        >
                                            {s === 'inactive' ? 'Off' : s === 'paused' ? 'Paused' : 'Active'}
                                        </button>
                                    ))}
                                </div>
                                <button type="button" onClick={remove} className="text-xs text-rose-700 hover:underline">
                                    Delete
                                </button>
                            </div>
                            <nav aria-label="Agent sections" className="flex gap-1 overflow-x-auto border-b border-slate-200 px-3 py-1.5">
                                {TABS.map(({ tab: t, label }) => (
                                    <button
                                        key={t}
                                        type="button"
                                        onClick={() => setTab(t)}
                                        aria-current={tab === t ? 'page' : undefined}
                                        className={`whitespace-nowrap rounded-md px-2 py-1 text-sm ${tab === t ? 'bg-teal-50 font-semibold text-teal-900' : 'text-slate-600 hover:bg-slate-100'}`}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </nav>
                            <div className={`relative min-h-0 flex-1 ${tab === 'chat' ? '' : 'overflow-y-auto p-4'}`}>
                                {tab === 'chat' && <ChatView key={agent.id} api={api} agent={agent} meId={me.id} />}
                                {tab === 'profile' && <ProfileForm key={agent.id} api={api} agent={agent} options={options} onSaved={replace} />}
                                {tab === 'access' && <AccessForm key={agent.id} api={api} agent={agent} options={options} onSaved={replace} />}
                                {tab === 'memory' && <MemoryView key={agent.id} api={api} agent={agent} />}
                                {tab === 'history' && <HistoryForm key={agent.id} api={api} agent={agent} />}
                                {tab === 'activity' && <ActivityView key={agent.id} api={api} agent={agent} meId={me.id} />}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </section>
    );
}

const ACTION_LABELS: Record<string, string> = {
    update_task: 'Update a task',
    assign_task: 'Assign a task',
    create_reminder: 'Set a reminder',
    send_direct_message: 'Send a direct message',
    send_channel_message: 'Post in a channel',
    reply_in_thread: 'Reply in a thread',
    follow_up: 'Follow up on a task',
};

function ApprovalsView({ slug, meId, workspaceId, onChanged }: { slug: string; meId: number; workspaceId: number; onChanged: () => void }) {
    const api = useMemo(() => approvalsApi(slug), [slug]);
    const [status, setStatus] = useState<ApprovalItem['status']>('pending');
    const [items, setItems] = useState<ApprovalItem[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(() => api.list(status).then(setItems), [api, status]);
    useEffect(() => {
        load();
    }, [load]);
    usePersonalEvents(meId, { 'agent.approval': (e: { workspace_id: number }) => e.workspace_id === workspaceId && load() });

    const decide = async (call: () => Promise<ApprovalItem>) => {
        try {
            const result = await call();
            setError(result.status === 'failed' ? `It could not be done: ${result.error}` : null);
            await load();
            onChanged();
        } catch (e) {
            setError(errorText(e));
        }
    };

    return (
        <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
            <div className="flex items-center justify-between">
                <h3 className="text-lg font-bold text-slate-900">Approvals</h3>
                <select aria-label="Show" value={status} onChange={(e) => setStatus(e.target.value as ApprovalItem['status'])} className="rounded-md border-slate-300 py-1 text-sm">
                    <option value="pending">Waiting</option>
                    <option value="executed">Done</option>
                    <option value="rejected">Declined</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            <p className="text-sm text-slate-600">Your agents asked before doing these. Approving runs the action now, with everything checked again.</p>
            <Notice kind="error" text={error} />
            {items === null && <p className="text-sm text-slate-500">Loading…</p>}
            {items?.length === 0 && <p className="rounded-md bg-slate-50 p-4 text-sm text-slate-500">Nothing here.</p>}
            <ul className="space-y-2">
                {items?.map((item) => (
                    <li key={item.id} className="rounded-lg border border-slate-200 p-3">
                        <p className="text-xs text-slate-500">
                            {item.agent?.name ?? 'A deleted agent'} · {ACTION_LABELS[item.action] ?? item.action} · {relative(item.created_at)}
                        </p>
                        <p className="mt-1 whitespace-pre-wrap text-sm text-slate-900">{item.summary}</p>
                        {item.error && <p className="mt-1 text-xs text-rose-700">{item.error}</p>}
                        {item.status === 'pending' && (
                            <div className="mt-2 flex gap-2">
                                <button type="button" onClick={() => decide(() => api.approve(item.id))} className="rounded-md bg-teal-700 px-3 py-1 text-sm font-semibold text-white">
                                    Approve
                                </button>
                                <button type="button" onClick={() => decide(() => api.reject(item.id))} className="rounded-md px-3 py-1 text-sm text-slate-700 ring-1 ring-slate-300">
                                    Decline
                                </button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function UsageView({ slug, agents, members, isAdmin }: { slug: string; agents: Agent[]; members: ChatUser[]; isAdmin: boolean }) {
    const api = useAgentsApi(slug);
    const [scope, setScope] = useState<'mine' | 'workspace'>('mine');
    const [days, setDays] = useState(30);
    const [report, setReport] = useState<UsageReport | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api.usage(scope, days).then(setReport).catch((e) => setError(errorText(e)));
    }, [api, scope, days]);

    const name = (row: UsageReport['rows'][number]) =>
        row.agent_id !== undefined
            ? agents.find((a) => a.id === row.agent_id)?.name ?? `Agent #${row.agent_id}`
            : members.find((m) => m.id === row.owner_id)?.name ?? `Member #${row.owner_id}`;

    return (
        <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="mr-auto text-lg font-bold text-slate-900">Usage and cost</h3>
                {isAdmin && (
                    <select aria-label="Scope" value={scope} onChange={(e) => setScope(e.target.value as 'mine' | 'workspace')} className="rounded-md border-slate-300 py-1 text-sm">
                        <option value="mine">My agents</option>
                        <option value="workspace">Whole workspace</option>
                    </select>
                )}
                <select aria-label="Period" value={days} onChange={(e) => setDays(Number(e.target.value))} className="rounded-md border-slate-300 py-1 text-sm">
                    <option value={1}>Today</option>
                    <option value={7}>7 days</option>
                    <option value={30}>30 days</option>
                    <option value={90}>90 days</option>
                </select>
            </div>
            <Notice kind="error" text={error} />
            {!report && !error && <p className="text-sm text-slate-500">Loading…</p>}
            {report && (
                <>
                    <p className="text-3xl font-bold text-slate-900">${report.total_cost_usd.toFixed(2)}</p>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-xs text-slate-500">
                                <th className="py-1.5 font-medium">{scope === 'workspace' ? 'Member' : 'Agent'}</th>
                                <th className="py-1.5 text-right font-medium">Runs</th>
                                <th className="py-1.5 text-right font-medium">Tokens</th>
                                <th className="py-1.5 text-right font-medium">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.rows.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="py-3 text-slate-500">
                                        No AI use in this period.
                                    </td>
                                </tr>
                            )}
                            {report.rows.map((row) => (
                                <tr key={`${row.agent_id ?? row.owner_id}`} className="border-b border-slate-100">
                                    <td className="py-1.5 text-slate-800">{name(row)}</td>
                                    <td className="py-1.5 text-right tabular-nums">{row.runs.toLocaleString()}</td>
                                    <td className="py-1.5 text-right tabular-nums">{row.tokens.toLocaleString()}</td>
                                    <td className="py-1.5 text-right tabular-nums">${row.cost_usd.toFixed(2)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </>
            )}
        </div>
    );
}
