import Modal from '@/Components/Modal';
import { WorkspaceInfo } from '@/types/chat';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

interface Analytics {
    days: number;
    members: number;
    active_members: number;
    messages: number;
    bot_messages: number;
    files: number;
    file_bytes: number;
    daily: { day: string; messages: number }[];
    top_channels: { name: string; total: number }[];
    top_members: { name: string; total: number }[];
}

const number = (value: number) => value.toLocaleString();

const size = (bytes: number) =>
    bytes < 1048576 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1048576).toFixed(1)} MB`;

const shortDay = (day: string) =>
    new Date(`${day}T00:00:00`).toLocaleDateString([], { month: 'short', day: 'numeric' });

function Tile({ label, value, note }: { label: string; value: string; note?: string }) {
    return (
        <div className="rounded-lg border border-slate-200 p-3">
            <p className="text-xs text-slate-500">{label}</p>
            <p className="text-2xl font-bold text-slate-900">{value}</p>
            {note && <p className="text-xs text-slate-500">{note}</p>}
        </div>
    );
}

function DailyChart({ daily }: { daily: Analytics['daily'] }) {
    const [hover, setHover] = useState<number | null>(null);
    const peak = Math.max(...daily.map((d) => d.messages), 1);
    const shown = hover === null ? null : daily[hover];

    return (
        <figure>
            <figcaption className="mb-1 flex items-baseline justify-between text-sm font-semibold text-slate-800">
                Messages per day
                <span aria-live="polite" className="text-xs font-normal text-slate-600">
                    {shown ? `${shortDay(shown.day)}: ${number(shown.messages)}` : `Most in a day: ${number(peak)}`}
                </span>
            </figcaption>

            <div className="flex h-40 items-end gap-[2px] border-b border-slate-300" onMouseLeave={() => setHover(null)}>
                {daily.map((point, index) => (
                    <div
                        key={point.day}
                        className="flex h-full min-w-0 flex-1 items-end"
                        onMouseEnter={() => setHover(index)}
                        title={`${shortDay(point.day)}: ${number(point.messages)} messages`}
                    >
                        <div
                            data-chart-bar
                            className={`chart-bar w-full rounded-t ${hover === index ? 'opacity-70' : ''}`}
                            style={{ height: point.messages ? `${Math.max((point.messages / peak) * 100, 2)}%` : 0 }}
                        />
                    </div>
                ))}
            </div>

            <div className="mt-1 flex justify-between text-xs text-slate-500">
                <span>{shortDay(daily[0].day)}</span>
                <span>{shortDay(daily[daily.length - 1].day)}</span>
            </div>
        </figure>
    );
}

function Ranking({ title, rows }: { title: string; rows: { name: string; total: number }[] }) {
    const peak = Math.max(...rows.map((row) => row.total), 1);

    return (
        <div>
            <h3 className="mb-1 text-sm font-semibold text-slate-800">{title}</h3>
            {rows.length === 0 && <p className="text-sm text-slate-500">Nothing in this period.</p>}
            <ul className="space-y-1.5">
                {rows.map((row, index) => (
                    <li key={`${row.name}-${index}`} className="text-sm">
                        <span className="flex justify-between gap-2 text-slate-700">
                            <span className="truncate">{row.name}</span>
                            {number(row.total)}
                        </span>
                        <span className="chart-bar mt-0.5 block h-1 rounded" style={{ width: `${(row.total / peak) * 100}%` }} />
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function AnalyticsModal({ show, workspace, onClose }: { show: boolean; workspace: WorkspaceInfo; onClose: () => void }) {
    const [days, setDays] = useState(30);
    const [data, setData] = useState<Analytics | null>(null);
    const [failed, setFailed] = useState(false);
    const [asTable, setAsTable] = useState(false);

    useEffect(() => {
        if (!show) return;

        // A slower answer for an older period must not replace the newer one.
        let current = true;

        setFailed(false);
        axios
            .get<Analytics>(route('insights.analytics', workspace.slug), { params: { days } })
            .then(({ data: next }) => current && setData(next))
            .catch(() => current && setFailed(true));

        return () => {
            current = false;
        };
    }, [show, days, workspace.slug]);

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <div className="max-h-[85vh] space-y-4 overflow-y-auto p-5">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold text-slate-900">Analytics</h2>
                    <div role="group" aria-label="Period" className="flex gap-1">
                        {[7, 30, 90].map((option) => (
                            <button
                                key={option}
                                type="button"
                                aria-pressed={days === option}
                                onClick={() => setDays(option)}
                                className={`rounded-md px-2 py-1 text-sm ${
                                    days === option ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-100'
                                }`}
                            >
                                {option} days
                            </button>
                        ))}
                    </div>
                </div>

                {failed && <p className="text-sm text-red-600">Could not load analytics. Try again.</p>}
                {!data && !failed && <p className="text-sm text-slate-500">Loading…</p>}

                {data && (
                    <>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <Tile label="Messages" value={number(data.messages)} note={`${number(data.bot_messages)} from apps`} />
                            <Tile label="Active members" value={number(data.active_members)} note={`of ${number(data.members)}`} />
                            <Tile label="Files shared" value={number(data.files)} note={size(data.file_bytes)} />
                            <Tile label="Per day" value={number(Math.round(data.messages / data.days))} note="on average" />
                        </div>

                        {asTable ? (
                            <div className="max-h-48 overflow-y-auto">
                                <table className="w-full text-left text-sm text-slate-700">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-xs text-slate-500">
                                            <th className="py-1 font-medium">Day</th>
                                            <th className="py-1 text-right font-medium">Messages</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.daily.map((point) => (
                                            <tr key={point.day} className="border-b border-slate-100">
                                                <td className="py-1">{shortDay(point.day)}</td>
                                                <td className="py-1 text-right">{number(point.messages)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <DailyChart daily={data.daily} />
                        )}

                        <button type="button" onClick={() => setAsTable(!asTable)} className="text-xs text-sky-700 hover:underline">
                            {asTable ? 'Show chart' : 'Show as table'}
                        </button>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Ranking title="Busiest public channels" rows={data.top_channels} />
                            <Ranking title="Most active in public channels" rows={data.top_members} />
                        </div>

                        <p className="text-xs text-slate-500">
                            Totals include every conversation. Rankings use public channels only, so private channels and
                            direct messages are never singled out. No message text is shown.
                        </p>
                    </>
                )}

                <div className="flex justify-end">
                    <button type="button" onClick={onClose} className="rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100">
                        Done
                    </button>
                </div>
            </div>
        </Modal>
    );
}

interface AuditEntry {
    id: number;
    actor: string;
    action: string;
    detail: string;
    ip: string | null;
    created_at: string;
}

const ACTIONS: Record<string, string> = {
    'member.joined': 'Member joined',
    'member.removed': 'Member removed',
    'member.role_changed': 'Role changed',
    'channel.created': 'Channel created',
    'channel.renamed': 'Channel renamed',
    'channel.archived': 'Channel archived',
    'channel.restored': 'Channel restored',
    'channel.deleted': 'Channel deleted',
    'message.moderated': 'Message removed by admin',
    'webhook.created': 'Webhook created',
    'webhook.deleted': 'Webhook deleted',
    'workflow.created': 'Workflow created',
    'workflow.deleted': 'Workflow deleted',
    'workspace.created': 'Workspace created',
    'workspace.renamed': 'Workspace renamed',
    'workspace.exported': 'Workspace exported',
    'invite.sent': 'Email invites sent',
    'invite.cancelled': 'Invite cancelled',
};

export function AuditModal({ show, workspace, onClose }: { show: boolean; workspace: WorkspaceInfo; onClose: () => void }) {
    const [entries, setEntries] = useState<AuditEntry[] | null>(null);
    const [hasMore, setHasMore] = useState(false);
    const [action, setAction] = useState('');
    const [failed, setFailed] = useState(false);
    const latest = useRef(0);

    const load = async (before?: number) => {
        const request = ++latest.current;

        setFailed(false);
        try {
            const { data } = await axios.get<{ entries: AuditEntry[]; hasMore: boolean }>(
                route('insights.audit', workspace.slug),
                { params: { action: action || undefined, before } },
            );
            if (request !== latest.current) return;

            setEntries((current) => (before ? [...(current ?? []), ...data.entries] : data.entries));
            setHasMore(data.hasMore);
        } catch {
            if (request === latest.current) setFailed(true);
        }
    };

    useEffect(() => {
        if (show) load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, action, workspace.slug]);

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <div className="p-5">
                <div className="mb-3 flex items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold text-slate-900">Audit log</h2>
                    <select
                        aria-label="Filter by action"
                        value={action}
                        onChange={(e) => setAction(e.target.value)}
                        className="rounded-md border-slate-300 py-1 text-sm"
                    >
                        <option value="">All actions</option>
                        {Object.entries(ACTIONS).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>

                {failed && <p className="text-sm text-red-600">Could not load the audit log. Try again.</p>}
                {!entries && !failed && <p className="text-sm text-slate-500">Loading…</p>}
                {entries?.length === 0 && <p className="text-sm text-slate-500">Nothing recorded yet.</p>}

                <ul className="max-h-96 divide-y divide-slate-200 overflow-y-auto">
                    {entries?.map((entry) => (
                        <li key={entry.id} className="py-2 text-sm">
                            <span className="flex justify-between gap-3">
                                <span className="font-semibold text-slate-800">{ACTIONS[entry.action] ?? entry.action}</span>
                                <time dateTime={entry.created_at} className="shrink-0 text-xs text-slate-500">
                                    {new Date(entry.created_at).toLocaleString()}
                                </time>
                            </span>
                            <span className="block truncate text-slate-600">
                                {entry.actor}
                                {entry.detail && ` · ${entry.detail}`}
                                {entry.ip && ` · ${entry.ip}`}
                            </span>
                        </li>
                    ))}
                </ul>

                <div className="mt-3 flex justify-between">
                    {hasMore ? (
                        <button
                            type="button"
                            onClick={() => load(entries?.[entries.length - 1]?.id)}
                            className="rounded-md px-3 py-2 text-sm text-sky-700 hover:bg-slate-100"
                        >
                            Load older
                        </button>
                    ) : (
                        <span />
                    )}
                    <button type="button" onClick={onClose} className="rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100">
                        Done
                    </button>
                </div>
            </div>
        </Modal>
    );
}
