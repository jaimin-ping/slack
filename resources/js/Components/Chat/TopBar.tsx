import { BackIcon, ForwardIcon, SearchIcon } from '@/Components/Chat/Icons';
import { SearchResult, WorkspaceInfo } from '@/types/chat';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

const SEARCH_DELAY_MS = 250;

export default function TopBar({ workspace }: { workspace: WorkspaceInfo }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[] | null>(null);
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const term = query.trim();
        if (term.length < 2) {
            setResults(null);
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            axios
                .get<SearchResult[]>(route('workspaces.search', workspace.slug), {
                    params: { q: term },
                    signal: controller.signal,
                })
                .then(({ data }) => setResults(data))
                .catch(() => {});
        }, SEARCH_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, workspace.slug]);

    useEffect(() => {
        const close = (e: MouseEvent) => {
            if (!box.current?.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, []);

    const navButton = 'rounded p-1 text-white/70 hover:bg-white/10 hover:text-white';

    return (
        <div className="flex h-10 shrink-0 items-center justify-center gap-2 px-3">
            <button type="button" aria-label="Back" className={navButton} onClick={() => window.history.back()}>
                <BackIcon className="h-4 w-4" />
            </button>
            <button type="button" aria-label="Forward" className={navButton} onClick={() => window.history.forward()}>
                <ForwardIcon className="h-4 w-4" />
            </button>

            <div ref={box} className="relative w-full max-w-2xl">
                <SearchIcon className="pointer-events-none absolute left-2.5 top-1.5 h-4 w-4 text-white/70" />
                <input
                    type="search"
                    aria-label={`Search ${workspace.name}`}
                    placeholder={`Search ${workspace.name}`}
                    value={query}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
                    className="h-7 w-full rounded-md border-0 bg-white/15 pl-8 pr-2 text-sm text-white placeholder-white/70 focus:bg-white/25 focus:ring-1 focus:ring-white/50"
                />

                {open && results !== null && (
                    <div className="absolute z-40 mt-1 max-h-96 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-xl">
                        {results.length === 0 && (
                            <p className="px-4 py-3 text-sm text-slate-500">No messages match “{query.trim()}”.</p>
                        )}
                        {results.map((result) => (
                            <Link
                                key={result.id}
                                href={route('channels.show', [workspace.slug, result.channel_id])}
                                onClick={() => setOpen(false)}
                                className="block px-4 py-2 hover:bg-slate-100"
                            >
                                <span className="block text-xs text-slate-500">
                                    {result.channel_label} · {result.user.name} ·{' '}
                                    {new Date(result.created_at).toLocaleDateString()}
                                </span>
                                <span className="block truncate text-sm text-slate-800">{result.body}</span>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
