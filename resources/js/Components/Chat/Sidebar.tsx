import Avatar from '@/Components/Chat/Avatar';
import { BookmarkIcon, CaretIcon, ComposeIcon, LockIcon, PencilIcon, PlusIcon, SearchIcon, ThreadIcon } from '@/Components/Chat/Icons';
import { WorkspaceMenu } from '@/Components/Chat/Menus';
import {
    ActiveChannel,
    InfoPanelName,
    RailView,
    SectionInfo,
    SidebarChannel,
    WorkspaceInfo,
    WorkspaceMember,
    WorkspaceTool,
} from '@/types/chat';
import { Link, router } from '@inertiajs/react';
import { ReactNode, useCallback, useState } from 'react';

interface Props {
    workspace: WorkspaceInfo;
    channel: ActiveChannel;
    channels: SidebarChannel[];
    members: WorkspaceMember[];
    online: Set<number>;
    me: { id: number };
    open: boolean;
    view: RailView;
    onNewChannel: () => void;
    onNewDm: () => void;
    onInvite: () => void;
    onCustomize: () => void;
    onManageApps: () => void;
    onManageMembers: () => void;
    onPreferences: () => void;
    onTool: (tool: WorkspaceTool) => void;
    sections: SectionInfo[];
    onRemoveSection: (id: number) => void;
    panel: InfoPanelName | null;
    onPanel: (panel: InfoPanelName) => void;
}

const COLLAPSED_KEY = 'chat.sidebar.collapsed';

function readCollapsed(): string[] {
    try {
        return JSON.parse(window.localStorage.getItem(COLLAPSED_KEY) ?? '[]');
    } catch {
        return [];
    }
}

function Row({
    active,
    unread,
    muted = false,
    children,
}: {
    active: boolean;
    unread: number;
    muted?: boolean;
    children: ReactNode;
}) {
    return (
        <span
            className={`flex h-7 w-full items-center gap-2 rounded-md px-3 text-left text-[15px] ${muted ? 'opacity-50' : ''} ${
                active
                    ? 'bg-teal-100 text-teal-950'
                    : unread > 0
                      ? 'font-bold text-white hover:bg-white/10'
                      : 'text-teal-50/75 hover:bg-white/10'
            }`}
        >
            {children}
            {unread > 0 && !active && (
                <span className="ml-auto rounded-full bg-amber-400 px-1.5 text-xs font-bold text-slate-900">
                    {unread}
                </span>
            )}
        </span>
    );
}

function Section({
    title,
    collapsed,
    onToggle,
    addLabel,
    onAdd,
    onRemove,
    children,
}: {
    title: string;
    collapsed: boolean;
    onToggle: () => void;
    addLabel?: string;
    onAdd?: () => void;
    onRemove?: () => void;
    children: ReactNode;
}) {
    return (
        <section>
            <div className="group flex h-7 items-center rounded-md hover:bg-white/10">
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={!collapsed}
                    className="flex h-7 min-w-0 flex-1 items-center gap-1 px-1 text-[15px] text-teal-50/75"
                >
                    <CaretIcon open={!collapsed} className="h-3.5 w-3.5" />
                    {title}
                </button>
                {onRemove && (
                    <button
                        type="button"
                        onClick={onRemove}
                        aria-label={`Remove section ${title}`}
                        title="Remove section"
                        className="mr-1 rounded px-1 text-teal-50/75 opacity-0 hover:bg-white/15 hover:text-white focus:opacity-100 group-hover:opacity-100"
                    >
                        ×
                    </button>
                )}
                {onAdd && (
                    <button
                        type="button"
                        onClick={onAdd}
                        aria-label={addLabel}
                        title={addLabel}
                        className="mr-1 rounded p-0.5 text-teal-50/75 opacity-0 hover:bg-white/15 hover:text-white focus:opacity-100 group-hover:opacity-100"
                    >
                        <PlusIcon className="h-4 w-4" />
                    </button>
                )}
            </div>
            {!collapsed && children}
        </section>
    );
}

export default function Sidebar({
    workspace,
    channel,
    channels,
    members,
    online,
    me,
    open,
    view,
    onNewChannel,
    onNewDm,
    onInvite,
    onCustomize,
    onManageApps,
    onManageMembers,
    onPreferences,
    onTool,
    sections,
    onRemoveSection,
    panel,
    onPanel,
}: Props) {
    const [filter, setFilter] = useState('');
    const [menuOpen, setMenuOpen] = useState(false);
    const closeMenu = useCallback(() => setMenuOpen(false), []);
    const [collapsed, setCollapsed] = useState<string[]>(readCollapsed);

    const toggle = (section: string) => {
        const next = collapsed.includes(section)
            ? collapsed.filter((name) => name !== section)
            : [...collapsed, section];
        setCollapsed(next);
        try {
            window.localStorage.setItem(COLLAPSED_KEY, JSON.stringify(next));
        } catch {
            // Collapsed sections are a convenience; losing them is harmless.
        }
    };

    const term = filter.trim().toLowerCase();
    const matches = (name: string | null) => !term || (name ?? '').toLowerCase().includes(term);

    const dmByUser = new Map(channels.filter((c) => c.type === 'dm').map((c) => [c.dm_user_id, c]));
    const people = members.filter((m) => m.id !== me.id && matches(m.name));
    const self = members.find((m) => m.id === me.id);
    const nameOf = (c: SidebarChannel) => members.find((m) => m.id === c.dm_user_id)?.name ?? null;
    const named = channels.filter((c) => c.type !== 'dm' && matches(c.name));
    const starred = channels.filter((c) => c.starred && matches(c.type === 'dm' ? nameOf(c) : c.name));

    const channelLink = (c: SidebarChannel) => {
        if (c.type === 'dm') {
            const person = members.find((m) => m.id === c.dm_user_id);
            return person ? personRow(person) : null;
        }

        return (
            <Link key={c.id} href={route('channels.show', [workspace.slug, c.id])} className="block">
                <Row active={!panel && c.id === channel.id} unread={c.muted ? 0 : c.unread} muted={c.muted}>
                    {c.type === 'private' ? (
                        <LockIcon className="h-4 w-4 shrink-0 opacity-70" />
                    ) : (
                        <span aria-hidden className="w-4 shrink-0 text-center opacity-70">#</span>
                    )}
                    <span className="truncate">{c.name}</span>
                </Row>
            </Link>
        );
    };

    const personRow = (person: WorkspaceMember) => {
        const dm = dmByUser.get(person.id);
        const label = (
            <Row
                active={!panel && dm?.id === channel.id}
                unread={dm?.muted ? 0 : (dm?.unread ?? 0)}
                muted={dm?.muted}
            >
                <span className="relative shrink-0">
                    <Avatar user={person} className="h-5 w-5 rounded text-xs" />
                    <span
                        className={`absolute -bottom-0.5 -right-0.5 h-2 w-2 rounded-full border border-[#10302e] ${
                            online.has(person.id) && !person.is_away ? 'bg-emerald-400' : 'bg-slate-400'
                        }`}
                    />
                </span>
                <span className="truncate">
                    {person.name}
                    {person.id === me.id && ' (you)'}
                </span>
                {person.in_dnd && (
                    <span title="Notifications paused" className="shrink-0 text-sm">
                        💤
                    </span>
                )}
                {person.status_emoji && (
                    <span title={person.status_text ?? undefined} className="shrink-0 text-sm">
                        {person.status_emoji}
                    </span>
                )}
                <span className="sr-only">{online.has(person.id) && !person.is_away ? '(online)' : '(away)'}</span>
            </Row>
        );

        return dm ? (
            <Link key={person.id} href={route('channels.show', [workspace.slug, dm.id])} className="block">
                {label}
            </Link>
        ) : (
            <button
                key={person.id}
                type="button"
                className="block w-full"
                onClick={() => router.post(route('channels.direct', [workspace.slug, person.id]))}
            >
                {label}
            </button>
        );
    };

    const action = 'flex h-7 w-full items-center gap-2 rounded-md px-3 text-[15px] text-teal-50/75 hover:bg-white/10';

    return (
        <aside
            className={`${open ? 'flex' : 'hidden'} w-full shrink-0 flex-col bg-[#10302e] text-white md:flex md:w-72`}
            aria-label="Conversations"
        >
            <div className="relative px-4 pb-2 pt-3">
                <div className="flex items-center justify-between gap-2">
                    <button
                        type="button"
                        onClick={() => setMenuOpen(!menuOpen)}
                        aria-haspopup="menu"
                        aria-expanded={menuOpen}
                        className="flex min-w-0 items-center gap-1 rounded px-1 text-lg font-bold hover:bg-white/10"
                    >
                        <span className="truncate">{workspace.name}</span>
                        <CaretIcon open className="h-3.5 w-3.5 shrink-0" />
                    </button>
                    <button
                        type="button"
                        onClick={onNewDm}
                        aria-label="New message"
                        title="New message"
                        className="shrink-0 rounded p-1 hover:bg-white/10"
                    >
                        <ComposeIcon className="h-5 w-5" />
                    </button>
                </div>

                {menuOpen && (
                    <WorkspaceMenu
                        workspace={workspace}
                        onClose={closeMenu}
                        onInvite={onInvite}
                        onCustomize={onCustomize}
                        onManageApps={onManageApps}
                        onManageMembers={onManageMembers}
                        onPreferences={onPreferences}
                        onTool={onTool}
                    />
                )}

                <div className="relative mt-2">
                    <SearchIcon className="pointer-events-none absolute left-2 top-1.5 h-4 w-4 text-white/60" />
                    <input
                        type="search"
                        aria-label="Find a conversation"
                        placeholder="Find a conversation…"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                        className="h-7 w-full rounded-md border border-white/20 bg-white/5 pl-7 pr-2 text-sm text-white placeholder-white/60 focus:border-white/40 focus:ring-0"
                    />
                </div>
            </div>

            <nav className="flex-1 space-y-3 overflow-y-auto px-2 pb-3">
                {view === 'dms' && (
                    <div>
                        <h2 className="px-2 py-1 text-[15px] font-semibold">Direct messages</h2>
                        {people.map(personRow)}
                    </div>
                )}

                {view === 'home' && (
                    <>
                        <div>
                            <button type="button" onClick={() => onPanel('threads')} className="block w-full">
                                <Row active={panel === 'threads'} unread={0}>
                                    <ThreadIcon className="h-4 w-4 shrink-0" /> Threads
                                </Row>
                            </button>
                            <button type="button" onClick={() => onPanel('drafts')} className="block w-full">
                                <Row active={panel === 'drafts'} unread={0}>
                                    <PencilIcon className="h-4 w-4 shrink-0" /> Drafts
                                </Row>
                            </button>
                            <button type="button" onClick={() => onPanel('scheduled')} className="block w-full">
                                <Row active={panel === 'scheduled'} unread={0}>
                                    <span aria-hidden className="w-4 shrink-0 text-center text-xs">🕒</span> Scheduled
                                </Row>
                            </button>
                            <button type="button" onClick={() => onPanel('saved')} className="block w-full">
                                <Row active={panel === 'saved'} unread={0}>
                                    <BookmarkIcon className="h-4 w-4 shrink-0" /> Saved items
                                </Row>
                            </button>
                        </div>

                        {starred.length > 0 && (
                            <Section
                                title="Starred"
                                collapsed={collapsed.includes('starred')}
                                onToggle={() => toggle('starred')}
                            >
                                {starred.map(channelLink)}
                            </Section>
                        )}

                        {sections.map((section) => (
                            <Section
                                key={section.id}
                                title={section.name}
                                collapsed={collapsed.includes(`section-${section.id}`)}
                                onToggle={() => toggle(`section-${section.id}`)}
                                onRemove={() => onRemoveSection(section.id)}
                            >
                                {named.filter((c) => !c.starred && c.section_id === section.id).map(channelLink)}
                            </Section>
                        ))}

                        <Section
                            title="Channels"
                            collapsed={collapsed.includes('channels')}
                            onToggle={() => toggle('channels')}
                            addLabel="Create a channel"
                            onAdd={onNewChannel}
                        >
                            {named.filter((c) => !c.starred && !c.section_id).map(channelLink)}
                            <button type="button" onClick={onNewChannel} className={action}>
                                <PlusIcon className="h-4 w-4" /> Add channels
                            </button>
                            <button type="button" onClick={() => onPanel('channels')} className={action}>
                                <SearchIcon className="h-4 w-4" /> Browse channels
                            </button>
                        </Section>

                        <Section
                            title="Direct messages"
                            collapsed={collapsed.includes('dms')}
                            onToggle={() => toggle('dms')}
                            addLabel="New direct message"
                            onAdd={onNewDm}
                        >
                            {self && matches(self.name) && personRow(self)}
                            {people.filter((p) => !dmByUser.get(p.id)?.starred).map(personRow)}
                            <button type="button" onClick={onInvite} className={action}>
                                <PlusIcon className="h-4 w-4" /> Invite people
                            </button>
                        </Section>
                    </>
                )}
            </nav>

        </aside>
    );
}
