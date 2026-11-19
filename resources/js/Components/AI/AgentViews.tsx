import AgentAvatar, { presetEmoji } from '@/Components/AI/AgentAvatar';
import {
    Agent,
    AgentMode,
    AgentOptions,
    agentsApi,
    AgentSettings,
    ChatItem,
    errorText,
    MemoryItem,
    MODE_LABELS,
    Policy,
    POLICY_LABELS,
    relative,
    RunItem,
} from '@/Components/AI/api';
import { usePersonalEvents } from '@/Components/AI/live';
import { SendIcon } from '@/Components/Chat/Icons';
import { FormEvent, ReactNode, useEffect, useMemo, useRef, useState } from 'react';

type Api = ReturnType<typeof agentsApi>;

const input = 'mt-1 w-full rounded-md border-slate-300 text-sm';
const primary = 'rounded-md bg-teal-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50';
const secondary = 'rounded-md px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50';

export function Field({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
    return (
        <label className="block text-sm">
            <span className="font-medium text-slate-800">{label}</span>
            {hint && <span className="block text-xs text-slate-500">{hint}</span>}
            {children}
        </label>
    );
}

export function Notice({ kind, text }: { kind: 'error' | 'ok'; text: string | null }) {
    if (!text) return null;
    return (
        <p role={kind === 'error' ? 'alert' : 'status'} className={`rounded-md p-2 text-sm ${kind === 'error' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-800'}`}>
            {text}
        </p>
    );
}

/* ---------------- Profile: who the agent is ---------------- */

export function ProfileForm({
    api,
    agent,
    options,
    onSaved,
    onCancel,
}: {
    api: Api;
    agent: Agent | null;
    options: AgentOptions;
    onSaved: (agent: Agent) => void;
    onCancel?: () => void;
}) {
    const [form, setForm] = useState({
        name: agent?.name ?? '',
        description: agent?.description ?? '',
        personality: agent?.personality ?? '',
        instructions: agent?.instructions ?? '',
        responsibilities: agent?.responsibilities ?? '',
        avatar_preset: agent?.avatar_preset ?? 'robot',
    });
    const [mode, setMode] = useState<AgentMode>('suggest');
    const [sources, setSources] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saved, setSaved] = useState<string | null>(null);
    const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [key]: e.target.value });

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setSaved(null);
        try {
            const data = { ...form, description: form.description || null, personality: form.personality || null };
            const result = agent ? await api.update(agent.id, data) : await api.create({ ...data, mode, source_channel_ids: sources });
            setError(null);
            if (agent) setSaved('Saved.');
            onSaved(result);
        } catch (err) {
            setError(errorText(err));
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="max-w-2xl space-y-4">
            <div className="flex items-start gap-4">
                <AgentAvatar agent={{ name: form.name || 'Agent', avatar_url: agent?.avatar_url, avatar_preset: form.avatar_preset }} className="h-14 w-14 rounded-xl text-2xl" />
                <div className="min-w-0 flex-1">
                    <Field label="Name" hint="Shown everywhere as “Name (AI Assistant)”.">
                        <input required minLength={2} maxLength={80} value={form.name} onChange={set('name')} className={input} />
                    </Field>
                </div>
            </div>

            <fieldset>
                <legend className="text-sm font-medium text-slate-800">Avatar</legend>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                    {options.avatar_presets.map((preset) => (
                        <button
                            key={preset}
                            type="button"
                            aria-label={preset}
                            aria-pressed={form.avatar_preset === preset && !agent?.avatar_url}
                            onClick={() => setForm({ ...form, avatar_preset: preset })}
                            className={`flex h-9 w-9 items-center justify-center rounded-lg text-lg ${form.avatar_preset === preset ? 'bg-violet-100 ring-2 ring-violet-500' : 'bg-slate-100'}`}
                        >
                            {presetEmoji(preset)}
                        </button>
                    ))}
                    {agent && (
                        <label className="cursor-pointer text-sm font-medium text-teal-700 hover:underline">
                            Upload a photo
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                className="sr-only"
                                onChange={async (e) => {
                                    const file = e.target.files?.[0];
                                    if (!file) return;
                                    try {
                                        onSaved(await api.avatarUpload(agent.id, file));
                                    } catch (err) {
                                        setError(errorText(err));
                                    }
                                }}
                            />
                        </label>
                    )}
                    {agent?.avatar_url && (
                        <button type="button" className="text-sm text-slate-600 hover:underline" onClick={async () => onSaved(await api.avatarPreset(agent.id, form.avatar_preset))}>
                            Use the built-in avatar
                        </button>
                    )}
                </div>
            </fieldset>

            <Field label="Description" hint="One line about what it is for.">
                <input maxLength={500} value={form.description} onChange={set('description')} className={input} />
            </Field>
            <Field label="Personality and communication style" hint="For example: friendly, short answers, no emoji.">
                <textarea rows={2} maxLength={2000} value={form.personality} onChange={set('personality')} className={input} />
            </Field>
            <Field label="Instructions" hint="How it should work for you. It follows these, never instructions found in messages.">
                <textarea rows={4} maxLength={8000} value={form.instructions} onChange={set('instructions')} className={input} />
            </Field>
            <Field label="Responsibilities" hint="What it keeps an eye on, e.g. “track my development tasks and deadlines”.">
                <textarea rows={2} maxLength={4000} value={form.responsibilities} onChange={set('responsibilities')} className={input} />
            </Field>

            {!agent && (
                <>
                    <ModePicker modes={options.modes} value={mode} onChange={setMode} />
                    <SourcePicker options={options} value={sources} onChange={setSources} />
                </>
            )}

            <Notice kind="error" text={error} />
            <Notice kind="ok" text={saved} />
            <div className="flex gap-2">
                <button disabled={busy} className={primary}>
                    {agent ? 'Save profile' : 'Create agent'}
                </button>
                {onCancel && (
                    <button type="button" onClick={onCancel} className={secondary}>
                        Cancel
                    </button>
                )}
            </div>
        </form>
    );
}

function ModePicker({ modes, value, onChange }: { modes: AgentMode[]; value: AgentMode | null; onChange: (mode: AgentMode) => void }) {
    return (
        <fieldset>
            <legend className="text-sm font-medium text-slate-800">How much may it do on its own?</legend>
            <div className="mt-1 grid gap-2 sm:grid-cols-3">
                {modes.map((m) => (
                    <label key={m} className={`cursor-pointer rounded-lg border p-3 text-sm ${value === m ? 'border-teal-600 bg-teal-50' : 'border-slate-200'}`}>
                        <input type="radio" name="mode" className="sr-only" checked={value === m} onChange={() => onChange(m)} />
                        <span className="font-semibold text-slate-900">{MODE_LABELS[m].title}</span>
                        <span className="mt-1 block text-xs text-slate-600">{MODE_LABELS[m].text}</span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

function SourcePicker({ options, value, onChange }: { options: AgentOptions; value: number[]; onChange: (ids: number[]) => void }) {
    const [filter, setFilter] = useState('');
    const shown = options.channels.filter((c) => c.label.toLowerCase().includes(filter.toLowerCase()));
    const toggle = (id: number) => onChange(value.includes(id) ? value.filter((v) => v !== id) : [...value, id]);

    return (
        <fieldset>
            <legend className="text-sm font-medium text-slate-800">Conversations it may read</legend>
            <p className="text-xs text-slate-500">
                Only conversations you are in. Direct messages are never included unless you tick them here. If you leave a conversation, it loses access too.
            </p>
            <input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Filter" aria-label="Filter conversations" className={`${input} py-1`} />
            <ul className="mt-2 max-h-56 overflow-y-auto rounded-md border border-slate-200">
                {shown.map((c) => (
                    <li key={c.id} className="border-b border-slate-100 last:border-0">
                        <label className="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm hover:bg-slate-50">
                            <input type="checkbox" checked={value.includes(c.id)} onChange={() => toggle(c.id)} className="rounded border-slate-300 text-teal-700" />
                            <span className="text-slate-800">{c.label}</span>
                            <span className="ml-auto text-xs text-slate-400">{c.type === 'dm' ? 'direct message' : c.type}</span>
                        </label>
                    </li>
                ))}
                {shown.length === 0 && <li className="px-3 py-2 text-sm text-slate-500">No conversations.</li>}
            </ul>
            <p className="mt-1 text-xs text-slate-500">{value.length} selected</p>
        </fieldset>
    );
}

/* ---------------- Access: permissions, sources and behaviour ---------------- */

export function AccessForm({ api, agent, options, onSaved }: { api: Api; agent: Agent; options: AgentOptions; onSaved: (agent: Agent) => void }) {
    const [mode, setMode] = useState<AgentMode>(agent.mode);
    const [policies, setPolicies] = useState<Record<string, Policy>>(agent.permissions);
    const [sources, setSources] = useState<number[]>(agent.sources.map((s) => s.channel_id));
    const [settings, setSettings] = useState<AgentSettings>(agent.settings);
    const [error, setError] = useState<string | null>(null);
    const [saved, setSaved] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const pickMode = (m: AgentMode) => {
        setMode(m);
        setPolicies({ ...options.presets[m] });
    };
    const patch = <G extends keyof AgentSettings>(group: G, values: Partial<AgentSettings[G]>) =>
        setSettings({ ...settings, [group]: { ...settings[group], ...values } });

    const save = async () => {
        setBusy(true);
        setSaved(null);
        try {
            await api.permissions(agent.id, mode, policies);
            await api.sources(agent.id, sources);
            onSaved(await api.update(agent.id, { settings }));
            setError(null);
            setSaved('Saved. Changes apply immediately, also to work in progress.');
        } catch (err) {
            setError(errorText(err));
        } finally {
            setBusy(false);
        }
    };

    const toggle = (checked: boolean, onChange: (v: boolean) => void, label: string, hint?: string) => (
        <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="mt-0.5 rounded border-slate-300 text-teal-700" />
            <span>
                <span className="text-slate-800">{label}</span>
                {hint && <span className="block text-xs text-slate-500">{hint}</span>}
            </span>
        </label>
    );

    return (
        <div className="max-w-2xl space-y-6">
            <ModePicker modes={options.modes} value={mode} onChange={pickMode} />

            <section>
                <h3 className="text-sm font-medium text-slate-800">Permissions</h3>
                <p className="text-xs text-slate-500">Checked by the server for every action. “Ask me first” sends it to your approval queue.</p>
                <table className="mt-2 w-full text-sm">
                    <tbody>
                        {Object.entries(options.actions).map(([action, label]) => (
                            <tr key={action} className="border-b border-slate-100">
                                <td className="py-1.5 pr-2 text-slate-800">{label}</td>
                                <td className="py-1.5">
                                    <select
                                        aria-label={label}
                                        value={policies[action] ?? 'deny'}
                                        onChange={(e) => setPolicies({ ...policies, [action]: e.target.value as Policy })}
                                        className="w-full rounded-md border-slate-300 py-1 text-sm"
                                    >
                                        {options.policies.map((p) => (
                                            <option key={p} value={p}>
                                                {POLICY_LABELS[p]}
                                            </option>
                                        ))}
                                    </select>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>

            <SourcePicker options={options} value={sources} onChange={setSources} />

            <section className="space-y-2">
                <h3 className="text-sm font-medium text-slate-800">Finding tasks</h3>
                {toggle(settings.task_detection.enabled, (v) => patch('task_detection', { enabled: v }), 'Look for tasks in the conversations above')}
                {toggle(settings.task_detection.realtime, (v) => patch('task_detection', { realtime: v }), 'Read new messages as they arrive', 'Otherwise only when you ask it to look back.')}
                {toggle(settings.task_detection.only_mine, (v) => patch('task_detection', { only_mine: v }), 'Only tasks for me')}
                <Field label={`Confidence needed for a real task: ${Math.round(settings.task_detection.min_confidence * 100)}%`} hint="Below this, a task is only suggested.">
                    <input
                        type="range"
                        min={0.3}
                        max={0.95}
                        step={0.05}
                        value={settings.task_detection.min_confidence}
                        onChange={(e) => patch('task_detection', { min_confidence: Number(e.target.value) })}
                        className="mt-1 w-full accent-teal-700"
                    />
                </Field>
            </section>

            <section className="space-y-2">
                <h3 className="text-sm font-medium text-slate-800">Keeping you informed</h3>
                {toggle(settings.notifications.overdue_alerts, (v) => patch('notifications', { overdue_alerts: v }), 'Tell me when my tasks are overdue')}
                {toggle(settings.notifications.notify_assignees, (v) => patch('notifications', { notify_assignees: v }), 'Notify people when tasks are assigned to them')}
                <div className="flex items-center gap-3">
                    {toggle(settings.daily_summary.enabled, (v) => patch('daily_summary', { enabled: v }), 'Daily summary at')}
                    <input
                        type="time"
                        aria-label="Daily summary time"
                        value={settings.daily_summary.time}
                        onChange={(e) => patch('daily_summary', { time: e.target.value })}
                        className="rounded-md border-slate-300 py-1 text-sm"
                    />
                </div>
            </section>

            <Notice kind="error" text={error} />
            <Notice kind="ok" text={saved} />
            <button type="button" disabled={busy} onClick={save} className={primary}>
                Save access settings
            </button>
        </div>
    );
}

/* ---------------- Chat with your agent ---------------- */

/** Plain text with **bold** shown as bold; nothing else is interpreted, so no HTML can get in. */
function ChatText({ text }: { text: string }) {
    return (
        <>
            {text.split(/(\*\*[^*\n]+\*\*)/g).map((part, i) =>
                part.startsWith('**') && part.endsWith('**') && part.length > 4 ? <strong key={i}>{part.slice(2, -2)}</strong> : part,
            )}
        </>
    );
}

export function ChatView({ api, agent, meId }: { api: Api; agent: Agent; meId: number }) {
    const [items, setItems] = useState<ChatItem[]>([]);
    const [text, setText] = useState('');
    const [waiting, setWaiting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const bottom = useRef<HTMLDivElement>(null);

    useEffect(() => {
        api.chat(agent.id).then(setItems).catch((e) => setError(errorText(e)));
    }, [api, agent.id]);

    // A block body on purpose: newer browsers return a Promise from scrollIntoView, which React must not get as a cleanup.
    useEffect(() => {
        bottom.current?.scrollIntoView({ block: 'end' });
    }, [items, waiting]);

    usePersonalEvents(meId, {
        'agent.chat': (message: ChatItem) => {
            if (message.agent_id !== agent.id) return;
            setItems((current) => (current.some((m) => m.id === message.id) ? current : [...current, message]));
            if (message.role === 'agent') setWaiting(false);
        },
    });

    const send = async (e: FormEvent) => {
        e.preventDefault();
        const body = text.trim();
        if (!body) return;
        setError(null);
        setWaiting(true);
        try {
            const sent = await api.send(agent.id, body);
            setText('');
            setItems((current) => (current.some((m) => m.id === sent.id) ? current : [...current, sent]));
            // The reply arrives over the WebSocket; if it is not running, fetch once after a while.
            setTimeout(() => api.chat(agent.id).then((all) => {
                setItems(all);
                if (all.at(-1)?.role === 'agent') setWaiting(false);
            }), 20000);
        } catch (err) {
            setWaiting(false);
            setError(errorText(err));
        }
    };

    const disabled = agent.status === 'inactive';

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4" aria-live="polite">
                {items.length === 0 && (
                    <div className="mx-auto max-w-md rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                        <p className="font-medium text-slate-800">Ask {agent.name} anything about your work.</p>
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            <li>Show me my overdue tasks.</li>
                            <li>What did people ask me to do in #general today?</li>
                            <li>Remind me tomorrow at 9 to send the report.</li>
                        </ul>
                    </div>
                )}
                {items.map((m) => (
                    <div key={m.id} className={`flex gap-2 ${m.role === 'owner' ? 'justify-end' : ''}`}>
                        {m.role === 'agent' && <AgentAvatar agent={agent} className="h-7 w-7 rounded-lg text-sm" />}
                        <div
                            className={`max-w-[80%] whitespace-pre-wrap rounded-2xl px-3 py-2 text-sm ${
                                m.role === 'owner' ? 'rounded-br-sm bg-teal-700 text-white' : 'rounded-bl-sm bg-slate-100 text-slate-800'
                            }`}
                        >
                            <ChatText text={m.content} />
                            <span className={`mt-1 block text-[10px] ${m.role === 'owner' ? 'text-teal-100' : 'text-slate-400'}`}>{relative(m.created_at)}</span>
                        </div>
                    </div>
                ))}
                {waiting && (
                    <div className="flex items-center gap-2 text-sm text-slate-500">
                        <AgentAvatar agent={agent} className="h-7 w-7 rounded-lg text-sm" /> {agent.name} is working on it…
                    </div>
                )}
                <div ref={bottom} />
            </div>
            <Notice kind="error" text={error} />
            {agent.status === 'paused' && <p className="px-4 pb-1 text-xs text-amber-700">Paused: it can answer questions but will not take any action.</p>}
            <form onSubmit={send} className="flex gap-2 border-t border-slate-200 p-3">
                <textarea
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            (e.currentTarget.form as HTMLFormElement).requestSubmit();
                        }
                    }}
                    disabled={disabled}
                    rows={1}
                    maxLength={4000}
                    placeholder={disabled ? 'Reactivate this agent to chat.' : `Message ${agent.name}`}
                    aria-label={`Message ${agent.name}`}
                    className="min-h-[38px] flex-1 resize-none rounded-md border-slate-300 text-sm"
                />
                <button disabled={disabled || !text.trim()} aria-label="Send" className="rounded-md bg-teal-700 px-3 text-white disabled:opacity-40">
                    <SendIcon className="h-4 w-4" />
                </button>
            </form>
        </div>
    );
}

/* ---------------- Look back over past messages ---------------- */

export function HistoryForm({ api, agent }: { api: Api; agent: Agent }) {
    const today = new Date().toISOString().slice(0, 10);
    const weekAgo = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
    const [from, setFrom] = useState(weekAgo);
    const [to, setTo] = useState(today);
    const [channels, setChannels] = useState<number[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [done, setDone] = useState<string | null>(null);

    return (
        <form
            onSubmit={async (e) => {
                e.preventDefault();
                setDone(null);
                try {
                    await api.analyze(agent.id, channels, new Date(from).toISOString(), new Date(`${to}T23:59:59`).toISOString());
                    setError(null);
                    setDone('Started. New tasks and suggestions will appear in your task panel.');
                } catch (err) {
                    setError(errorText(err));
                }
            }}
            className="max-w-2xl space-y-3"
        >
            <p className="text-sm text-slate-600">Ask {agent.name} to look for tasks in past messages (up to 30 days), in conversations it may read.</p>
            <div className="flex gap-3">
                <Field label="From">
                    <input type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} className={input} />
                </Field>
                <Field label="To">
                    <input type="date" value={to} max={today} onChange={(e) => setTo(e.target.value)} className={input} />
                </Field>
            </div>
            <fieldset className="space-y-1">
                <legend className="text-sm font-medium text-slate-800">Conversations</legend>
                {agent.sources.length === 0 && <p className="text-sm text-slate-500">Give it access to some conversations first.</p>}
                {agent.sources.map((s) => (
                    <label key={s.channel_id} className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={channels.includes(s.channel_id)}
                            onChange={() => setChannels(channels.includes(s.channel_id) ? channels.filter((c) => c !== s.channel_id) : [...channels, s.channel_id])}
                            className="rounded border-slate-300 text-teal-700"
                        />
                        {s.label}
                    </label>
                ))}
            </fieldset>
            <Notice kind="error" text={error} />
            <Notice kind="ok" text={done} />
            <button disabled={channels.length === 0 || agent.status !== 'active'} className={primary}>
                Look back
            </button>
        </form>
    );
}

/* ---------------- Memory ---------------- */

const KINDS: MemoryItem['kind'][] = ['preference', 'instruction', 'context', 'fact'];

export function MemoryView({ api, agent }: { api: Api; agent: Agent }) {
    const [items, setItems] = useState<MemoryItem[] | null>(null);
    const [kind, setKind] = useState<MemoryItem['kind']>('preference');
    const [content, setContent] = useState('');
    const [editing, setEditing] = useState<{ id: number; content: string } | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = () => api.memories(agent.id).then(setItems).catch((e) => setError(errorText(e)));
    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [agent.id]);

    const run = async (call: () => Promise<unknown>) => {
        try {
            await call();
            setError(null);
            load();
        } catch (e) {
            setError(errorText(e));
        }
    };

    return (
        <div className="max-w-2xl space-y-4">
            <p className="text-sm text-slate-600">
                What {agent.name} remembers between conversations. Notes it saved itself are marked; editing one makes it yours.
            </p>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    run(() => api.addMemory(agent.id, { kind, content })).then(() => setContent(''));
                }}
                className="flex flex-wrap gap-2"
            >
                <select aria-label="Kind" value={kind} onChange={(e) => setKind(e.target.value as MemoryItem['kind'])} className="rounded-md border-slate-300 text-sm">
                    {KINDS.map((k) => (
                        <option key={k}>{k}</option>
                    ))}
                </select>
                <input
                    required
                    minLength={2}
                    maxLength={2000}
                    value={content}
                    onChange={(e) => setContent(e.target.value)}
                    placeholder="e.g. I prefer summaries as bullet points"
                    aria-label="Memory"
                    className="min-w-0 flex-1 rounded-md border-slate-300 text-sm"
                />
                <button className={primary}>Add</button>
            </form>
            <Notice kind="error" text={error} />
            <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
                {items === null && <li className="p-3 text-sm text-slate-500">Loading…</li>}
                {items?.length === 0 && <li className="p-3 text-sm text-slate-500">Nothing remembered yet.</li>}
                {items?.map((m) => (
                    <li key={m.id} className="flex items-start gap-2 p-3 text-sm">
                        <span className="rounded bg-slate-100 px-1.5 text-[11px] text-slate-600">{m.kind}</span>
                        {m.origin === 'agent' && <span className="rounded bg-violet-50 px-1.5 text-[11px] text-violet-700">saved by {agent.name}</span>}
                        {editing?.id === m.id ? (
                            <form
                                className="flex flex-1 gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    run(() => api.updateMemory(agent.id, m.id, { content: editing.content })).then(() => setEditing(null));
                                }}
                            >
                                <input value={editing.content} onChange={(e) => setEditing({ id: m.id, content: e.target.value })} aria-label="Edit memory" className="flex-1 rounded-md border-slate-300 py-0.5 text-sm" />
                                <button className="text-teal-700">Save</button>
                            </form>
                        ) : (
                            <span className="flex-1 text-slate-800">{m.content}</span>
                        )}
                        <button type="button" onClick={() => run(() => api.updateMemory(agent.id, m.id, { pinned: !m.pinned }))} className="text-xs text-slate-500 hover:text-slate-800">
                            {m.pinned ? 'Unpin' : 'Pin'}
                        </button>
                        <button type="button" onClick={() => setEditing({ id: m.id, content: m.content })} className="text-xs text-slate-500 hover:text-slate-800">
                            Edit
                        </button>
                        <button type="button" onClick={() => run(() => api.removeMemory(agent.id, m.id))} className="text-xs text-rose-600 hover:text-rose-800">
                            Delete
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/* ---------------- Activity and cost ---------------- */

const RUN_STYLES: Record<RunItem['status'], string> = {
    running: 'bg-sky-100 text-sky-800',
    succeeded: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-rose-100 text-rose-800',
    skipped: 'bg-amber-100 text-amber-800',
    cancelled: 'bg-slate-100 text-slate-600',
};

const TRIGGERS: Record<RunItem['trigger'], string> = {
    message: 'New messages',
    history: 'Look back',
    chat: 'Chat',
    schedule: 'Scheduled',
};

export function ActivityView({ api, agent, meId }: { api: Api; agent: Agent; meId: number }) {
    const [runs, setRuns] = useState<RunItem[] | null>(null);
    const [page, setPage] = useState({ current: 1, last: 1 });
    const [open, setOpen] = useState<RunItem | null>(null);
    const [usage, setUsage] = useState<{ runs: number; tokens: number; cost: number } | null>(null);

    const load = (p = 1) =>
        api.runs(agent.id, p).then((r) => {
            setRuns(r.data);
            setPage({ current: r.meta.current_page, last: r.meta.last_page });
        });

    useEffect(() => {
        load();
        api.usage('mine', 30).then((u) => {
            const row = u.rows.find((r) => r.agent_id === agent.id);
            setUsage({ runs: row?.runs ?? 0, tokens: row?.tokens ?? 0, cost: row?.cost_usd ?? 0 });
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [agent.id]);

    usePersonalEvents(meId, {
        'agent.run': (event: { agent_id: number }) => event.agent_id === agent.id && page.current === 1 && load(1),
    });

    return (
        <div className="max-w-3xl space-y-4">
            {usage && (
                <dl className="grid grid-cols-3 gap-2 text-center">
                    {[
                        ['Runs, 30 days', usage.runs.toLocaleString()],
                        ['Tokens', usage.tokens.toLocaleString()],
                        ['Cost', `$${usage.cost.toFixed(2)}`],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg bg-slate-50 p-3">
                            <dt className="text-xs text-slate-500">{label}</dt>
                            <dd className="text-lg font-semibold text-slate-900">{value}</dd>
                        </div>
                    ))}
                </dl>
            )}

            <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
                {runs === null && <li className="p-3 text-sm text-slate-500">Loading…</li>}
                {runs?.length === 0 && <li className="p-3 text-sm text-slate-500">No activity yet.</li>}
                {runs?.map((run) => (
                    <li key={run.id}>
                        <button
                            type="button"
                            onClick={async () => setOpen(open?.id === run.id ? null : await api.run(agent.id, run.id))}
                            aria-expanded={open?.id === run.id}
                            className="flex w-full items-center gap-2 p-3 text-left text-sm hover:bg-slate-50"
                        >
                            <span className={`rounded px-1.5 text-[11px] font-semibold ${RUN_STYLES[run.status]}`}>{run.status}</span>
                            <span className="text-slate-700">{TRIGGERS[run.trigger]}</span>
                            <span className="min-w-0 flex-1 truncate text-slate-500">{run.error ?? run.summary}</span>
                            <span className="shrink-0 text-xs text-slate-400">{relative(run.started_at)}</span>
                        </button>
                        {open?.id === run.id && (
                            <div className="space-y-1 bg-slate-50 px-3 pb-3 text-xs">
                                <p className="text-slate-500">
                                    {run.steps} step(s) · {(run.tokens.input + run.tokens.output + run.tokens.cache_read + run.tokens.cache_write).toLocaleString()} tokens · $
                                    {run.cost_usd.toFixed(4)} {run.model && run.model !== 'none' ? `· ${run.model}` : ''}
                                </p>
                                <ol className="space-y-1">
                                    {open.step_log?.map((step) => (
                                        <li key={step.sequence} className="rounded bg-white p-2 ring-1 ring-slate-200">
                                            <span className="font-semibold text-slate-700">
                                                {step.sequence}. {step.type}
                                                {step.name ? `: ${step.name}` : ''}
                                            </span>{' '}
                                            <span className={step.status === 'ok' ? 'text-emerald-700' : 'text-amber-700'}>{step.status.replace('_', ' ')}</span>
                                            {step.output !== null && (
                                                <pre className="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-words text-[11px] text-slate-600">
                                                    {JSON.stringify(step.output, null, 1)}
                                                </pre>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
            {page.last > 1 && (
                <div className="flex items-center justify-between text-sm">
                    <button type="button" disabled={page.current <= 1} onClick={() => load(page.current - 1)} className={secondary}>
                        Newer
                    </button>
                    <span className="text-slate-500">
                        Page {page.current} of {page.last}
                    </span>
                    <button type="button" disabled={page.current >= page.last} onClick={() => load(page.current + 1)} className={secondary}>
                        Older
                    </button>
                </div>
            )}
        </div>
    );
}

export const useAgentsApi = (slug: string) => useMemo(() => agentsApi(slug), [slug]);
