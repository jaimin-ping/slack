import { FileIcon } from '@/Components/Chat/Icons';
import { ensurePush, notificationState, NotificationState, requestNotifications } from '@/notify';
import { ChatMessage, InfoPanelName, WorkspaceInfo } from '@/types/chat';
import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { ReactNode, useEffect, useState } from 'react';

interface ActivityItem {
    id: number;
    type: 'mention' | 'reply';
    read: boolean;
    message: ChatMessage;
    channel_label: string;
}

type ThreadItem = ChatMessage & { channel_label: string };

interface DraftItem {
    id: number;
    channel_id: number;
    channel_label: string;
    body: string;
    updated_at: string;
}

interface BrowseItem {
    id: number;
    name: string;
    type: 'public' | 'private';
    topic: string | null;
    member_count: number;
    joined: boolean;
    archived: boolean;
    can_manage: boolean;
}

interface ScheduledItem {
    id: number;
    channel_label: string;
    body: string;
    send_at: string;
    failed: boolean;
}

interface ReminderItem {
    id: number;
    text: string;
    remind_at: string;
}

interface FileItem {
    id: number;
    name: string;
    size: number;
    is_image: boolean;
    url: string;
    channel_id: number;
    channel_label: string;
    sender: string;
    created_at: string;
}

const TITLES: Record<InfoPanelName, string> = {
    activity: 'Activity',
    threads: 'Threads',
    drafts: 'Drafts',
    files: 'Files',
    channels: 'Channels',
    saved: 'Saved items',
    scheduled: 'Scheduled',
};

const EMPTY: Record<InfoPanelName, string> = {
    activity: 'Mentions and thread replies will show up here.',
    threads: 'Threads you start or reply to will show up here.',
    drafts: 'Unsent messages are saved here automatically.',
    files: 'Files shared in your channels will show up here.',
    channels: 'No channels yet.',
    saved: 'Messages you save for later will show up here.',
    scheduled: 'Messages you schedule will show up here.',
};

const when = (iso: string) =>
    new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });

function Row({ href, unread, children }: { href: string; unread?: boolean; children: ReactNode }) {
    return (
        <Link href={href} className={`block border-b border-slate-200 px-5 py-3 hover:bg-slate-50 ${unread ? 'bg-sky-50' : ''}`}>
            {children}
        </Link>
    );
}

export function NotificationBanner({ showBlocked = false }: { showBlocked?: boolean }) {
    const [state, setState] = useState<NotificationState>(notificationState);
    const publicKey = usePage().props.vapidPublicKey as string | null;

    if (state === 'granted' || state === 'unsupported' || (state === 'denied' && !showBlocked)) return null;

    return (
        <div className="flex items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-5 py-2 text-sm text-amber-900">
            {state === 'denied'
                ? 'Desktop notifications are blocked. Allow them in your browser’s site settings.'
                : 'Turn on desktop notifications to hear about messages, even when Ping Chat is closed.'}
            {state === 'default' && (
                <button
                    type="button"
                    onClick={async () => {
                        const result = await requestNotifications();
                        setState(result);
                        await ensurePush(publicKey);
                    }}
                    className="shrink-0 rounded-md bg-teal-700 px-3 py-1 font-semibold text-white hover:bg-teal-800"
                >
                    Turn on
                </button>
            )}
        </div>
    );
}

export default function InfoPanel({
    panel,
    workspace,
    onActivityRead,
}: {
    panel: InfoPanelName;
    workspace: WorkspaceInfo;
    onActivityRead: () => void;
}) {
    const [items, setItems] = useState<unknown[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [filter, setFilter] = useState('');
    const [reminders, setReminders] = useState<ReminderItem[]>([]);

    useEffect(() => {
        if (panel !== 'scheduled') return;

        axios
            .get<ReminderItem[]>(route('reminders.index', workspace.slug))
            .then(({ data }) => setReminders(data))
            .catch(() => setReminders([]));
    }, [panel, workspace.slug]);

    useEffect(() => {
        const controller = new AbortController();
        setItems(null);
        setFailed(false);

        axios
            .get<unknown[]>(route(`feed.${panel}`, workspace.slug), { signal: controller.signal })
            .then(({ data }) => {
                setItems(data);
                if (panel === 'activity') {
                    axios.post(route('feed.activity.read', workspace.slug)).then(onActivityRead).catch(() => {});
                }
            })
            .catch((error) => {
                if (!axios.isCancel(error)) setFailed(true);
            });

        return () => controller.abort();
    }, [panel, workspace.slug, onActivityRead]);

    const channelUrl = (channelId: number, thread?: number | null) =>
        route('channels.show', { workspace: workspace.slug, channel: channelId, ...(thread ? { thread } : {}) });

    return (
        <section aria-label={TITLES[panel]} className="flex min-w-0 flex-1 flex-col">
            <header className="flex h-12 shrink-0 items-center border-b border-slate-200 px-5">
                <h1 className="text-lg font-bold text-slate-900">{TITLES[panel]}</h1>
            </header>

            {panel === 'activity' && <NotificationBanner showBlocked />}

            <div className="flex-1 overflow-y-auto">
                {failed && <p className="p-5 text-sm text-red-600">Could not load this list. Try again.</p>}
                {items === null && !failed && <p className="p-5 text-sm text-slate-500">Loading…</p>}
                {items?.length === 0 && <p className="p-5 text-center text-sm text-slate-500">{EMPTY[panel]}</p>}

                {panel === 'activity' &&
                    (items as ActivityItem[] | null)?.map((item) => (
                        <Row
                            key={item.id}
                            unread={!item.read}
                            href={channelUrl(item.message.channel_id, item.message.parent_id)}
                        >
                            <span className="block text-xs text-slate-500">
                                {item.type === 'mention' ? 'Mention' : 'Thread reply'} in {item.channel_label} ·{' '}
                                {when(item.message.created_at)}
                            </span>
                            <span className="block font-semibold text-slate-900">{item.message.user.name}</span>
                            <span className="block truncate text-sm text-slate-700">{item.message.body}</span>
                        </Row>
                    ))}

                {panel === 'saved' &&
                    (items as ThreadItem[] | null)?.map((item) => (
                        <Row key={item.id} href={channelUrl(item.channel_id, item.parent_id)}>
                            <span className="block text-xs text-slate-500">
                                {item.channel_label} · {when(item.created_at)}
                            </span>
                            <span className="block font-semibold text-slate-900">{item.user.name}</span>
                            <span className="block truncate text-sm text-slate-700">
                                {item.removed ? 'This message was deleted.' : item.body || item.cards[0]?.title}
                            </span>
                        </Row>
                    ))}

                {panel === 'scheduled' &&
                    (items as ScheduledItem[] | null)?.map((item) => (
                        <div key={item.id} className="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
                            <span className="min-w-0">
                                <span className="block text-xs text-slate-500">
                                    {item.channel_label} · {item.failed ? 'Could not be sent' : when(item.send_at)}
                                </span>
                                <span className="block truncate text-sm text-slate-800">{item.body}</span>
                            </span>
                            <button
                                type="button"
                                onClick={async () => {
                                    await axios.delete(route('scheduled.destroy', [workspace.slug, item.id]));
                                    setItems((current) => (current as ScheduledItem[]).filter((other) => other.id !== item.id));
                                }}
                                className="shrink-0 rounded-md border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50"
                            >
                                Cancel
                            </button>
                        </div>
                    ))}

                {panel === 'scheduled' && reminders.length > 0 && (
                    <h3 className="px-5 pb-1 pt-4 text-sm font-semibold text-slate-800">Reminders</h3>
                )}
                {panel === 'scheduled' &&
                    reminders.map((reminder) => (
                        <div key={reminder.id} className="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
                            <span className="min-w-0">
                                <span className="block text-xs text-slate-500">{when(reminder.remind_at)}</span>
                                <span className="block truncate text-sm text-slate-800">{reminder.text}</span>
                            </span>
                            <button
                                type="button"
                                aria-label={`Cancel reminder: ${reminder.text}`}
                                onClick={async () => {
                                    await axios.delete(route('reminders.destroy', [workspace.slug, reminder.id]));
                                    setReminders((current) => current.filter((other) => other.id !== reminder.id));
                                }}
                                className="shrink-0 rounded-md border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-100"
                            >
                                Cancel
                            </button>
                        </div>
                    ))}

                {panel === 'threads' &&
                    (items as ThreadItem[] | null)?.map((item) => (
                        <Row key={item.id} href={channelUrl(item.channel_id, item.id)}>
                            <span className="block text-xs text-slate-500">
                                {item.channel_label} · {item.reply_count} {item.reply_count === 1 ? 'reply' : 'replies'}
                                {item.last_reply_at && ` · Last reply ${when(item.last_reply_at)}`}
                            </span>
                            <span className="block font-semibold text-slate-900">{item.user.name}</span>
                            <span className="block truncate text-sm text-slate-700">
                                {item.removed ? 'This message was deleted.' : item.body}
                            </span>
                        </Row>
                    ))}

                {panel === 'drafts' &&
                    (items as DraftItem[] | null)?.map((item) => (
                        <Row key={item.id} href={channelUrl(item.channel_id)}>
                            <span className="block text-xs text-slate-500">
                                {item.channel_label} · {when(item.updated_at)}
                            </span>
                            <span className="block truncate text-sm text-slate-800">{item.body}</span>
                        </Row>
                    ))}

                {panel === 'channels' && (
                    <div className="border-b border-slate-200 px-5 py-3">
                        <input
                            type="search"
                            aria-label="Search channels"
                            placeholder="Search channels"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            className="w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600"
                        />
                    </div>
                )}

                {panel === 'channels' &&
                    (items as BrowseItem[] | null)
                        ?.filter((item) => item.name.includes(filter.trim().toLowerCase()))
                        .map((item) => (
                            <div key={item.id} className="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
                                <span className="min-w-0">
                                    <span className="block truncate font-semibold text-slate-900">
                                        {item.type === 'private' ? '🔒 ' : '# '}
                                        {item.name}
                                        {item.archived && <span className="ml-2 text-xs font-normal text-slate-500">Archived</span>}
                                    </span>
                                    <span className="block truncate text-xs text-slate-500">
                                        {item.member_count} {item.member_count === 1 ? 'member' : 'members'}
                                        {item.topic && ` · ${item.topic}`}
                                    </span>
                                </span>
                                <span className="flex shrink-0 gap-2">
                                    {item.joined && (
                                        <Link href={channelUrl(item.id)} className="rounded-md border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50">
                                            Open
                                        </Link>
                                    )}
                                    {item.archived && item.can_manage && (
                                        <button
                                            type="button"
                                            onClick={() => router.post(route('channels.unarchive', [workspace.slug, item.id]))}
                                            className="rounded-md border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50"
                                        >
                                            Restore
                                        </button>
                                    )}
                                    {!item.joined && !item.archived && (
                                        <button
                                            type="button"
                                            onClick={() => router.post(route('channels.join', [workspace.slug, item.id]))}
                                            className="rounded-md bg-teal-700 px-3 py-1 text-sm font-semibold text-white hover:bg-teal-800"
                                        >
                                            Join
                                        </button>
                                    )}
                                </span>
                            </div>
                        ))}

                {panel === 'files' &&
                    (items as FileItem[] | null)?.map((item) => (
                        <a
                            key={item.id}
                            href={item.url}
                            target={item.is_image ? '_blank' : undefined}
                            rel="noopener noreferrer"
                            className="flex items-center gap-3 border-b border-slate-200 px-5 py-3 hover:bg-slate-50"
                        >
                            {item.is_image ? (
                                <img src={item.url} alt="" loading="lazy" className="h-10 w-10 shrink-0 rounded object-cover" />
                            ) : (
                                <FileIcon className="h-10 w-10 shrink-0 p-1.5 text-sky-700" />
                            )}
                            <span className="min-w-0">
                                <span className="block truncate font-semibold text-slate-900">{item.name}</span>
                                <span className="block truncate text-xs text-slate-500">
                                    {item.sender} · {item.channel_label} · {when(item.created_at)}
                                </span>
                            </span>
                        </a>
                    ))}
            </div>
        </section>
    );
}
