import Modal from '@/Components/Modal';
import { WorkspaceInfo } from '@/types/chat';
import axios from 'axios';
import { FormEvent, useState } from 'react';

interface Props {
    show: boolean;
    workspace: WorkspaceInfo;
    onClose: () => void;
}

export default function InviteModal({ show, workspace, onClose }: Props) {
    const [copied, setCopied] = useState(false);
    const [emails, setEmails] = useState('');
    const [result, setResult] = useState<string | null>(null);

    const sendInvites = async (e: FormEvent) => {
        e.preventDefault();
        const list = emails.split(/[\s,;]+/).filter(Boolean);

        try {
            const { data } = await axios.post<{ sent: number }>(route('invites.store', workspace.slug), { emails: list });
            setResult(`${data.sent} ${data.sent === 1 ? 'invite' : 'invites'} sent.`);
            setEmails('');
        } catch {
            setResult('Invites not sent. Check the addresses and try again.');
        }
    };
    const link = route('workspaces.invite', workspace.invite_code);

    const copy = async () => {
        await navigator.clipboard.writeText(link);
        setCopied(true);
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="p-5">
                <h2 className="text-lg font-semibold text-slate-900">Invite people to {workspace.name}</h2>
                <p className="mb-3 mt-1 text-sm text-slate-600">
                    Anyone with this link can join after they sign in.
                </p>
                <div className="flex gap-2">
                    <input
                        readOnly
                        aria-label="Invite link"
                        value={link}
                        onFocus={(e) => e.target.select()}
                        className="min-w-0 flex-1 rounded-md border-slate-300 text-sm"
                    />
                    <button
                        type="button"
                        onClick={copy}
                        className="rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800"
                    >
                        {copied ? 'Copied' : 'Copy'}
                    </button>
                </div>

                {workspace.is_admin && (
                    <form onSubmit={sendInvites} className="mt-4">
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
                )}
            </div>
        </Modal>
    );
}
