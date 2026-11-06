import Modal from '@/Components/Modal';
import { WorkspaceInfo } from '@/types/chat';
import axios from 'axios';
import { FormEvent, useCallback, useEffect, useState } from 'react';

interface Props {
    show: boolean;
    workspace: WorkspaceInfo;
    onClose: () => void;
}

export default function InviteModal({ show, workspace, onClose }: Props) {
    const [emails, setEmails] = useState('');
    const [result, setResult] = useState<string | null>(null);
    const [pending, setPending] = useState<{ id: number; email: string }[]>([]);

    const loadPending = useCallback(async () => {
        const { data } = await axios.get<{ id: number; email: string }[]>(route('invites.index', workspace.slug));
        setPending(data);
    }, [workspace.slug]);

    useEffect(() => {
        if (show && workspace.is_admin) loadPending().catch(() => setPending([]));
    }, [show, workspace.is_admin, loadPending]);

    const sendInvites = async (e: FormEvent) => {
        e.preventDefault();
        const list = emails.split(/[\s,;]+/).filter(Boolean);

        try {
            const { data } = await axios.post<{ sent: number }>(route('invites.store', workspace.slug), { emails: list });
            setResult(`${data.sent} ${data.sent === 1 ? 'invite' : 'invites'} sent.`);
            setEmails('');
            await loadPending();
        } catch {
            setResult('Invites not sent. Check the addresses and try again.');
        }
    };

    const cancel = async (id: number) => {
        try {
            await axios.delete(route('invites.destroy', [workspace.slug, id]));
            setPending((list) => list.filter((invite) => invite.id !== id));
        } catch {
            setResult('That invite could not be cancelled. Try again.');
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="p-5">
                <h2 className="text-lg font-semibold text-slate-900">Invite people to {workspace.name}</h2>

                {workspace.is_admin ? (
                    <>
                        <p className="mb-3 mt-1 text-sm text-slate-600">
                            Only the addresses you invite can sign in and join.
                        </p>
                        <form onSubmit={sendInvites}>
                            <label htmlFor="invite-emails" className="mb-1 block text-sm font-medium text-slate-700">
                                Invite by email
                            </label>
                            <textarea
                                id="invite-emails"
                                rows={2}
                                required
                                value={emails}
                                onChange={(e) => setEmails(e.target.value)}
                                placeholder="name@company.com, other@company.com"
                                className="w-full rounded-md border-slate-300 text-sm"
                            />
                            {result && <p className="mt-1 text-sm text-slate-600">{result}</p>}
                            <button
                                type="submit"
                                className="mt-2 rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800"
                            >
                                Send invites
                            </button>
                        </form>

                        {pending.length > 0 && (
                            <section className="mt-4">
                                <h3 className="mb-1 text-sm font-medium text-slate-700">Waiting to join</h3>
                                <ul className="max-h-40 divide-y divide-slate-100 overflow-y-auto">
                                    {pending.map((invite) => (
                                        <li key={invite.id} className="flex items-center justify-between gap-2 py-1.5 text-sm text-slate-700">
                                            <span className="min-w-0 truncate">{invite.email}</span>
                                            <button
                                                type="button"
                                                onClick={() => cancel(invite.id)}
                                                className="shrink-0 text-red-700 hover:underline"
                                            >
                                                Cancel
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </>
                ) : (
                    <p className="mt-1 text-sm text-slate-600">Ask a workspace admin to invite someone by email.</p>
                )}
            </div>
        </Modal>
    );
}
