import Avatar from '@/Components/Chat/Avatar';
import Modal from '@/Components/Modal';
import { COMPOSER_EMOJIS, WorkspaceInfo, WorkspaceMember } from '@/types/chat';
import { router, useForm } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { FormEvent, useEffect, useRef, useState } from 'react';

const field =
    'w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600';
const primary =
    'rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50';
const secondary =
    'rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100';

export function StatusModal({
    show,
    me,
    onClose,
}: {
    show: boolean;
    me: WorkspaceMember;
    onClose: () => void;
}) {
    const [emoji, setEmoji] = useState<string | null>(me.status_emoji);
    const [text, setText] = useState(me.status_text ?? '');
    const [expiresIn, setExpiresIn] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (show) {
            setEmoji(me.status_emoji);
            setText(me.status_text ?? '');
            setError(false);
        }
    }, [show, me.status_emoji, me.status_text]);

    const save = async (status_emoji: string | null, status_text: string) => {
        setSaving(true);
        setError(false);
        try {
            await axios.patch(route('status.update'), {
                status_emoji,
                status_text: status_text.trim() || null,
                expires_in: expiresIn ? Number(expiresIn) : null,
            });
            router.reload({ only: ['members', 'channelMemberIds'] });
            onClose();
        } catch {
            setError(true);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form
                className="p-5"
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    save(emoji, text);
                }}
            >
                <h2 className="mb-3 text-lg font-semibold text-slate-900">
                    Set a status
                </h2>
                <div className="mb-3 flex flex-wrap gap-0.5">
                    {COMPOSER_EMOJIS.map((option) => (
                        <button
                            key={option}
                            type="button"
                            aria-label={`Use ${option}`}
                            aria-pressed={emoji === option}
                            onClick={() =>
                                setEmoji(emoji === option ? null : option)
                            }
                            className={`rounded p-1 text-lg leading-none ${emoji === option ? 'bg-teal-100' : 'hover:bg-slate-100'}`}
                        >
                            {option}
                        </button>
                    ))}
                </div>
                <input
                    aria-label="Status text"
                    className={field}
                    maxLength={100}
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    placeholder="What’s your status?"
                />
                <select
                    aria-label="Clear status after"
                    className={`${field} mt-2`}
                    value={expiresIn}
                    onChange={(e) => setExpiresIn(e.target.value)}
                >
                    <option value="">Don’t clear</option>
                    <option value="30">Clear after 30 minutes</option>
                    <option value="60">Clear after 1 hour</option>
                    <option value="240">Clear after 4 hours</option>
                    <option value="1440">Clear after 1 day</option>
                </select>
                {error && (
                    <p className="mt-1 text-sm text-red-600">
                        Status not saved. Try again.
                    </p>
                )}
                <div className="mt-5 flex justify-end gap-2">
                    {(me.status_emoji || me.status_text) && (
                        <button
                            type="button"
                            className={`${secondary} mr-auto`}
                            onClick={() => save(null, '')}
                        >
                            Clear status
                        </button>
                    )}
                    <button
                        type="button"
                        className={secondary}
                        onClick={onClose}
                    >
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

const MAX_PHOTO_BYTES = 2 * 1024 * 1024;

export function PhotoModal({
    show,
    me,
    onClose,
}: {
    show: boolean;
    me: WorkspaceMember;
    onClose: () => void;
}) {
    const picker = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (show) setError(null);
    }, [show]);

    const refresh = () =>
        router.reload({ only: ['members', 'channelMemberIds'] });

    const upload = async (file: File) => {
        if (file.size > MAX_PHOTO_BYTES) {
            setError('Choose a photo smaller than 2 MB.');
            return;
        }

        const form = new FormData();
        form.append('photo', file);
        setBusy(true);
        setError(null);
        try {
            await axios.post(route('avatar.store'), form);
            refresh();
        } catch (e) {
            setError(
                (isAxiosError<{ message?: string }>(e) &&
                    e.response?.data?.message) ||
                    'Photo not uploaded. Try again.',
            );
        } finally {
            setBusy(false);
        }
    };

    const remove = async () => {
        setBusy(true);
        setError(null);
        try {
            await axios.delete(route('avatar.destroy'));
            refresh();
        } catch {
            setError('Photo not removed. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="sm">
            <div className="p-5">
                <h2 className="mb-4 text-lg font-semibold text-slate-900">
                    Profile photo
                </h2>
                <div className="flex items-center gap-4">
                    <Avatar
                        user={me}
                        className="h-20 w-20 rounded-xl text-3xl"
                    />
                    <div>
                        <input
                            ref={picker}
                            type="file"
                            hidden
                            aria-label="Choose a photo"
                            accept="image/png,image/jpeg,image/webp"
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                e.target.value = '';
                                if (file) upload(file);
                            }}
                        />
                        <button
                            type="button"
                            className={primary}
                            disabled={busy}
                            onClick={() => picker.current?.click()}
                        >
                            {busy ? 'Saving…' : 'Choose photo'}
                        </button>
                        <p className="mt-2 text-xs text-slate-500">
                            PNG, JPG or WebP, up to 2 MB.
                        </p>
                    </div>
                </div>
                {error && <p className="mt-3 text-sm text-red-600">{error}</p>}
                <div className="mt-5 flex justify-end gap-2">
                    {me.avatar_url && (
                        <button
                            type="button"
                            className={`${secondary} mr-auto`}
                            disabled={busy}
                            onClick={remove}
                        >
                            Remove photo
                        </button>
                    )}
                    <button
                        type="button"
                        className={secondary}
                        onClick={onClose}
                    >
                        Done
                    </button>
                </div>
            </div>
        </Modal>
    );
}

export function CustomizeModal({
    show,
    workspace,
    onClose,
}: {
    show: boolean;
    workspace: WorkspaceInfo;
    onClose: () => void;
}) {
    const form = useForm({ name: workspace.name });
    const removal = useForm({ confirm_name: '' });

    useEffect(() => {
        if (show) {
            form.setData('name', workspace.name);
            removal.reset();
            removal.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, workspace.name]);

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form
                className="p-5"
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    form.patch(route('workspaces.update', workspace.slug), {
                        preserveScroll: true,
                        onSuccess: onClose,
                    });
                }}
            >
                <h2 className="mb-3 text-lg font-semibold text-slate-900">
                    Customize workspace
                </h2>
                <label
                    htmlFor="workspace-rename"
                    className="mb-1 block text-sm font-medium text-slate-700"
                >
                    Workspace name
                </label>
                <input
                    id="workspace-rename"
                    className={field}
                    maxLength={80}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    required
                />
                {form.errors.name && (
                    <p className="mt-1 text-sm text-red-600">
                        {form.errors.name}
                    </p>
                )}
                <div className="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        className={secondary}
                        onClick={onClose}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        className={primary}
                        disabled={form.processing}
                    >
                        Save
                    </button>
                </div>
            </form>

            {workspace.is_owner && (
                <form
                    className="border-t border-slate-200 p-5"
                    onSubmit={(e: FormEvent) => {
                        e.preventDefault();
                        removal.delete(
                            route('workspaces.destroy', workspace.slug),
                        );
                    }}
                >
                    <h3 className="text-sm font-semibold text-rose-800">
                        Delete this workspace
                    </h3>
                    <p className="mb-2 mt-1 text-sm text-slate-600">
                        This deletes every channel, message and file for
                        everyone. It can’t be undone. Type{' '}
                        <b>{workspace.name}</b> to confirm.
                    </p>
                    <div className="flex gap-2">
                        <input
                            aria-label="Type the workspace name to confirm"
                            className={field}
                            value={removal.data.confirm_name}
                            onChange={(e) =>
                                removal.setData('confirm_name', e.target.value)
                            }
                        />
                        <button
                            type="submit"
                            disabled={
                                removal.processing ||
                                removal.data.confirm_name !== workspace.name
                            }
                            className="shrink-0 rounded-md bg-rose-700 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-800 disabled:opacity-40"
                        >
                            Delete
                        </button>
                    </div>
                    {removal.errors.confirm_name && (
                        <p className="mt-1 text-sm text-red-600">
                            {removal.errors.confirm_name}
                        </p>
                    )}
                </form>
            )}
        </Modal>
    );
}

export function NewDmModal({
    show,
    workspace,
    people,
    onClose,
    onInvite,
}: {
    show: boolean;
    workspace: WorkspaceInfo;
    people: WorkspaceMember[];
    onClose: () => void;
    onInvite: () => void;
}) {
    const [term, setTerm] = useState('');
    const matches = people.filter((p) =>
        p.name.toLowerCase().includes(term.trim().toLowerCase()),
    );

    const start = (id: number) => {
        onClose();
        setTerm('');
        router.post(route('channels.direct', [workspace.slug, id]));
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="p-5">
                <h2 className="mb-3 text-lg font-semibold text-slate-900">
                    New message
                </h2>
                <input
                    aria-label="Find a person"
                    className={field}
                    value={term}
                    onChange={(e) => setTerm(e.target.value)}
                    placeholder="Type a name"
                />
                <ul className="mt-2 max-h-64 overflow-y-auto">
                    {matches.map((person) => (
                        <li key={person.id}>
                            <button
                                type="button"
                                onClick={() => start(person.id)}
                                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-slate-800 hover:bg-slate-100"
                            >
                                <Avatar
                                    user={person}
                                    className="h-6 w-6 rounded text-xs"
                                />
                                <span className="truncate">{person.name}</span>
                                {person.status_emoji && (
                                    <span>{person.status_emoji}</span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
                {people.length === 0 && (
                    <p className="mt-3 text-sm text-slate-600">
                        Nobody else is here yet.{' '}
                        <button
                            type="button"
                            className="text-sky-700 hover:underline"
                            onClick={() => {
                                onClose();
                                onInvite();
                            }}
                        >
                            Invite people
                        </button>
                    </p>
                )}
                {people.length > 0 && matches.length === 0 && (
                    <p className="mt-3 text-sm text-slate-500">
                        No one matches “{term.trim()}”.
                    </p>
                )}
            </div>
        </Modal>
    );
}
