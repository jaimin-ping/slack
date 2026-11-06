import RichText from '@/Components/Chat/RichText';
import { ChatUser, WorkspaceInfo } from '@/types/chat';
import axios, { isAxiosError } from 'axios';
import { useCallback, useEffect, useState } from 'react';

interface Canvas {
    body: string;
    version: number;
    updated_at: string | null;
    updated_by: string | null;
}

interface Props {
    workspace: WorkspaceInfo;
    channelId: number;
    remoteVersion: number;
    me: ChatUser;
    members: ChatUser[];
}

export default function CanvasPane({ workspace, channelId, remoteVersion, me, members }: Props) {
    const [canvas, setCanvas] = useState<Canvas | null>(null);
    const [draft, setDraft] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const url = route('canvas.show', [workspace.slug, channelId]);

    const load = useCallback(() => {
        axios
            .get<Canvas>(url)
            .then(({ data }) => setCanvas(data))
            .catch(() => setError('Could not load this canvas.'));
    }, [url]);

    useEffect(load, [load, remoteVersion]);

    const editing = draft !== null;
    const changedElsewhere = editing && canvas !== null && remoteVersion > canvas.version;

    const save = async () => {
        if (!canvas || draft === null) return;

        setSaving(true);
        setError(null);
        try {
            const { data } = await axios.put<Canvas>(route('canvas.update', [workspace.slug, channelId]), {
                body: draft,
                version: canvas.version,
            });
            setCanvas(data);
            setDraft(null);
        } catch (e) {
            setError(
                isAxiosError(e) && e.response?.status === 409
                    ? 'Someone else saved this canvas. Copy your text, cancel, and edit the latest version.'
                    : 'Canvas not saved. Try again.',
            );
        } finally {
            setSaving(false);
        }
    };

    if (!canvas) {
        return <p className="flex-1 p-6 text-sm text-slate-500">{error ?? 'Loading…'}</p>;
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-2">
                <span className="min-w-0 truncate text-xs text-slate-500">
                    {canvas.updated_at
                        ? `Last edited by ${canvas.updated_by ?? 'someone'} on ${new Date(canvas.updated_at).toLocaleString()}`
                        : 'Shared notes for everyone in this conversation'}
                </span>
                {editing ? (
                    <span className="flex shrink-0 gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                setDraft(null);
                                setError(null);
                                load();
                            }}
                            className="rounded px-3 py-1 text-sm text-slate-600 hover:bg-slate-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={save}
                            disabled={saving}
                            className="rounded bg-teal-700 px-3 py-1 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50"
                        >
                            Save
                        </button>
                    </span>
                ) : (
                    <button
                        type="button"
                        onClick={() => setDraft(canvas.body)}
                        className="shrink-0 rounded border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50"
                    >
                        Edit
                    </button>
                )}
            </div>

            {error && <p className="px-5 pt-2 text-sm text-red-600">{error}</p>}
            {changedElsewhere && !error && (
                <p className="px-5 pt-2 text-sm text-amber-700">Someone else just saved this canvas.</p>
            )}

            {editing ? (
                <textarea
                    aria-label="Canvas text"
                    autoFocus
                    maxLength={50000}
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    placeholder="Write notes. *bold*, _italic_, - lists and > quotes work here."
                    className="min-h-0 flex-1 resize-none border-0 px-6 py-4 text-[15px] leading-6 focus:ring-0"
                />
            ) : (
                <div className="flex-1 overflow-y-auto whitespace-pre-wrap break-words px-6 py-4 text-[15px] leading-6 text-slate-800">
                    {canvas.body ? (
                        <RichText text={canvas.body} members={members} meId={me.id} />
                    ) : (
                        <p className="text-slate-500">This canvas is empty. Choose Edit to start writing.</p>
                    )}
                </div>
            )}
        </div>
    );
}
