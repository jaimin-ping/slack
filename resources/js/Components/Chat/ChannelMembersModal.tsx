import Avatar from '@/Components/Chat/Avatar';
import { LockIcon, PlusIcon, SearchIcon } from '@/Components/Chat/Icons';
import Modal from '@/Components/Modal';
import { ActiveChannel, WorkspaceInfo, WorkspaceMember } from '@/types/chat';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, ReactNode, useEffect, useMemo, useState } from 'react';

export type ChannelTab = 'about' | 'members';

type Props = {
    show: boolean;
    workspace: WorkspaceInfo;
    channel: ActiveChannel;
    meId: number;
    members: WorkspaceMember[];
    channelMemberIds: number[];
    online: Set<number>;
    initialTab?: ChannelTab;
    onOpenSettings: () => void;
    onClose: () => void;
};

type Field = 'name' | 'topic' | 'description';

const matches = (member: WorkspaceMember, query: string) => {
    const q = query.trim().toLowerCase();
    return q === '' || member.name.toLowerCase().includes(q) || (member.title ?? '').toLowerCase().includes(q);
};

const inputClass = 'w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600';
const secondaryBtn = 'rounded-md border border-slate-300 px-3 py-1 text-sm font-medium text-slate-700 hover:bg-slate-50';

/** One row of the About tab, like Slack's bordered cards. */
function AboutRow({
    label,
    value,
    empty,
    editable,
    editing,
    onEdit,
    children,
}: {
    label: string;
    value: ReactNode;
    empty: string;
    editable: boolean;
    editing: boolean;
    onEdit: () => void;
    children?: ReactNode;
}) {
    return (
        <div className="px-4 py-3">
            <div className="flex items-center justify-between">
                <p className="text-sm font-bold text-slate-900">{label}</p>
                {editable && !editing && (
                    <button type="button" onClick={onEdit} className="text-sm font-semibold text-sky-700 hover:underline">
                        Edit
                    </button>
                )}
            </div>
            {editing ? (
                children
            ) : (
                <div className="mt-0.5 whitespace-pre-wrap break-words text-sm text-slate-700">
                    {value || <span className="text-slate-500">{empty}</span>}
                </div>
            )}
        </div>
    );
}

/** Slack-style channel details: About, Members (with "Add people") and a link to Settings. */
export default function ChannelMembersModal({
    show,
    workspace,
    channel,
    meId,
    members,
    channelMemberIds,
    online,
    initialTab = 'members',
    onOpenSettings,
    onClose,
}: Props) {
    const [tab, setTab] = useState<ChannelTab>(initialTab);
    const [view, setView] = useState<'list' | 'add'>('list');
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<number[]>([]);
    const [saving, setSaving] = useState(false);
    const [editing, setEditing] = useState<Field | null>(null);
    const [draft, setDraft] = useState('');
    const [aboutError, setAboutError] = useState<string | null>(null);
    const [confirmPrivate, setConfirmPrivate] = useState(false);

    useEffect(() => {
        if (show) setTab(initialTab);
        setView('list');
        setQuery('');
        setSelected([]);
        setEditing(null);
        setAboutError(null);
        setConfirmPrivate(false);
    }, [show, initialTab]);

    const inChannel = useMemo(
        () => members.filter((m) => channelMemberIds.includes(m.id)).sort((a, b) => a.name.localeCompare(b.name)),
        [members, channelMemberIds],
    );
    const outside = useMemo(
        () => members.filter((m) => !channelMemberIds.includes(m.id)).sort((a, b) => a.name.localeCompare(b.name)),
        [members, channelMemberIds],
    );

    const url = { workspace: workspace.slug, channel: channel.id };
    const canAdd = !channel.archived && channel.type !== 'dm';
    // #general (can_leave false) keeps everyone; managers can remove from others.
    const canRemove = !!channel.can_manage && !!channel.can_leave;
    const partial = { preserveScroll: true, preserveState: true };

    const openAdd = () => {
        setView('add');
        setQuery('');
        setSelected([]);
    };

    const toggle = (id: number) => setSelected((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));

    const add = () => {
        if (selected.length === 0) return;
        setSaving(true);
        router.post(
            route('channels.members', url),
            { member_ids: selected },
            {
                ...partial,
                only: ['channelMemberIds', 'members'],
                onSuccess: () => {
                    setView('list');
                    setQuery('');
                    setSelected([]);
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const remove = (member: WorkspaceMember) =>
        router.delete(route('channels.members.remove', { ...url, user: member.id }), {
            ...partial,
            only: ['channelMemberIds', 'members'],
        });

    const startEdit = (field: Field) => {
        setEditing(field);
        setAboutError(null);
        setDraft((field === 'name' ? channel.name : channel[field]) ?? '');
    };

    const saveField = (e: FormEvent) => {
        e.preventDefault();
        if (!editing) return;
        router.patch(
            route('channels.update', url),
            { [editing]: draft.trim() || null },
            {
                ...partial,
                only: ['channel', 'sidebar'],
                onSuccess: () => setEditing(null),
                onError: (errors) => setAboutError(Object.values(errors)[0] ?? 'Not saved.'),
            },
        );
    };

    const makePrivate = () =>
        router.patch(
            route('channels.update', url),
            { make_private: true },
            { ...partial, only: ['channel', 'sidebar'], onSuccess: () => setConfirmPrivate(false) },
        );

    const leave = async () => {
        await axios.post(route('channels.leave', url));
        router.visit(route('workspaces.show', workspace.slug));
    };

    const editor = (multiline: boolean, max: number) => (
        <form onSubmit={saveField} className="mt-2 space-y-2">
            {multiline ? (
                <textarea autoFocus rows={3} maxLength={max} value={draft} onChange={(e) => setDraft(e.target.value)} className={inputClass} />
            ) : (
                <input autoFocus maxLength={max} value={draft} onChange={(e) => setDraft(e.target.value)} className={inputClass} />
            )}
            {aboutError && <p className="text-sm text-red-600">{aboutError}</p>}
            <div className="flex justify-end gap-2">
                <button type="button" onClick={() => setEditing(null)} className={secondaryBtn}>
                    Cancel
                </button>
                <button type="submit" className="rounded-md bg-teal-700 px-3 py-1 text-sm font-semibold text-white hover:bg-teal-800">
                    Save
                </button>
            </div>
        </form>
    );

    const created = channel.created_at
        ? new Date(channel.created_at).toLocaleDateString([], { month: 'long', day: 'numeric', year: 'numeric' })
        : null;

    const dot = (member: WorkspaceMember) =>
        online.has(member.id) && !member.is_away ? (
            <span className="h-2 w-2 shrink-0 rounded-full bg-emerald-500" title="Active" />
        ) : (
            <span className="h-2 w-2 shrink-0 rounded-full border border-slate-400" title="Away" />
        );

    const listed = (view === 'list' ? inChannel : outside).filter((m) => matches(m, query));
    const showPeople = view === 'add' || tab === 'members';

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <div className="flex max-h-[80vh] flex-col">
                <div className="flex items-center justify-between px-6 pb-2 pt-5">
                    <h2 className="flex min-w-0 items-center gap-1 text-xl font-bold text-slate-900">
                        {view === 'add' ? (
                            <span className="truncate">
                                Add people to {channel.type === 'private' ? '' : '#'}
                                {channel.name}
                            </span>
                        ) : (
                            <>
                                {channel.type === 'private' ? <LockIcon className="h-5 w-5 shrink-0" /> : '#'}
                                <span className="truncate">{channel.name}</span>
                            </>
                        )}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="rounded p-1 text-2xl leading-none text-slate-500 hover:bg-slate-100"
                    >
                        ×
                    </button>
                </div>

                {view === 'list' && (
                    <div role="tablist" className="flex gap-5 border-b border-slate-200 px-6 text-sm">
                        {(['about', 'members'] as const).map((name) => (
                            <button
                                key={name}
                                type="button"
                                role="tab"
                                aria-selected={tab === name}
                                onClick={() => setTab(name)}
                                className={`-mb-px border-b-2 py-2 font-semibold ${
                                    tab === name ? 'border-teal-700 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                {name === 'about' ? 'About' : `Members ${inChannel.length}`}
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => {
                                onClose();
                                onOpenSettings();
                            }}
                            className="-mb-px border-b-2 border-transparent py-2 font-semibold text-slate-500 hover:text-slate-800"
                        >
                            Settings
                        </button>
                    </div>
                )}

                {view === 'list' && tab === 'about' && (
                    <div className="space-y-3 overflow-y-auto bg-slate-50 px-6 py-4">
                        <div className="rounded-lg border border-slate-200 bg-white">
                            <AboutRow
                                label="Channel name"
                                value={`${channel.type === 'private' ? '🔒 ' : '# '}${channel.name ?? ''}`}
                                empty=""
                                editable={!!channel.can_manage && !channel.archived}
                                editing={editing === 'name'}
                                onEdit={() => startEdit('name')}
                            >
                                {editor(false, 60)}
                            </AboutRow>
                        </div>

                        <div className="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                            <AboutRow
                                label="Topic"
                                value={channel.topic}
                                empty="Add a topic"
                                editable={!channel.archived}
                                editing={editing === 'topic'}
                                onEdit={() => startEdit('topic')}
                            >
                                {editor(false, 250)}
                            </AboutRow>
                            <AboutRow
                                label="Description"
                                value={channel.description}
                                empty="Add a description"
                                editable={!channel.archived}
                                editing={editing === 'description'}
                                onEdit={() => startEdit('description')}
                            >
                                {editor(true, 500)}
                            </AboutRow>
                            <div className="px-4 py-3">
                                <p className="text-sm font-bold text-slate-900">Created by</p>
                                <p className="mt-0.5 text-sm text-slate-700">
                                    {channel.created_by_name ?? 'Someone'}
                                    {created && ` on ${created}`}
                                </p>
                            </div>
                            {channel.can_leave && (
                                <div className="px-4 py-3">
                                    <button type="button" onClick={leave} className="text-sm font-semibold text-red-600 hover:underline">
                                        Leave channel
                                    </button>
                                </div>
                            )}
                        </div>

                        {channel.type === 'public' && channel.can_manage && !channel.is_default && !channel.archived && (
                            <div className="rounded-lg border border-slate-200 bg-white px-4 py-3">
                                {confirmPrivate ? (
                                    <div className="space-y-2">
                                        <p className="text-sm text-slate-700">
                                            Only current members will see <b>#{channel.name}</b> and its history. This cannot be undone.
                                        </p>
                                        <div className="flex justify-end gap-2">
                                            <button type="button" onClick={() => setConfirmPrivate(false)} className={secondaryBtn}>
                                                Cancel
                                            </button>
                                            <button
                                                type="button"
                                                onClick={makePrivate}
                                                className="rounded-md bg-red-600 px-3 py-1 text-sm font-semibold text-white hover:bg-red-700"
                                            >
                                                Change to private
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => setConfirmPrivate(true)}
                                        className="flex items-center gap-2 text-sm font-semibold text-slate-800 hover:underline"
                                    >
                                        <LockIcon className="h-4 w-4" /> Change to a private channel
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                )}

                {showPeople && (
                    <>
                        <div className="px-6 pt-4">
                            <label className="flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 focus-within:border-teal-600 focus-within:ring-1 focus-within:ring-teal-600">
                                <SearchIcon className="h-4 w-4 text-slate-500" />
                                <input
                                    autoFocus
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder={view === 'add' ? 'Search by name' : 'Find people'}
                                    className="w-full border-0 p-0 text-sm focus:ring-0"
                                />
                            </label>
                            {view === 'add' && selected.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {outside
                                        .filter((m) => selected.includes(m.id))
                                        .map((m) => (
                                            <button
                                                key={m.id}
                                                type="button"
                                                onClick={() => toggle(m.id)}
                                                className="flex items-center gap-1 rounded bg-teal-50 px-2 py-0.5 text-xs text-teal-800 hover:bg-teal-100"
                                            >
                                                {m.name} <span aria-hidden>×</span>
                                            </button>
                                        ))}
                                </div>
                            )}
                        </div>

                        <ul className="mt-2 min-h-[12rem] flex-1 overflow-y-auto pb-2">
                            {view === 'list' && canAdd && query.trim() === '' && (
                                <li>
                                    <button
                                        type="button"
                                        onClick={openAdd}
                                        disabled={outside.length === 0}
                                        title={outside.length === 0 ? 'Everyone is already here' : undefined}
                                        className="flex w-full items-center gap-3 px-6 py-2 text-left text-sm font-semibold text-slate-800 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span className="flex h-9 w-9 items-center justify-center rounded-md bg-teal-50 text-teal-700">
                                            <PlusIcon className="h-5 w-5" />
                                        </span>
                                        Add people
                                    </button>
                                </li>
                            )}

                            {listed.length === 0 && (
                                <li className="px-6 py-6 text-center text-sm text-slate-500">
                                    {view === 'add' && outside.length === 0
                                        ? 'Everyone in this workspace is already in the channel.'
                                        : 'No matches found.'}
                                </li>
                            )}

                            {listed.map((member) => {
                                const row = (
                                    <>
                                        <Avatar user={member} className="h-9 w-9 rounded-md text-sm" />
                                        <span className="flex min-w-0 flex-1 items-center gap-2">
                                            <span className="truncate font-semibold text-slate-900">
                                                {member.name}
                                                {member.id === meId && <span className="font-normal text-slate-500"> (you)</span>}
                                            </span>
                                            {dot(member)}
                                            {member.status_emoji && <span title={member.status_text ?? undefined}>{member.status_emoji}</span>}
                                            {member.title && <span className="truncate text-slate-500">{member.title}</span>}
                                        </span>
                                    </>
                                );

                                if (view === 'add') {
                                    return (
                                        <li key={member.id}>
                                            <label className="flex cursor-pointer items-center gap-3 px-6 py-2 text-sm hover:bg-slate-50">
                                                <input
                                                    type="checkbox"
                                                    checked={selected.includes(member.id)}
                                                    onChange={() => toggle(member.id)}
                                                    className="rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                                                />
                                                {row}
                                            </label>
                                        </li>
                                    );
                                }

                                return (
                                    <li key={member.id} className="group flex items-center gap-3 px-6 py-2 text-sm hover:bg-slate-50">
                                        {row}
                                        {canRemove && member.id !== meId && (
                                            <button
                                                type="button"
                                                onClick={() => remove(member)}
                                                className="hidden shrink-0 rounded px-2 py-0.5 text-xs font-medium text-red-600 hover:bg-red-50 group-hover:block"
                                            >
                                                Remove
                                            </button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}

                {view === 'add' && (
                    <div className="flex justify-end gap-2 border-t border-slate-200 px-6 py-3">
                        <button
                            type="button"
                            onClick={() => setView('list')}
                            className="rounded-md border border-slate-300 px-4 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Back
                        </button>
                        <button
                            type="button"
                            onClick={add}
                            disabled={saving || selected.length === 0}
                            className="rounded-md bg-teal-700 px-4 py-1.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50"
                        >
                            Add{selected.length > 0 ? ` (${selected.length})` : ''}
                        </button>
                    </div>
                )}
            </div>
        </Modal>
    );
}
