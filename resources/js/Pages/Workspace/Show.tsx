import {
    DeleteMessageModal,
    ForwardModal,
    ManageAppsModal,
} from '@/Components/Chat/Apps';
import CanvasPane from '@/Components/Chat/CanvasPane';
import ChannelSettings from '@/Components/Chat/ChannelSettings';
import Composer from '@/Components/Chat/Composer';
import Huddle, { requestHuddleJoin } from '@/Components/Chat/Huddle';
import HuddleInvite, { HuddleCall } from '@/Components/Chat/HuddleInvite';
import InfoPanel, { NotificationBanner } from '@/Components/Chat/InfoPanel';
import { AnalyticsModal, AuditModal } from '@/Components/Chat/Insights';
import {
    CustomizeModal,
    NewDmModal,
    PhotoModal,
    StatusModal,
} from '@/Components/Chat/Dialogs';
import {
    BellIcon,
    LockIcon,
    PinIcon,
    StarIcon,
    UsersIcon,
} from '@/Components/Chat/Icons';
import InviteModal from '@/Components/Chat/InviteModal';
import MessageList from '@/Components/Chat/MessageList';
import NewChannelModal from '@/Components/Chat/NewChannelModal';
import Rail from '@/Components/Chat/Rail';
import { ExportModal, TwoFactorModal } from '@/Components/Chat/Security';
import { setCustomEmojis } from '@/Components/Chat/RichText';
import {
    EmojiModal,
    GroupsModal,
    WorkflowsModal,
} from '@/Components/Chat/Tools';
import {
    MembersModal,
    PreferencesModal,
    QuickSwitcher,
    ShortcutsModal,
} from '@/Components/Chat/Settings';
import Sidebar from '@/Components/Chat/Sidebar';
import ThreadPane from '@/Components/Chat/ThreadPane';
import TopBar from '@/Components/Chat/TopBar';
import { echo } from '@/echo';
import { ensurePush, inDnd, showNotification } from '@/notify';
import { PageProps } from '@/types';
import {
    ActiveChannel,
    ChatMessage,
    ChatUser,
    InfoPanelName,
    MessageReaction,
    RailItem,
    RailView,
    SidebarChannel,
    WorkspaceExtras,
    WorkspaceInfo,
    WorkspaceMember,
} from '@/types/chat';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

interface Props extends Record<string, unknown> {
    workspace: WorkspaceInfo;
    channel: ActiveChannel;
    sidebar: SidebarChannel[];
    members: WorkspaceMember[];
    channelMemberIds: number[];
    messages: ChatMessage[];
    hasMore: boolean;
    lastReadId: number | null;
    draft: string;
    openThreadId: number | null;
    activityUnread: number;
    vapidPublicKey: string | null;
    savedIds: number[];
    extras: WorkspaceExtras;
}

interface ActivityEvent {
    workspace_id: number;
    channel_id: number;
    message_id: number;
    parent_id: number | null;
    sender: string;
    preview: string;
}

interface ReactionUpdate {
    id: number;
    reactions: MessageReaction[];
}

interface MessageRemoval {
    id: number;
    parent_id: number | null;
    parent_reply_count: number | null;
}

interface Thread {
    parent: ChatMessage;
    replies: ChatMessage[];
}

const TYPING_TIMEOUT_MS = 3500;

export default function Show({
    auth,
    workspace,
    channel,
    sidebar,
    members,
    channelMemberIds,
    messages: initialMessages,
    hasMore: initialHasMore,
    lastReadId,
    draft,
    openThreadId,
    activityUnread: initialActivityUnread,
    vapidPublicKey,
    savedIds: initialSavedIds,
    extras,
}: PageProps<Props>) {
    setCustomEmojis(extras.emojis);

    const me = auth.user;
    const meMember: WorkspaceMember = members.find((m) => m.id === me.id) ?? {
        id: me.id,
        name: me.name,
        status_emoji: null,
        status_text: null,
        is_away: false,
        in_dnd: false,
    };

    const [messages, setMessages] = useState(initialMessages);
    const [hasMore, setHasMore] = useState(initialHasMore);
    const [unread, setUnread] = useState<Record<number, number>>({});
    const [online, setOnline] = useState<Set<number>>(new Set([me.id]));
    const [typers, setTypers] = useState<ChatUser[]>([]);
    const [navOpen, setNavOpen] = useState(false);
    const [view, setView] = useState<RailView>('home');
    const [modal, setModal] = useState<
        | 'channel'
        | 'invite'
        | 'dm'
        | 'status'
        | 'customize'
        | 'apps'
        | 'settings'
        | 'photo'
        | 'members'
        | 'preferences'
        | 'switcher'
        | 'shortcuts'
        | 'groups'
        | 'emoji'
        | 'workflows'
        | 'export'
        | 'my-data'
        | 'two-factor'
        | 'analytics'
        | 'audit'
        | null
    >(null);
    const [membersOpen, setMembersOpen] = useState(false);
    const [thread, setThread] = useState<Thread | null>(null);
    const closeThread = useCallback(() => setThread(null), []);
    const [pendingDelete, setPendingDelete] = useState<ChatMessage | null>(
        null,
    );
    const [panel, setPanel] = useState<InfoPanelName | null>(null);
    const [saved, setSaved] = useState(new Set(initialSavedIds));
    const [forwarding, setForwarding] = useState<ChatMessage | null>(null);
    const [pins, setPins] = useState<ChatMessage[] | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [tab, setTab] = useState<'messages' | 'canvas'>('messages');
    const [canvasVersion, setCanvasVersion] = useState(0);
    const [activityUnread, setActivityUnread] = useState(initialActivityUnread);
    const clearActivity = useCallback(() => setActivityUnread(0), []);

    const typingTimers = useRef(new Map<number, number>());
    const subscribed = useRef(new Set<number>());

    // A new channel page replaces the visible conversation.
    useEffect(() => {
        setMessages(initialMessages);
        setHasMore(initialHasMore);
        setTypers([]);
        setNavOpen(false);
        setMembersOpen(false);
        setPanel(null);
    }, [initialMessages, initialHasMore]);

    useEffect(
        () => setActivityUnread(initialActivityUnread),
        [initialActivityUnread],
    );

    useEffect(() => {
        setThread(null);
        setTab('messages');
        setCanvasVersion(0);
        setPins(null);
        if (openThreadId) {
            axios
                .get<Thread>(
                    route('messages.thread', [
                        workspace.slug,
                        channel.id,
                        openThreadId,
                    ]),
                )
                .then(({ data }) => setThread(data))
                .catch(() => {});
        }
    }, [channel.id, openThreadId, workspace.slug]);

    // The open channel was deleted or this person was removed from it.
    useEffect(() => {
        if (!channel.archived && !sidebar.some((c) => c.id === channel.id)) {
            router.visit(route('workspaces.show', workspace.slug));
        }
    }, [sidebar, channel.id, channel.archived, workspace.slug]);

    useEffect(() => {
        ensurePush(vapidPublicKey);
    }, [vapidPublicKey]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setModal('switcher');
            } else if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                setModal('shortcuts');
            } else if (
                e.altKey &&
                (e.key === 'ArrowUp' || e.key === 'ArrowDown')
            ) {
                e.preventDefault();
                const position = sidebar.findIndex((c) => c.id === channel.id);
                const next =
                    sidebar[position + (e.key === 'ArrowDown' ? 1 : -1)];
                if (next)
                    router.visit(
                        route('channels.show', [workspace.slug, next.id]),
                    );
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [sidebar, channel.id, workspace.slug]);

    // The server's counts replace any live increments.
    useEffect(() => {
        setUnread(Object.fromEntries(sidebar.map((c) => [c.id, c.unread])));
    }, [sidebar]);

    const addMessage = (message: ChatMessage) =>
        setMessages((current) =>
            current.some((m) => m.id === message.id)
                ? current
                : [...current, message],
        );

    const applyReply = (reply: ChatMessage) => {
        const count = reply.parent_reply_count ?? 0;

        setMessages((current) =>
            current.map((m) =>
                m.id === reply.parent_id
                    ? {
                          ...m,
                          reply_count: count,
                          last_reply_at: reply.created_at,
                      }
                    : m,
            ),
        );
        setThread((current) =>
            current?.parent.id === reply.parent_id
                ? {
                      parent: {
                          ...current.parent,
                          reply_count: count,
                          last_reply_at: reply.created_at,
                      },
                      replies: current.replies.some((r) => r.id === reply.id)
                          ? current.replies
                          : [...current.replies, reply],
                  }
                : current,
        );
    };

    const applyReactions = ({ id, reactions }: ReactionUpdate) => {
        const update = (m: ChatMessage) =>
            m.id === id ? { ...m, reactions } : m;

        setMessages((current) => current.map(update));
        setThread(
            (current) =>
                current && {
                    parent: update(current.parent),
                    replies: current.replies.map(update),
                },
        );
    };

    const applyUpdate = (message: ChatMessage) => {
        const update = (m: ChatMessage) => (m.id === message.id ? message : m);

        setMessages((current) => current.map(update));
        setThread(
            (current) =>
                current && {
                    parent: update(current.parent),
                    replies: current.replies.map(update),
                },
        );
    };

    const applyRemoval = ({
        id,
        parent_id,
        parent_reply_count,
    }: MessageRemoval) => {
        const count = parent_reply_count ?? 0;
        const adjust = (m: ChatMessage) =>
            m.id === parent_id ? { ...m, reply_count: count } : m;

        setMessages((current) =>
            current.filter((m) => m.id !== id).map(adjust),
        );
        setThread((current) =>
            !current || current.parent.id === id
                ? null
                : {
                      parent: adjust(current.parent),
                      replies: current.replies.filter((r) => r.id !== id),
                  },
        );
    };

    const clearTyper = (userId: number) => {
        window.clearTimeout(typingTimers.current.get(userId));
        typingTimers.current.delete(userId);
        setTypers((current) => current.filter((t) => t.id !== userId));
    };

    // Echo callbacks are registered once, so they read the latest handlers from this ref.
    const handlers = useRef({
        message: (_: ChatMessage) => {},
        activity: (_: ActivityEvent) => {},
        canvas: (_: { channel_id: number; version: number }) => {},
        typing: (_channelId: number, _user: ChatUser) => {},
        huddle: (_event: Omit<HuddleCall, 'place'>) => {},
    });
    const [incomingHuddle, setIncomingHuddle] = useState<HuddleCall | null>(null);
    const [huddleJoins, setHuddleJoins] = useState(0);
    const needsAlert = (channelId: number) =>
        !inDnd(me) && (document.hidden || channelId !== channel.id);

    const openChannel = (channelId: number, threadId?: number | null) =>
        router.visit(
            route('channels.show', {
                workspace: workspace.slug,
                channel: channelId,
                ...(threadId ? { thread: threadId } : {}),
            }),
        );

    const joinHuddle = (channelId: number) => {
        setIncomingHuddle(null);
        requestHuddleJoin(channelId);

        if (channelId === channel.id) setHuddleJoins((count) => count + 1);
        else openChannel(channelId);
    };

    handlers.current = {
        huddle: (event) => {
            const target = sidebar.find((c) => c.id === event.channel_id);

            if (!target || target.muted || event.by.id === me.id || inDnd(me)) return;

            const place = target.type === 'dm' ? null : `#${target.name}`;
            setIncomingHuddle({ ...event, place });

            if (document.hidden) {
                showNotification({
                    title: event.by.name,
                    body: `Started a huddle${place ? ` in ${place}` : ' with you'}`,
                    tag: `huddle-${event.channel_id}`,
                    onClick: () => joinHuddle(event.channel_id),
                });
            }
        },
        canvas: (event) => {
            if (event.channel_id === channel.id)
                setCanvasVersion(event.version);
        },
        activity: (event) => {
            if (event.workspace_id !== workspace.id) return;
            setActivityUnread((count) => count + 1);
            if (needsAlert(event.channel_id)) {
                showNotification({
                    title: event.sender,
                    body: event.preview,
                    tag: `message-${event.message_id}`,
                    onClick: () =>
                        openChannel(event.channel_id, event.parent_id),
                });
            }
        },
        message: (message) => {
            const target = sidebar.find((c) => c.id === message.channel_id);

            if (
                target &&
                message.parent_id === null &&
                message.user.id !== me.id &&
                (target.type === 'dm' ||
                    (target.notify_all && !target.muted)) &&
                needsAlert(message.channel_id)
            ) {
                showNotification({
                    title:
                        target.type === 'dm'
                            ? message.user.name
                            : `#${target.name}`,
                    body:
                        message.body ||
                        message.cards[0]?.title ||
                        'New message',
                    tag: `message-${message.id}`,
                    onClick: () => openChannel(message.channel_id),
                });
            }

            if (message.parent_id !== null) {
                if (message.channel_id === channel.id) applyReply(message);
            } else if (message.channel_id === channel.id) {
                addMessage(message);
                clearTyper(message.user.id);
                if (message.user.id !== me.id) {
                    axios
                        .post(
                            route('channels.read', [
                                workspace.slug,
                                channel.id,
                            ]),
                        )
                        .catch(() => {});
                }
            } else if (message.user.id !== me.id) {
                setUnread((current) => ({
                    ...current,
                    [message.channel_id]:
                        (current[message.channel_id] ?? 0) + 1,
                }));
            }
        },
        typing: (channelId, user) => {
            if (channelId !== channel.id) return;
            window.clearTimeout(typingTimers.current.get(user.id));
            typingTimers.current.set(
                user.id,
                window.setTimeout(() => clearTyper(user.id), TYPING_TIMEOUT_MS),
            );
            setTypers((current) =>
                current.some((t) => t.id === user.id)
                    ? current
                    : [...current, user],
            );
        },
    };

    // Listen to every channel in the sidebar, so unread counts update live.
    useEffect(() => {
        sidebar.forEach(({ id }) => {
            if (subscribed.current.has(id)) return;
            subscribed.current.add(id);
            echo.private(`channel.${id}`)
                .listen('.message.sent', (message: ChatMessage) =>
                    handlers.current.message(message),
                )
                .listen('.huddle.started', (event: Omit<HuddleCall, 'place'>) =>
                    handlers.current.huddle(event),
                )
                .listen('.reaction.updated', applyReactions)
                .listen('.message.updated', applyUpdate)
                .listen('.message.deleted', applyRemoval)
                .listen(
                    '.canvas.updated',
                    (event: { channel_id: number; version: number }) =>
                        handlers.current.canvas(event),
                )
                .listenForWhisper('typing', (user: ChatUser) =>
                    handlers.current.typing(id, user),
                );
        });
    }, [sidebar]);

    useEffect(() => {
        const channels = subscribed.current;

        return () => {
            channels.forEach((id) => echo.leave(`channel.${id}`));
            channels.clear();
        };
    }, []);

    useEffect(() => {
        const presence = `workspace.${workspace.id}`;
        const personal = `App.Models.User.${me.id}`;

        echo.join(presence)
            .listen('.member.updated', () =>
                router.reload({
                    only: ['auth', 'members', 'channelMemberIds', 'extras'],
                }),
            )
            .here((users: ChatUser[]) =>
                setOnline(new Set(users.map((u) => u.id))),
            )
            .joining((user: ChatUser) => {
                setOnline((current) => new Set(current).add(user.id));
                router.reload({ only: ['members', 'channelMemberIds'] });
            })
            .leaving((user: ChatUser) =>
                setOnline((current) => {
                    const next = new Set(current);
                    next.delete(user.id);
                    return next;
                }),
            );

        echo.private(personal)
            .listen('.sidebar.changed', (event: { workspace_id: number }) => {
                if (event.workspace_id === workspace.id)
                    router.reload({ only: ['sidebar'] });
            })
            .listen('.member.removed', (event: { workspace_id: number }) => {
                if (event.workspace_id === workspace.id)
                    router.visit(route('dashboard'));
            })
            .listen('.activity.created', (event: ActivityEvent) =>
                handlers.current.activity(event),
            );

        return () => {
            echo.leave(presence);
            echo.leave(personal);
        };
    }, [workspace.id, me.id]);

    const unreadTotal =
        activityUnread +
        sidebar.reduce(
            (total, c) =>
                total +
                (c.id === channel.id || c.muted ? 0 : (unread[c.id] ?? 0)),
            0,
        );

    const channels = useMemo(
        () =>
            sidebar.map((c) => ({
                ...c,
                unread: c.id === channel.id ? 0 : (unread[c.id] ?? 0),
            })),
        [sidebar, unread, channel.id],
    );

    // The first message from someone else that arrived since the last visit.
    const newLineId = useMemo(
        () =>
            initialMessages.find(
                (m) => m.id > (lastReadId ?? 0) && m.user.id !== me.id,
            )?.id ?? null,
        [initialMessages, lastReadId, me.id],
    );

    const partner = members.find((m) => m.id === channel.dm_user_id);
    const title =
        channel.type === 'dm'
            ? (partner?.name ?? 'Direct message')
            : channel.name;
    const label = channel.type === 'dm' ? title : `#${title}`;
    const people = members.filter((m) => m.id !== me.id);
    const mentionable =
        channel.type === 'dm'
            ? people
            : [
                  ...people,
                  { id: -1, name: 'channel' },
                  { id: -2, name: 'here' },
                  ...extras.groups.map((group) => ({
                      id: -100 - group.id,
                      name: group.handle,
                  })),
              ];
    const channelMembers = members.filter((m) =>
        channelMemberIds.includes(m.id),
    );
    const current = sidebar.find((c) => c.id === channel.id);
    const isStarred = current?.starred ?? false;
    const notifyAll = current?.notify_all ?? false;
    const railCurrent: RailItem | null =
        panel === 'activity' || panel === 'files' ? panel : panel ? null : view;

    const chooseRail = (item: RailItem) => {
        if (item === 'activity' || item === 'files') {
            setPanel(item);
        } else {
            setView(item);
            setPanel(null);
        }
    };

    const scheduleMessage = async (body: string, sendAt: string) => {
        await axios.post(
            route('channels.schedule', [workspace.slug, channel.id]),
            { body, send_at: sendAt },
        );
        setNotice(
            `Scheduled for ${new Date(sendAt).toLocaleString()}. See it under Scheduled.`,
        );
    };

    const removeSection = async (id: number) => {
        await axios.delete(route('sections.destroy', [workspace.slug, id]));
        router.reload({ only: ['sidebar', 'extras'] });
    };

    const runCommand = async (text: string) => {
        const { data } = await axios.post<{
            text?: string;
            message?: ChatMessage;
            reload?: boolean;
        }>(route('channels.command', [workspace.slug, channel.id]), { text });

        if (data.message) addMessage(data.message);
        setNotice(data.text ?? null);
        if (data.reload)
            router.reload({ only: ['channel', 'sidebar', 'members'] });
    };

    const send = async (body: string, files: File[], parentId?: number) => {
        if (!parentId && files.length === 0 && /^\/[a-z]+(\s|$)/i.test(body)) {
            await runCommand(body);
            return;
        }

        const form = new FormData();
        form.append('body', body);
        files.forEach((file) => form.append('files[]', file));
        if (parentId) form.append('parent_id', String(parentId));

        const { data } = await axios.post<ChatMessage>(
            route('messages.store', [workspace.slug, channel.id]),
            form,
        );

        if (data.parent_id !== null) applyReply(data);
        else addMessage(data);
    };

    const openThread = async (message: ChatMessage) => {
        const { data } = await axios.get<Thread>(
            route('messages.thread', [workspace.slug, channel.id, message.id]),
        );
        setThread(data);
    };

    const loadOlder = async () => {
        const { data } = await axios.get<{
            messages: ChatMessage[];
            hasMore: boolean;
        }>(route('channels.messages', [workspace.slug, channel.id]), {
            params: { before: messages[0]?.id },
        });
        setMessages((current) => [...data.messages, ...current]);
        setHasMore(data.hasMore);
    };

    const react = (messageId: number, emoji: string) => {
        axios
            .post<ReactionUpdate>(
                route('messages.react', [
                    workspace.slug,
                    channel.id,
                    messageId,
                ]),
                { emoji },
            )
            .then(({ data }) => applyReactions(data))
            .catch(() => {});
    };

    const editMessage = async (messageId: number, body: string) => {
        const { data } = await axios.patch<ChatMessage>(
            route('messages.update', [workspace.slug, channel.id, messageId]),
            { body },
        );
        applyUpdate(data);
    };

    const confirmDelete = async () => {
        if (!pendingDelete) return;

        await axios.delete(
            route('messages.destroy', [
                workspace.slug,
                channel.id,
                pendingDelete.id,
            ]),
        );
        setPendingDelete(null);
    };

    const pinMessage = (messageId: number) => {
        axios
            .post<ChatMessage>(
                route('messages.pin', [workspace.slug, channel.id, messageId]),
            )
            .then(({ data }) => applyUpdate(data))
            .catch(() => {});
    };

    const saveMessage = (messageId: number) => {
        axios
            .post<{ saved: boolean }>(
                route('messages.save', [workspace.slug, channel.id, messageId]),
            )
            .then(({ data }) =>
                setSaved((current) => {
                    const next = new Set(current);
                    if (data.saved) next.add(messageId);
                    else next.delete(messageId);
                    return next;
                }),
            )
            .catch(() => {});
    };

    const togglePins = async () => {
        if (pins) {
            setPins(null);
            return;
        }

        const { data } = await axios.get<ChatMessage[]>(
            route('channels.pins', [workspace.slug, channel.id]),
        );
        setPins(data);
    };

    const toggleAway = async () => {
        await axios.post(route('status.away'));
        router.reload({ only: ['members', 'channelMemberIds'] });
    };

    const toggleNotify = async () => {
        await axios.post(
            route('channels.notify', [workspace.slug, channel.id]),
        );
        router.reload({ only: ['sidebar'] });
    };

    const toggleStar = async () => {
        await axios.post(route('channels.star', [workspace.slug, channel.id]));
        router.reload({ only: ['sidebar'] });
    };

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-[#0b2523]">
            <Head
                title={`${unreadTotal > 0 ? `(${unreadTotal}) ` : ''}${label ?? 'Chat'}`}
            />

            <TopBar workspace={workspace} />
            <NotificationBanner />

            <div className="flex min-h-0 flex-1">
                <Rail
                    workspace={workspace}
                    me={meMember}
                    current={railCurrent}
                    activityUnread={activityUnread}
                    onChange={chooseRail}
                    onStatus={() => setModal('status')}
                    onPreferences={() => setModal('preferences')}
                    onPhoto={() => setModal('photo')}
                    onToggleAway={toggleAway}
                />

                <div className="flex min-w-0 flex-1 overflow-hidden bg-white md:mb-1 md:mr-1 md:rounded-lg">
                    <Sidebar
                        workspace={workspace}
                        channel={channel}
                        channels={channels}
                        members={members}
                        online={online}
                        me={me}
                        open={navOpen}
                        view={view}
                        onNewChannel={() => setModal('channel')}
                        onNewDm={() => setModal('dm')}
                        onInvite={() => setModal('invite')}
                        onCustomize={() => setModal('customize')}
                        onManageApps={() => setModal('apps')}
                        onManageMembers={() => setModal('members')}
                        onPreferences={() => setModal('preferences')}
                        onTool={setModal}
                        sections={extras.sections}
                        onRemoveSection={removeSection}
                        panel={panel}
                        onPanel={setPanel}
                    />

                    {panel && (
                        <InfoPanel
                            panel={panel}
                            workspace={workspace}
                            onActivityRead={clearActivity}
                        />
                    )}

                    <main
                        className={`${navOpen || thread || panel ? 'hidden' : 'flex'} min-w-0 flex-1 flex-col ${
                            panel ? 'md:hidden' : 'md:flex'
                        }`}
                    >
                        <header className="relative flex h-12 shrink-0 items-center gap-2 border-b border-slate-200 px-4">
                            <button
                                type="button"
                                onClick={() => setNavOpen(true)}
                                aria-label="Open navigation"
                                className="rounded px-2 py-1 text-slate-600 hover:bg-slate-100 md:hidden"
                            >
                                ☰
                            </button>
                            <button
                                type="button"
                                onClick={toggleStar}
                                aria-label={
                                    isStarred
                                        ? 'Remove star'
                                        : 'Star this conversation'
                                }
                                aria-pressed={isStarred}
                                className={`rounded p-1 hover:bg-slate-100 ${isStarred ? 'text-amber-500' : 'text-slate-500'}`}
                            >
                                <StarIcon
                                    filled={isStarred}
                                    className="h-4 w-4"
                                />
                            </button>
                            <div className="min-w-0">
                                <h1 className="flex min-w-0 items-center gap-1 text-lg font-bold leading-tight text-slate-900">
                                    {channel.type === 'private' && (
                                        <LockIcon className="h-4 w-4 shrink-0" />
                                    )}
                                    {channel.type === 'public' && (
                                        <span aria-hidden>#</span>
                                    )}
                                    {channel.type === 'dm' ? (
                                        <span className="truncate">
                                            {title}
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            title="Channel settings"
                                            onClick={() => setModal('settings')}
                                            className="truncate rounded hover:bg-slate-100"
                                        >
                                            {title}
                                        </button>
                                    )}
                                </h1>
                                {channel.topic && (
                                    <p className="truncate text-xs text-slate-500">
                                        {channel.topic}
                                    </p>
                                )}
                            </div>
                            {channel.type === 'dm' && partner?.status_emoji && (
                                <span title={partner.status_text ?? undefined}>
                                    {partner.status_emoji}
                                </span>
                            )}
                            {channel.type === 'dm' && partner && (
                                <span className="text-xs text-slate-500">
                                    {online.has(partner.id) && !partner.is_away
                                        ? 'Active'
                                        : 'Away'}
                                </span>
                            )}

                            <span className="ml-auto" />

                            <Huddle
                                key={`${channel.id}-${huddleJoins}`}
                                channelId={channel.id}
                                me={me}
                                onStarted={() => {
                                    axios
                                        .post(route('channels.huddle', [workspace.slug, channel.id]))
                                        .catch(() => {});
                                    send(
                                        '🎧 started a huddle. Choose Huddle in this conversation to join.',
                                        [],
                                    ).catch(() => {});
                                }}
                            />

                            {channel.type !== 'dm' && (
                                <button
                                    type="button"
                                    onClick={toggleNotify}
                                    aria-pressed={notifyAll}
                                    title={
                                        notifyAll
                                            ? 'Notifying you about every message'
                                            : 'Notify me about every message'
                                    }
                                    aria-label="Notify me about every message in this channel"
                                    className={`rounded p-1 hover:bg-slate-100 ${notifyAll ? 'text-amber-500' : 'text-slate-500'}`}
                                >
                                    <BellIcon className="h-4 w-4" />
                                </button>
                            )}

                            <button
                                type="button"
                                onClick={togglePins}
                                aria-expanded={pins !== null}
                                aria-label="Pinned messages"
                                title="Pinned messages"
                                className="rounded p-1 text-slate-500 hover:bg-slate-100"
                            >
                                <PinIcon className="h-4 w-4" />
                            </button>

                            <button
                                type="button"
                                onClick={() => setMembersOpen(!membersOpen)}
                                aria-expanded={membersOpen}
                                aria-label={`${channelMembers.length} members`}
                                className="flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-sm text-slate-700 hover:bg-slate-50"
                            >
                                <UsersIcon className="h-4 w-4" />
                                {channelMembers.length}
                            </button>

                            {pins && (
                                <div className="absolute right-4 top-11 z-30 max-h-96 w-80 overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-xl">
                                    <p className="px-3 py-1.5 text-xs font-semibold text-slate-500">
                                        Pinned messages
                                    </p>
                                    {pins.length === 0 && (
                                        <p className="px-3 py-2 text-sm text-slate-500">
                                            Nothing is pinned yet.
                                        </p>
                                    )}
                                    {pins.map((pin) => (
                                        <div
                                            key={pin.id}
                                            className="border-t border-slate-100 px-3 py-2"
                                        >
                                            <p className="text-xs font-semibold text-slate-700">
                                                {pin.user.name}
                                            </p>
                                            <p className="whitespace-pre-wrap break-words text-sm text-slate-800">
                                                {pin.body ||
                                                    pin.cards[0]?.title}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {membersOpen && (
                                <ul className="absolute right-4 top-11 z-30 max-h-72 w-60 overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-xl">
                                    {channelMembers.map((member) => (
                                        <li
                                            key={member.id}
                                            className="flex items-center gap-2 px-3 py-1.5 text-sm text-slate-800"
                                        >
                                            <span
                                                className={`h-2 w-2 shrink-0 rounded-full ${
                                                    online.has(member.id) &&
                                                    !member.is_away
                                                        ? 'bg-emerald-500'
                                                        : 'bg-slate-300'
                                                }`}
                                            />
                                            <span className="min-w-0">
                                                <span className="block truncate">
                                                    {member.name}
                                                </span>
                                                {member.title && (
                                                    <span className="block truncate text-xs text-slate-500">
                                                        {member.title}
                                                    </span>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </header>

                        <div
                            role="tablist"
                            className="flex shrink-0 gap-4 border-b border-slate-200 px-5 text-sm"
                        >
                            {(['messages', 'canvas'] as const).map((name) => (
                                <button
                                    key={name}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === name}
                                    onClick={() => setTab(name)}
                                    className={`-mb-px border-b-2 py-1.5 capitalize ${
                                        tab === name
                                            ? 'border-teal-700 font-semibold text-slate-900'
                                            : 'border-transparent text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    {name}
                                </button>
                            ))}
                        </div>

                        {tab === 'canvas' && (
                            <CanvasPane
                                workspace={workspace}
                                channelId={channel.id}
                                remoteVersion={canvasVersion}
                                me={me}
                                members={members}
                            />
                        )}

                        <div
                            className={`${tab === 'messages' ? 'flex' : 'hidden'} min-h-0 flex-1 flex-col`}
                        >
                            <MessageList
                                key={channel.id}
                                messages={messages}
                                hasMore={hasMore}
                                me={me}
                                members={members}
                                emptyText={`No messages yet. Say hello to ${label}.`}
                                newLineId={newLineId}
                                onLoadOlder={loadOlder}
                                onReact={react}
                                onReply={openThread}
                                onEdit={editMessage}
                                onDelete={setPendingDelete}
                                canModerate={workspace.is_admin}
                                savedIds={saved}
                                onPin={pinMessage}
                                onSave={saveMessage}
                                onForward={setForwarding}
                            />

                            {notice && (
                                <p className="mx-5 mb-1 flex items-start justify-between gap-3 whitespace-pre-wrap rounded-md bg-sky-50 px-3 py-2 text-sm text-slate-700">
                                    {notice}
                                    <button
                                        type="button"
                                        aria-label="Dismiss"
                                        className="shrink-0 text-slate-500"
                                        onClick={() => setNotice(null)}
                                    >
                                        ×
                                    </button>
                                </p>
                            )}
                            {channel.archived ? (
                                <p className="mx-5 rounded-lg bg-slate-100 px-4 py-3 text-center text-sm text-slate-600">
                                    This channel is archived. Restore it from
                                    Browse channels to post again.
                                </p>
                            ) : (
                                <Composer
                                    commands
                                    onSchedule={scheduleMessage}
                                    key={`composer-${channel.id}`}
                                    placeholder={`Message ${label}`}
                                    members={mentionable}
                                    initialBody={draft}
                                    onDraft={(body) => {
                                        axios
                                            .put(
                                                route('channels.draft', [
                                                    workspace.slug,
                                                    channel.id,
                                                ]),
                                                { body },
                                            )
                                            .catch(() => {});
                                    }}
                                    onSend={send}
                                    onTyping={() =>
                                        echo
                                            .private(`channel.${channel.id}`)
                                            .whisper('typing', {
                                                id: me.id,
                                                name: me.name,
                                            })
                                    }
                                />
                            )}

                            <p
                                className="h-6 px-6 pt-0.5 text-xs text-slate-500"
                                aria-live="polite"
                            >
                                {typers.length > 0 &&
                                    `${typers.map((t) => t.name).join(', ')} ${typers.length === 1 ? 'is' : 'are'} typing…`}
                            </p>
                        </div>
                    </main>

                    {thread && (
                        <ThreadPane
                            parent={thread.parent}
                            replies={thread.replies}
                            channelLabel={label ?? ''}
                            me={me}
                            members={members}
                            people={mentionable}
                            onClose={closeThread}
                            onSend={(body, files) =>
                                send(body, files, thread.parent.id)
                            }
                            onReact={react}
                            onEdit={editMessage}
                            onDelete={setPendingDelete}
                            canModerate={workspace.is_admin}
                            savedIds={saved}
                            onPin={pinMessage}
                            onSave={saveMessage}
                            onForward={setForwarding}
                        />
                    )}
                </div>
            </div>

            <NewChannelModal
                show={modal === 'channel'}
                workspace={workspace}
                people={people}
                onClose={() => setModal(null)}
            />
            {incomingHuddle && (
                <HuddleInvite
                    call={incomingHuddle}
                    onJoin={() => joinHuddle(incomingHuddle.channel_id)}
                    onDismiss={() => setIncomingHuddle(null)}
                />
            )}

            <InviteModal
                show={modal === 'invite'}
                workspace={workspace}
                onClose={() => setModal(null)}
            />
            <NewDmModal
                show={modal === 'dm'}
                workspace={workspace}
                people={people}
                onClose={() => setModal(null)}
                onInvite={() => setModal('invite')}
            />
            <PhotoModal
                show={modal === 'photo'}
                me={meMember}
                onClose={() => setModal(null)}
            />
            <StatusModal
                show={modal === 'status'}
                me={meMember}
                onClose={() => setModal(null)}
            />
            <CustomizeModal
                show={modal === 'customize'}
                workspace={workspace}
                onClose={() => setModal(null)}
            />
            <ChannelSettings
                show={modal === 'settings'}
                workspace={workspace}
                channel={channel}
                muted={current?.muted ?? false}
                sections={extras.sections}
                sectionId={current?.section_id ?? null}
                nonMembers={members.filter(
                    (m) => !channelMemberIds.includes(m.id),
                )}
                onClose={() => setModal(null)}
            />
            <ForwardModal
                message={forwarding}
                workspace={workspace}
                fromChannelId={channel.id}
                channels={sidebar}
                members={members}
                onClose={() => setForwarding(null)}
            />
            <MembersModal
                show={modal === 'members'}
                workspace={workspace}
                meId={me.id}
                onClose={() => setModal(null)}
            />
            <PreferencesModal
                show={modal === 'preferences'}
                user={me}
                onTwoFactor={() => setModal('two-factor')}
                onMyData={() => setModal('my-data')}
                onClose={() => setModal(null)}
            />
            <QuickSwitcher
                show={modal === 'switcher'}
                workspace={workspace}
                channels={sidebar}
                people={people}
                onClose={() => setModal(null)}
            />
            <ShortcutsModal
                show={modal === 'shortcuts'}
                onClose={() => setModal(null)}
            />
            <GroupsModal
                show={modal === 'groups'}
                workspace={workspace}
                groups={extras.groups}
                members={members}
                onClose={() => setModal(null)}
            />
            <EmojiModal
                show={modal === 'emoji'}
                workspace={workspace}
                emojis={extras.emojis}
                onClose={() => setModal(null)}
            />
            <WorkflowsModal
                show={modal === 'workflows'}
                workspace={workspace}
                channels={sidebar}
                onClose={() => setModal(null)}
            />
            <TwoFactorModal
                show={modal === 'two-factor'}
                enabled={Boolean(me.two_factor_enabled)}
                onClose={() => setModal(null)}
            />
            <ExportModal
                show={modal === 'my-data'}
                url={route('export.personal')}
                title="Download my data"
                description="A ZIP with your profile and every message you wrote. Other people's messages are not included."
                fileName="ping-chat-my-data.zip"
                onClose={() => setModal(null)}
            />
            <ExportModal
                show={modal === 'export'}
                url={route('export.workspace', workspace.slug)}
                title="Export workspace data"
                description="A ZIP with members, public channels and the private channels you belong to. Direct messages, other private channels and file contents are not included."
                fileName="workspace-export.zip"
                onClose={() => setModal(null)}
            />
            <AnalyticsModal
                show={modal === 'analytics'}
                workspace={workspace}
                onClose={() => setModal(null)}
            />
            <AuditModal
                show={modal === 'audit'}
                workspace={workspace}
                onClose={() => setModal(null)}
            />
            <ManageAppsModal
                show={modal === 'apps'}
                workspace={workspace}
                channels={sidebar}
                onClose={() => setModal(null)}
            />
            <DeleteMessageModal
                message={pendingDelete}
                onCancel={() => setPendingDelete(null)}
                onConfirm={confirmDelete}
            />
        </div>
    );
}
