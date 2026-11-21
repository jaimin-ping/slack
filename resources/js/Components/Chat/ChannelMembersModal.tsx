import Avatar from '@/Components/Chat/Avatar';
import { LockIcon, PlusIcon, SearchIcon } from '@/Components/Chat/Icons';
import Modal from '@/Components/Modal';
import { ActiveChannel, WorkspaceInfo, WorkspaceMember } from '@/types/chat';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

type Props = {
    show: boolean;
    workspace: WorkspaceInfo;
    channel: ActiveChannel;
    meId: number;
    members: WorkspaceMember[];
    channelMemberIds: number[];
    online: Set<number>;
    onClose: () => void;
};

const matches = (member: WorkspaceMember, query: string) => {
    const q = query.trim().toLowerCase();
    return (
        q === '' ||
        member.name.toLowerCase().includes(q) ||
        (member.title ?? '').toLowerCase().includes(q)
    );
};

/** Slack-style channel member list with "Add people". */
export default function ChannelMembersModal({
    show,
    workspace,
    channel,
    meId,
    members,
    channelMemberIds,
    online,
    onClose,
}: Props) {
    const [view, setView] = useState<'list' | 'add'>('list');
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<number[]>([]);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!show) {
            setView('list');
            setQuery('');
            setSelected([]);
        }
    }, [show]);

    const inChannel = useMemo(
        () =>
            members
                .filter((m) => channelMemberIds.includes(m.id))
                .sort((a, b) => a.name.localeCompare(b.name)),
        [members, channelMemberIds],
    );
    const outside = useMemo(
        () =>
            members
                .filter((m) => !channelMemberIds.includes(m.id))
                .sort((a, b) => a.name.localeCompare(b.name)),
        [members, channelMemberIds],
    );

    const url = { workspace: workspace.slug, channel: channel.id };
    const canAdd = !channel.archived && channel.type !== 'dm';
    // #general (can_leave false) keeps everyone; managers can remove from others.
    const canRemove = !!channel.can_manage && !!channel.can_leave;

    const openAdd = () => {
        setView('add');
        setQuery('');
        setSelected([]);
    };

    const toggle = (id: number) =>
        setSelected((ids) =>
            ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id],
        );

    const add = () => {
        if (selected.length === 0) return;
        setSaving(true);
        router.post(
            route('channels.members', url),
            { member_ids: selected },
            {
                preserveScroll: true,
                preserveState: true,
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

    const remove = (member: WorkspaceMember) => {
        router.delete(
            route('channels.members.remove', { ...url, user: member.id }),
            {
                preserveScroll: true,
                preserveState: true,
                only: ['channelMemberIds', 'members'],
            },
        );
    };

    const dot = (member: WorkspaceMember) =>
        online.has(member.id) && !member.is_away ? (
            <span
                className="h-2 w-2 shrink-0 rounded-full bg-emerald-500"
                title="Active"
            />
        ) : (
            <span
                className="h-2 w-2 shrink-0 rounded-full border border-slate-400"
                title="Away"
            />
        );

    const listed = (view === 'list' ? inChannel : outside).filter((m) =>
        matches(m, query),
    );

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <div className="flex max-h-[80vh] flex-col">
                <div className="flex items-center justify-between px-6 pb-2 pt-5">
                    <h2 className="flex items-center gap-1 text-xl font-bold text-slate-900">
                        {view === 'add' ? (
                            <>Add people to {channel.type === 'private' ? '' : '#'}{channel.name}</>
                        ) : (
                            <>
                                {channel.type === 'private' ? (
                                    <LockIcon className="h-5 w-5" />
                                ) : (
                                    '#'
                                )}
                                {channel.name}
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
                    <div className="border-b border-slate-200 px-6">
                        <span className="-mb-px inline-block border-b-2 border-teal-700 py-2 text-sm font-semibold text-slate-900">
                            Members {inChannel.length}
                        </span>
                    </div>
                )}

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
                        const isSelected = selected.includes(member.id);
                        const row = (
                            <>
                                <Avatar user={member} className="h-9 w-9 rounded-md text-sm" />
                                <span className="flex min-w-0 flex-1 items-center gap-2">
                                    <span className="truncate font-semibold text-slate-900">
                                        {member.name}
                                        {member.id === meId && (
                                            <span className="font-normal text-slate-500"> (you)</span>
                                        )}
                                    </span>
                                    {dot(member)}
                                    {member.status_emoji && (
                                        <span title={member.status_text ?? undefined}>
                                            {member.status_emoji}
                                        </span>
                                    )}
                                    {member.title && (
                                        <span className="truncate text-slate-500">{member.title}</span>
                                    )}
                                </span>
                            </>
                        );

                        if (view === 'add') {
                            return (
                                <li key={member.id}>
                                    <label className="flex cursor-pointer items-center gap-3 px-6 py-2 text-sm hover:bg-slate-50">
                                        <input
                                            type="checkbox"
                                            checked={isSelected}
                                            onChange={() => toggle(member.id)}
                                            className="rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                                        />
                                        {row}
                                    </label>
                                </li>
                            );
                        }

                        return (
                            <li
                                key={member.id}
                                className="group flex items-center gap-3 px-6 py-2 text-sm hover:bg-slate-50"
                            >
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
