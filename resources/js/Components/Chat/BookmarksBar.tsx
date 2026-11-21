import { PlusIcon } from '@/Components/Chat/Icons';
import { ChannelBookmark } from '@/types/chat';
import axios from 'axios';
import { FormEvent, useEffect, useRef, useState } from 'react';

type Props = {
    slug: string;
    channelId: number;
    initial: ChannelBookmark[];
    canEdit: boolean;
};

const host = (url: string) => {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
};

/** Slack-style bookmarks bar: links pinned under the channel header. */
export default function BookmarksBar({ slug, channelId, initial, canEdit }: Props) {
    const [bookmarks, setBookmarks] = useState(initial);
    const [adding, setAdding] = useState(false);
    const [url, setUrl] = useState('');
    const [title, setTitle] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const box = useRef<HTMLFormElement>(null);

    useEffect(() => {
        if (!adding) return;
        const away = (e: MouseEvent) => {
            if (box.current && !box.current.contains(e.target as Node)) setAdding(false);
        };
        const esc = (e: KeyboardEvent) => e.key === 'Escape' && setAdding(false);
        document.addEventListener('mousedown', away);
        document.addEventListener('keydown', esc);
        return () => {
            document.removeEventListener('mousedown', away);
            document.removeEventListener('keydown', esc);
        };
    }, [adding]);

    if (bookmarks.length === 0 && !canEdit) return null;

    const open = () => {
        setUrl('');
        setTitle('');
        setError(null);
        setAdding(true);
    };

    const save = async (e: FormEvent) => {
        e.preventDefault();
        let link = url.trim();
        if (link && !/^https?:\/\//i.test(link)) link = `https://${link}`;
        setSaving(true);
        setError(null);
        try {
            const { data } = await axios.post<ChannelBookmark>(route('bookmarks.store', [slug, channelId]), {
                url: link,
                title: title.trim() || null,
            });
            setBookmarks((current) => [...current, data]);
            setAdding(false);
        } catch {
            setError('Enter a valid link, like https://example.com');
        } finally {
            setSaving(false);
        }
    };

    const remove = async (bookmark: ChannelBookmark) => {
        setBookmarks((current) => current.filter((b) => b.id !== bookmark.id));
        try {
            await axios.delete(route('bookmarks.destroy', [slug, channelId, bookmark.id]));
        } catch {
            setBookmarks((current) => [...current, bookmark].sort((a, b) => a.id - b.id));
        }
    };

    return (
        <div className="relative shrink-0 border-b border-slate-200 text-[13px]">
          <div className="flex items-center gap-1 overflow-x-auto px-4 py-1">
            {bookmarks.map((bookmark) => (
                <span key={bookmark.id} className="group flex shrink-0 items-center rounded hover:bg-slate-100">
                    <a
                        href={bookmark.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={bookmark.url}
                        className="flex max-w-[14rem] items-center gap-1.5 px-2 py-1 text-slate-700"
                    >
                        <span aria-hidden>🔗</span>
                        <span className="truncate">{bookmark.title || host(bookmark.url)}</span>
                    </a>
                    {canEdit && (
                        <button
                            type="button"
                            aria-label={`Remove bookmark ${bookmark.title}`}
                            onClick={() => remove(bookmark)}
                            className="hidden pr-1.5 text-slate-400 hover:text-red-600 group-hover:block"
                        >
                            ×
                        </button>
                    )}
                </span>
            ))}

            {canEdit && (
                <button
                    type="button"
                    onClick={open}
                    className="flex shrink-0 items-center gap-1 rounded px-2 py-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                >
                    <PlusIcon className="h-3.5 w-3.5" />
                    {bookmarks.length === 0 ? 'Add a bookmark' : 'Add'}
                </button>
            )}

          </div>

            {adding && (
                <form
                    ref={box}
                    onSubmit={save}
                    className="absolute left-4 top-full z-40 mt-1 w-80 space-y-2 rounded-lg border border-slate-200 bg-white p-3 shadow-xl"
                >
                    <p className="text-sm font-bold text-slate-900">Add a bookmark</p>
                    <input
                        autoFocus
                        required
                        value={url}
                        onChange={(e) => setUrl(e.target.value)}
                        placeholder="Link (https://…)"
                        maxLength={2000}
                        className="w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600"
                    />
                    <input
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        placeholder="Name (optional)"
                        maxLength={100}
                        className="w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600"
                    />
                    {error && <p className="text-xs text-red-600">{error}</p>}
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => setAdding(false)}
                            className="rounded-md border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={saving}
                            className="rounded-md bg-teal-700 px-3 py-1 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50"
                        >
                            Add
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}
