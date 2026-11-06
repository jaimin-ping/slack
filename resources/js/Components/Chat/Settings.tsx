import Avatar from '@/Components/Chat/Avatar';
import { SsoConnect } from '@/Components/Chat/Security';
import Modal from '@/Components/Modal';
import { applyTheme, Theme } from '@/theme';
import { SidebarChannel, WorkspaceInfo, WorkspaceMember } from '@/types/chat';
import { router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { FormEvent, KeyboardEvent, useEffect, useMemo, useState } from 'react';

const field = 'w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600';
const primary = 'rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50';
const secondary = 'rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100';

interface ManagedMember {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'admin' | 'member';
}

export function MembersModal({
    show,
    workspace,
    meId,
    onClose,
}: {
    show: boolean;
    workspace: WorkspaceInfo;
    meId: number;
    onClose: () => void;
}) {
    const [members, setMembers] = useState<ManagedMember[] | null>(null);
    const [confirming, setConfirming] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = () =>
        axios
            .get<ManagedMember[]>(route('members.index', workspace.slug))
            .then(({ data }) => setMembers(data))
            .catch(() => setError('Could not load members.'));

    useEffect(() => {
        if (show) {
            setError(null);
            setConfirming(null);
            load();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, workspace.slug]);

    const changeRole = async (id: number, role: string) => {
        setError(null);
        try {
            await axios.patch(route('members.update', [workspace.slug, id]), { role });
            await load();
        } catch {
            setError('Role not changed. Try again.');
        }
    };

    const remove = async (id: number) => {
        setError(null);
        try {
            await axios.delete(route('members.destroy', [workspace.slug, id]));
            setConfirming(null);
            await load();
        } catch (e) {
            setError(
                isAxiosError(e) && e.response?.status === 403
                    ? 'Only the owner can remove an admin.'
                    : 'Person not removed. Try again.',
            );
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="xl">
            <div className="p-5">
                <h2 className="text-lg font-semibold text-slate-900">Manage members</h2>
                {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
                <ul className="mt-3 max-h-96 divide-y divide-slate-200 overflow-y-auto">
                    {members?.map((member) => (
                        <li key={member.id} className="flex items-center gap-3 py-2">
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-semibold text-slate-800">
                                    {member.name}
                                    {member.id === meId && ' (you)'}
                                </span>
                                <span className="block truncate text-xs text-slate-500">{member.email}</span>
                            </span>

                            {workspace.is_owner && member.role !== 'owner' ? (
                                <select
                                    aria-label={`Role of ${member.name}`}
                                    value={member.role}
                                    onChange={(e) => changeRole(member.id, e.target.value)}
                                    className="rounded-md border-slate-300 py-1 text-sm"
                                >
                                    <option value="member">Member</option>
                                    <option value="admin">Admin</option>
                                </select>
                            ) : (
                                <span className="text-sm capitalize text-slate-600">{member.role}</span>
                            )}

                            {member.role !== 'owner' &&
                                member.id !== meId &&
                                (confirming === member.id ? (
                                    <span className="flex shrink-0 gap-1">
                                        <button type="button" className={secondary} onClick={() => setConfirming(null)}>
                                            Keep
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => remove(member.id)}
                                            className="rounded-md bg-rose-700 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-800"
                                        >
                                            Remove
                                        </button>
                                    </span>
                                ) : (
                                    <button type="button" className={secondary} onClick={() => setConfirming(member.id)}>
                                        Remove
                                    </button>
                                ))}
                        </li>
                    ))}
                </ul>
                <p className="mt-3 text-xs text-slate-500">
                    Removing someone takes away this workspace. Their past messages stay.
                </p>
                <div className="mt-4 flex justify-end">
                    <button type="button" className={secondary} onClick={onClose}>
                        Done
                    </button>
                </div>
            </div>
        </Modal>
    );
}

export interface PreferenceValues {
    theme?: Theme;
    title?: string | null;
    phone?: string | null;
    dnd_from?: string | null;
    dnd_to?: string | null;
    dnd_until?: string | null;
    two_factor_enabled?: boolean;
}

const PAUSES = [
    [30, '30 minutes'],
    [60, '1 hour'],
    [120, '2 hours'],
    [480, '8 hours'],
    [1440, '24 hours'],
] as const;

export function PreferencesModal({
    show,
    user,
    onClose,
    onTwoFactor,
    onMyData,
}: {
    show: boolean;
    user: PreferenceValues;
    onClose: () => void;
    onTwoFactor: () => void;
    onMyData: () => void;
}) {
    const [theme, setTheme] = useState<Theme>(user.theme ?? 'system');
    const [title, setTitle] = useState(user.title ?? '');
    const [phone, setPhone] = useState(user.phone ?? '');
    const [from, setFrom] = useState(user.dnd_from ?? '');
    const [to, setTo] = useState(user.dnd_to ?? '');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (show) {
            setTheme(user.theme ?? 'system');
            setTitle(user.title ?? '');
            setPhone(user.phone ?? '');
            setFrom(user.dnd_from ?? '');
            setTo(user.dnd_to ?? '');
            setError(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show]);

    const refresh = () => router.reload({ only: ['auth', 'members', 'channelMemberIds'] });

    const save = async (e: FormEvent) => {
        e.preventDefault();

        if (Boolean(from) !== Boolean(to)) {
            setError('Choose both a start and an end time for the daily schedule.');
            return;
        }

        setSaving(true);
        setError(null);
        try {
            await axios.put(route('preferences.update'), {
                theme,
                title: title.trim() || null,
                phone: phone.trim() || null,
                dnd_from: from || null,
                dnd_to: to || null,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            });
            applyTheme(theme);
            refresh();
            onClose();
        } catch {
            setError('Preferences not saved. Try again.');
        } finally {
            setSaving(false);
        }
    };

    const pause = async (minutes: number) => {
        await axios.post(route('preferences.pause'), { minutes });
        refresh();
    };

    const paused = user.dnd_until && new Date(user.dnd_until) > new Date() ? new Date(user.dnd_until) : null;

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form onSubmit={save} className="space-y-5 p-5">
                <h2 className="text-lg font-semibold text-slate-900">Preferences</h2>

                <fieldset>
                    <legend className="mb-1 text-sm font-medium text-slate-700">Appearance</legend>
                    <div className="flex gap-4">
                        {(['light', 'dark', 'system'] as const).map((option) => (
                            <label key={option} className="flex items-center gap-1.5 text-sm capitalize text-slate-700">
                                <input
                                    type="radio"
                                    name="theme"
                                    className="border-slate-300 text-teal-700 focus:ring-teal-600"
                                    checked={theme === option}
                                    onChange={() => setTheme(option)}
                                />
                                {option}
                            </label>
                        ))}
                    </div>
                </fieldset>

                <div>
                    <p className="mb-1 text-sm font-medium text-slate-700">Pause notifications</p>
                    {paused ? (
                        <p className="flex items-center justify-between gap-2 text-sm text-slate-700">
                            Paused until {paused.toLocaleString([], { hour: 'numeric', minute: '2-digit', month: 'short', day: 'numeric' })}
                            <button type="button" className={secondary} onClick={() => pause(0)}>
                                Resume
                            </button>
                        </p>
                    ) : (
                        <div className="flex flex-wrap gap-1">
                            {PAUSES.map(([minutes, label]) => (
                                <button
                                    key={minutes}
                                    type="button"
                                    onClick={() => pause(minutes)}
                                    className="rounded-md border border-slate-300 px-2 py-1 text-sm text-slate-700 hover:bg-slate-50"
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                <div>
                    <p className="mb-1 text-sm font-medium text-slate-700">Do Not Disturb every day</p>
                    <div className="flex items-center gap-2 text-sm text-slate-700">
                        <input aria-label="Start time" type="time" className={field} value={from} onChange={(e) => setFrom(e.target.value)} />
                        to
                        <input aria-label="End time" type="time" className={field} value={to} onChange={(e) => setTo(e.target.value)} />
                    </div>
                </div>

                <div className="grid gap-2 sm:grid-cols-2">
                    <label className="text-sm font-medium text-slate-700">
                        Job title
                        <input className={`${field} mt-1`} maxLength={80} value={title} onChange={(e) => setTitle(e.target.value)} />
                    </label>
                    <label className="text-sm font-medium text-slate-700">
                        Phone
                        <input className={`${field} mt-1`} maxLength={30} value={phone} onChange={(e) => setPhone(e.target.value)} />
                    </label>
                </div>
                <p className="text-xs text-slate-500">Everyone in your workspaces can see your job title and phone.</p>

                <div>
                    <p className="mb-1 text-sm font-medium text-slate-700">Security and data</p>
                    <div className="flex flex-wrap gap-1">
                        <button
                            type="button"
                            onClick={onTwoFactor}
                            className="rounded-md border border-slate-300 px-2 py-1 text-sm text-slate-700 hover:bg-slate-50"
                        >
                            Two-step sign in: {user.two_factor_enabled ? 'On' : 'Off'}
                        </button>
                        <button
                            type="button"
                            onClick={onMyData}
                            className="rounded-md border border-slate-300 px-2 py-1 text-sm text-slate-700 hover:bg-slate-50"
                        >
                            Download my data
                        </button>
                        <SsoConnect />
                    </div>
                </div>

                {error && <p className="text-sm text-red-600">{error}</p>}

                <div className="flex justify-end gap-2">
                    <button type="button" className={secondary} onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" className={primary} disabled={saving}>
                        Save
                    </button>
                </div>
            </form>
        </Modal>
    );
}

export function QuickSwitcher({
    show,
    workspace,
    channels,
    people,
    onClose,
}: {
    show: boolean;
    workspace: WorkspaceInfo;
    channels: SidebarChannel[];
    people: WorkspaceMember[];
    onClose: () => void;
}) {
    const [term, setTerm] = useState('');
    const [index, setIndex] = useState(0);

    const items = useMemo(() => {
        const named = channels
            .filter((c) => c.type !== 'dm')
            .map((c) => ({ key: `c${c.id}`, label: `${c.type === 'private' ? '🔒' : '#'} ${c.name}`, channelId: c.id, person: null as WorkspaceMember | null }));
        const direct = people.map((person) => ({
            key: `p${person.id}`,
            label: person.name,
            channelId: channels.find((c) => c.dm_user_id === person.id)?.id ?? null,
            person,
        }));

        return [...named, ...direct].filter((item) => item.label.toLowerCase().includes(term.trim().toLowerCase())).slice(0, 8);
    }, [channels, people, term]);

    useEffect(() => {
        if (show) {
            setTerm('');
            setIndex(0);
        }
    }, [show]);

    const open = (item: (typeof items)[number]) => {
        onClose();

        if (item.channelId) {
            router.visit(route('channels.show', [workspace.slug, item.channelId]));
        } else if (item.person) {
            router.post(route('channels.direct', [workspace.slug, item.person.id]));
        }
    };

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            setIndex((current) => (current + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % Math.max(items.length, 1));
        }
        if (e.key === 'Enter' && items[index]) {
            e.preventDefault();
            open(items[index]);
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="p-3">
                <input
                    aria-label="Jump to a conversation"
                    autoFocus
                    className={field}
                    placeholder="Jump to…"
                    value={term}
                    onChange={(e) => {
                        setTerm(e.target.value);
                        setIndex(0);
                    }}
                    onKeyDown={onKeyDown}
                />
                <ul className="mt-2">
                    {items.map((item, position) => (
                        <li key={item.key}>
                            <button
                                type="button"
                                onClick={() => open(item)}
                                className={`flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm ${
                                    position === index ? 'bg-sky-700 text-white' : 'text-slate-800'
                                }`}
                            >
                                {item.person && <Avatar user={item.person} className="h-5 w-5 rounded text-xs" />}
                                <span className="truncate">{item.label}</span>
                            </button>
                        </li>
                    ))}
                </ul>
                {items.length === 0 && <p className="p-2 text-sm text-slate-500">No matches.</p>}
            </div>
        </Modal>
    );
}

const SHORTCUTS = [
    ['Ctrl + K', 'Jump to a conversation'],
    ['Alt + ↑ / ↓', 'Previous or next conversation'],
    ['Ctrl + /', 'Show these shortcuts'],
    ['Ctrl + B / I / U', 'Bold, italic, underline'],
    ['Enter', 'Send a message'],
    ['Shift + Enter', 'New line'],
    ['Esc', 'Close a thread or window'],
];

export function ShortcutsModal({ show, onClose }: { show: boolean; onClose: () => void }) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="p-5">
                <h2 className="mb-3 text-lg font-semibold text-slate-900">Keyboard shortcuts</h2>
                <dl className="space-y-1.5">
                    {SHORTCUTS.map(([keys, action]) => (
                        <div key={keys} className="flex items-center justify-between gap-4 text-sm">
                            <dt className="text-slate-700">{action}</dt>
                            <dd className="rounded border border-slate-300 bg-slate-50 px-2 py-0.5 font-mono text-xs text-slate-700">{keys}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </Modal>
    );
}
