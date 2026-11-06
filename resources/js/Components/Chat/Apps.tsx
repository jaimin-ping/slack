import Modal from '@/Components/Modal';
import { ChatMessage, ChatUser, SidebarChannel, WorkspaceInfo } from '@/types/chat';
import axios, { isAxiosError } from 'axios';
import { FormEvent, useEffect, useState } from 'react';

interface Webhook {
    id: number;
    name: string;
    channel_id: number;
    channel_name: string;
    last_used_at: string | null;
}

const field = 'w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600';
const primary = 'rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50';
const secondary = 'rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100';

export function ManageAppsModal({
    show,
    workspace,
    channels,
    onClose,
}: {
    show: boolean;
    workspace: WorkspaceInfo;
    channels: SidebarChannel[];
    onClose: () => void;
}) {
    const targets = channels.filter((c) => c.type !== 'dm');
    const [webhooks, setWebhooks] = useState<Webhook[] | null>(null);
    const [name, setName] = useState('');
    const [channelId, setChannelId] = useState<number | ''>('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [created, setCreated] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const [confirming, setConfirming] = useState<number | null>(null);

    useEffect(() => {
        if (!show) return;

        setCreated(null);
        setError(null);
        setConfirming(null);
        axios
            .get<Webhook[]>(route('webhooks.index', workspace.slug))
            .then(({ data }) => setWebhooks(data))
            .catch(() => setError('Could not load webhooks.'));
    }, [show, workspace.slug]);

    const create = async (e: FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setError(null);
        try {
            const { data } = await axios.post<{ webhook: Webhook; url: string }>(route('webhooks.store', workspace.slug), {
                name,
                channel_id: channelId,
            });
            setWebhooks((current) => [data.webhook, ...(current ?? [])]);
            setCreated(data.url);
            setCopied(false);
            setName('');
            setChannelId('');
        } catch (err) {
            setError((isAxiosError<{ message?: string }>(err) && err.response?.data?.message) || 'Webhook not created.');
        } finally {
            setSaving(false);
        }
    };

    const remove = async (id: number) => {
        try {
            await axios.delete(route('webhooks.destroy', [workspace.slug, id]));
            setWebhooks((current) => (current ?? []).filter((w) => w.id !== id));
        } catch {
            setError('Webhook not removed. Try again.');
        }
        setConfirming(null);
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="xl">
            <div className="p-5">
                <h2 className="text-lg font-semibold text-slate-900">Manage apps</h2>
                <p className="mb-4 mt-1 text-sm text-slate-600">
                    An incoming webhook lets another system post to a channel. It accepts the Slack webhook format.
                </p>

                {created && (
                    <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3">
                        <p className="mb-2 text-sm font-semibold text-amber-900">
                            Copy this URL now. It will not be shown again, and anyone who has it can post to the channel.
                        </p>
                        <div className="flex gap-2">
                            <input
                                readOnly
                                aria-label="Webhook URL"
                                value={created}
                                onFocus={(e) => e.target.select()}
                                className="min-w-0 flex-1 rounded-md border-slate-300 text-sm"
                            />
                            <button
                                type="button"
                                className={primary}
                                onClick={async () => {
                                    await navigator.clipboard.writeText(created);
                                    setCopied(true);
                                }}
                            >
                                {copied ? 'Copied' : 'Copy'}
                            </button>
                        </div>
                    </div>
                )}

                <form onSubmit={create} className="mb-4 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                    <input
                        aria-label="Webhook name"
                        className={field}
                        maxLength={80}
                        required
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="Name, for example Ping Prepay"
                    />
                    <select
                        aria-label="Channel"
                        className={field}
                        required
                        value={channelId}
                        onChange={(e) => setChannelId(Number(e.target.value))}
                    >
                        <option value="" disabled>
                            Choose a channel
                        </option>
                        {targets.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.type === 'private' ? '🔒 ' : '# '}
                                {c.name}
                            </option>
                        ))}
                    </select>
                    <button type="submit" className={primary} disabled={saving}>
                        Add
                    </button>
                </form>

                {error && <p className="mb-3 text-sm text-red-600">{error}</p>}

                {webhooks?.length === 0 && <p className="text-sm text-slate-500">No webhooks yet.</p>}

                <ul className="divide-y divide-slate-200">
                    {webhooks?.map((webhook) => (
                        <li key={webhook.id} className="flex items-center justify-between gap-3 py-2">
                            <span className="min-w-0">
                                <span className="block truncate text-sm font-semibold text-slate-800">{webhook.name}</span>
                                <span className="block truncate text-xs text-slate-500">
                                    #{webhook.channel_name} ·{' '}
                                    {webhook.last_used_at
                                        ? `Last used ${new Date(webhook.last_used_at).toLocaleString()}`
                                        : 'Never used'}
                                </span>
                            </span>
                            {confirming === webhook.id ? (
                                <span className="flex shrink-0 gap-1">
                                    <button type="button" className={secondary} onClick={() => setConfirming(null)}>
                                        Keep
                                    </button>
                                    <button
                                        type="button"
                                        className="rounded-md bg-rose-700 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-800"
                                        onClick={() => remove(webhook.id)}
                                    >
                                        Remove
                                    </button>
                                </span>
                            ) : (
                                <button type="button" className={secondary} onClick={() => setConfirming(webhook.id)}>
                                    Remove
                                </button>
                            )}
                        </li>
                    ))}
                </ul>

                <div className="mt-4 flex justify-end">
                    <button type="button" className={secondary} onClick={onClose}>
                        Done
                    </button>
                </div>
            </div>
        </Modal>
    );
}

export function DeleteMessageModal({
    message,
    onCancel,
    onConfirm,
}: {
    message: ChatMessage | null;
    onCancel: () => void;
    onConfirm: () => Promise<void>;
}) {
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => setFailed(false), [message]);

    const confirm = async () => {
        setBusy(true);
        setFailed(false);
        try {
            await onConfirm();
        } catch {
            setFailed(true);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal show={message !== null} onClose={onCancel} maxWidth="md">
            <div className="p-5">
                <h2 className="text-lg font-semibold text-slate-900">Delete message</h2>
                <p className="mt-1 text-sm text-slate-600">This can’t be undone. Any attached files are deleted too.</p>
                {message?.body && (
                    <p className="mt-3 max-h-32 overflow-hidden whitespace-pre-wrap break-words rounded-md border border-slate-200 bg-slate-50 p-2 text-sm text-slate-700">
                        {message.body}
                    </p>
                )}
                {failed && <p className="mt-2 text-sm text-red-600">Message not deleted. Try again.</p>}
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" className={secondary} onClick={onCancel}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={confirm}
                        disabled={busy}
                        className="rounded-md bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800 disabled:opacity-50"
                    >
                        Delete
                    </button>
                </div>
            </div>
        </Modal>
    );
}

export function ForwardModal({
    message,
    workspace,
    fromChannelId,
    channels,
    members,
    onClose,
}: {
    message: ChatMessage | null;
    workspace: WorkspaceInfo;
    fromChannelId: number;
    channels: SidebarChannel[];
    members: ChatUser[];
    onClose: () => void;
}) {
    const [target, setTarget] = useState<number | ''>('');
    const [comment, setComment] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        setTarget('');
        setComment('');
        setError(null);
    }, [message]);

    const label = (channel: SidebarChannel) =>
        channel.type === 'dm'
            ? (members.find((m) => m.id === channel.dm_user_id)?.name ?? 'Direct message')
            : `${channel.type === 'private' ? '🔒 ' : '# '}${channel.name}`;

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!message) return;

        setBusy(true);
        setError(null);
        try {
            await axios.post(route('messages.forward', [workspace.slug, fromChannelId, message.id]), {
                channel_id: target,
                comment,
            });
            onClose();
        } catch {
            setError('Message not forwarded. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal show={message !== null} onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="p-5">
                <h2 className="mb-3 text-lg font-semibold text-slate-900">Forward message</h2>
                <select
                    aria-label="Forward to"
                    required
                    className={field}
                    value={target}
                    onChange={(e) => setTarget(Number(e.target.value))}
                >
                    <option value="" disabled>
                        Choose a conversation
                    </option>
                    {channels.map((channel) => (
                        <option key={channel.id} value={channel.id}>
                            {label(channel)}
                        </option>
                    ))}
                </select>
                <textarea
                    aria-label="Add a comment"
                    className={`${field} mt-2`}
                    maxLength={1000}
                    rows={2}
                    placeholder="Add a comment (optional)"
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                />
                {message?.body && (
                    <p className="mt-2 max-h-24 overflow-hidden whitespace-pre-wrap break-words border-l-4 border-slate-300 pl-3 text-sm text-slate-600">
                        {message.body}
                    </p>
                )}
                {(message?.attachments.length ?? 0) > 0 && (
                    <p className="mt-2 text-xs text-slate-500">Attached files are not forwarded.</p>
                )}
                {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
                <div className="mt-4 flex justify-end gap-2">
                    <button type="button" className={secondary} onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" className={primary} disabled={busy}>
                        Forward
                    </button>
                </div>
            </form>
        </Modal>
    );
}
