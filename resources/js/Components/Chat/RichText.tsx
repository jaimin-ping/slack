import { ChatUser } from '@/types/chat';
import { Fragment, ReactNode } from 'react';

/*
 * Renders message text. Supported marks: *bold* _italic_ __underline__ ~strike~ `code`,
 * ```code blocks```, "> " quotes, "- " and "1. " lists, links and @mentions.
 * Everything becomes React elements, so message text can never inject HTML.
 */

const INLINE =
    /(?<![\w*])\*([^*\n]+)\*(?![\w*])|(?<![\w_])__([^_\n]+)__(?![\w_])|(?<![\w_])_([^_\n]+)_(?![\w_])|(?<![\w~])~([^~\n]+)~(?![\w~])|`([^`\n]+)`|(https?:\/\/[^\s<>]+)/g;

const escapeRegex = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

let customEmojis = new Map<string, string>();

/** The page sets this from the workspace's list; message text reads it while rendering. */
export function setCustomEmojis(emojis: { name: string; url: string }[]) {
    customEmojis = new Map(emojis.map((emoji) => [emoji.name, emoji.url]));
}

export function getCustomEmojis() {
    return [...customEmojis].map(([name, url]) => ({ id: name, names: [name], imgUrl: url }));
}

function emojify(text: string): ReactNode[] {
    if (customEmojis.size === 0 || !text.includes(':')) return [text];

    return text.split(/(:[a-z0-9_-]{2,32}:)/g).map((part, index) => {
        const url = customEmojis.get(part.slice(1, -1));

        return part.startsWith(':') && url ? (
            <img key={index} src={url} alt={part} title={part} className="inline h-5 w-5 object-contain align-text-bottom" />
        ) : (
            part
        );
    });
}

function mentions(text: string, members: ChatUser[], meId: number): ReactNode[] {
    if (!text.includes('@')) return emojify(text);

    const names = [...members]
        .sort((a, b) => b.name.length - a.name.length)
        .map((m) => escapeRegex(m.name))
        .concat('channel', 'here');
    const pattern = new RegExp(`(?<![\\w@])@(${names.join('|')})(?![\\w])`, 'g');
    const parts: ReactNode[] = [];
    let last = 0;

    for (const match of text.matchAll(pattern)) {
        const mine =
            match[1] === 'channel' ||
            match[1] === 'here' ||
            members.find((m) => m.name === match[1])?.id === meId ||
            (members.find((m) => m.name === match[1])?.id ?? 0) < 0;
        parts.push(...emojify(text.slice(last, match.index)));
        parts.push(
            <span
                key={match.index}
                className={`rounded px-0.5 ${mine ? 'bg-amber-200 text-amber-900' : 'bg-sky-100 text-sky-800'}`}
            >
                {match[0]}
            </span>,
        );
        last = match.index + match[0].length;
    }
    parts.push(...emojify(text.slice(last)));

    return parts;
}

function inline(text: string, members: ChatUser[], meId: number): ReactNode[] {
    const parts: ReactNode[] = [];
    let last = 0;

    for (const match of text.matchAll(INLINE)) {
        const [whole, bold, underline, italic, strike, code, url] = match;
        const at = match.index;
        parts.push(...mentions(text.slice(last, at), members, meId));
        last = at + whole.length;

        if (bold) parts.push(<strong key={at}>{inline(bold, members, meId)}</strong>);
        else if (underline) parts.push(<u key={at}>{inline(underline, members, meId)}</u>);
        else if (italic) parts.push(<em key={at}>{inline(italic, members, meId)}</em>);
        else if (strike) parts.push(<s key={at}>{inline(strike, members, meId)}</s>);
        else if (code)
            parts.push(
                <code key={at} className="rounded border border-slate-200 bg-slate-100 px-1 text-[13px] text-rose-700">
                    {code}
                </code>,
            );
        else
            parts.push(
                <a key={at} href={url} target="_blank" rel="noopener noreferrer" className="text-sky-700 hover:underline">
                    {url}
                </a>,
            );
    }
    parts.push(...mentions(text.slice(last), members, meId));

    return parts.map((part, index) => <Fragment key={index}>{part}</Fragment>);
}

export default function RichText({ text, members, meId }: { text: string; members: ChatUser[]; meId: number }) {
    const lines = text.split('\n');
    const blocks: ReactNode[] = [];

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];

        if (line.trim().startsWith('```')) {
            const code: string[] = [];
            const first = line.trim().slice(3);
            if (first.endsWith('```') && first.length > 3) {
                code.push(first.slice(0, -3));
            } else {
                if (first) code.push(first);
                for (i++; i < lines.length && !lines[i].trim().endsWith('```'); i++) code.push(lines[i]);
                const closing = lines[i]?.trim().slice(0, -3);
                if (closing) code.push(closing);
            }
            blocks.push(
                <pre key={i} className="my-1 overflow-x-auto rounded border border-slate-200 bg-slate-50 p-2 text-[13px]">
                    {code.join('\n')}
                </pre>,
            );
        } else if (line.startsWith('> ')) {
            const quote: string[] = [];
            for (; i < lines.length && lines[i].startsWith('> '); i++) quote.push(lines[i].slice(2));
            i--;
            blocks.push(
                <blockquote key={i} className="border-l-4 border-slate-300 pl-3">
                    {quote.map((row, index) => (
                        <div key={index}>{inline(row, members, meId)}</div>
                    ))}
                </blockquote>,
            );
        } else if (/^- /.test(line)) {
            const items: string[] = [];
            for (; i < lines.length && /^- /.test(lines[i]); i++) items.push(lines[i].slice(2));
            i--;
            blocks.push(
                <ul key={i} className="list-disc pl-6">
                    {items.map((item, index) => (
                        <li key={index}>{inline(item, members, meId)}</li>
                    ))}
                </ul>,
            );
        } else if (/^\d+\. /.test(line)) {
            const items: string[] = [];
            for (; i < lines.length && /^\d+\. /.test(lines[i]); i++) items.push(lines[i].replace(/^\d+\. /, ''));
            i--;
            blocks.push(
                <ol key={i} className="list-decimal pl-6">
                    {items.map((item, index) => (
                        <li key={index}>{inline(item, members, meId)}</li>
                    ))}
                </ol>,
            );
        } else {
            blocks.push(<div key={i}>{line === '' ? ' ' : inline(line, members, meId)}</div>);
        }
    }

    return <>{blocks}</>;
}
