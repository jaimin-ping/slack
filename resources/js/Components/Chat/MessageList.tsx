import Avatar from '@/Components/Chat/Avatar';
import EmojiDialog from '@/Components/Chat/EmojiDialog';
import {
    BookmarkIcon,
    ChatIcon,
    FileIcon,
    PencilIcon,
    PinIcon,
    ShareIcon,
    SmileIcon,
    TrashIcon,
} from '@/Components/Chat/Icons';
import RichText from '@/Components/Chat/RichText';
import {
    ChatMessage,
    ChatUser,
    MessageAttachment,
    MessageCard,
} from '@/types/chat';
import { KeyboardEvent, useLayoutEffect, useRef, useState } from 'react';

interface Props {
    messages: ChatMessage[];
    hasMore: boolean;
    me: ChatUser;
    members: ChatUser[];
    emptyText: string;
    newLineId: number | null;
    onLoadOlder: () => Promise<void>;
    onReact: (messageId: number, emoji: string) => void;
    onReply?: (message: ChatMessage) => void;
    onEdit: (messageId: number, body: string) => Promise<void>;
    onDelete: (message: ChatMessage) => void;
    canModerate: boolean;
    savedIds: Set<number>;
    onPin: (messageId: number) => void;
    onSave: (messageId: number) => void;
    onForward: (message: ChatMessage) => void;
}

const GROUP_WINDOW_MS = 5 * 60 * 1000;
const LONG_MESSAGE_CHARS = 600;
const LONG_MESSAGE_LINES = 8;
const QUICK_EMOJIS = ['✅', '👀', '👍'];

const time = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
const day = (iso: string) =>
    new Date(iso).toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });

function Body({ text, members, meId }: { text: string; members: ChatUser[]; meId: number }) {
    const [expanded, setExpanded] = useState(false);
    const long = text.length > LONG_MESSAGE_CHARS || text.split('\n').length > LONG_MESSAGE_LINES;

    return (
        <>
            <div
                className={`whitespace-pre-wrap break-words text-[15px] leading-[22px] text-slate-800 ${
                    long && !expanded ? 'max-h-44 overflow-hidden' : ''
                }`}
            >
                <RichText text={text} members={members} meId={meId} />
            </div>
            {long && (
                <button
                    type="button"
                    onClick={() => setExpanded(!expanded)}
                    className="text-[15px] text-sky-700 hover:underline"
                >
                    {expanded ? 'Show less' : 'Show more'}
                </button>
            )}
        </>
    );
}

const fileSize = (bytes: number) =>
    bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;

function Attachments({ files }: { files: MessageAttachment[] }) {
    return (
        <div className="mt-1 flex flex-wrap gap-2">
            {files.map((file) =>
                file.is_image ? (
                    <a key={file.id} href={file.url} target="_blank" rel="noopener noreferrer" title={file.name}>
                        <img
                            src={file.url}
                            alt={file.name}
                            loading="lazy"
                            className="max-h-64 max-w-full rounded-lg border border-slate-200"
                        />
                    </a>
                ) : (
                    <a
                        key={file.id}
                        href={file.url}
                        className="flex max-w-xs items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 hover:bg-slate-50"
                    >
                        <FileIcon className="h-6 w-6 shrink-0 text-sky-700" />
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-semibold text-slate-800">{file.name}</span>
                            <span className="block text-xs text-slate-500">{fileSize(file.size)}</span>
                        </span>
                    </a>
                ),
            )}
        </div>
    );
}

function Cards({ cards, members, meId }: { cards: MessageCard[]; members: ChatUser[]; meId: number }) {
    return (
        <div className="mt-1 space-y-2">
            {cards.map((card, index) => (
                <div
                    key={index}
                    className="whitespace-pre-wrap break-words border-l-4 pl-3 text-[15px] leading-[22px] text-slate-800"
                    style={{ borderLeftColor: card.color }}
                >
                    {card.title && <p className="font-bold">{card.title}</p>}
                    {card.text && <RichText text={card.text} members={members} meId={meId} />}
                    {card.fields.length > 0 && (
                        <dl className="mt-1">
                            {card.fields.map((item, fieldIndex) => (
                                <div key={fieldIndex}>
                                    <dt className="inline font-bold">{item.title}: </dt>
                                    <dd className="inline">{item.value}</dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </div>
            ))}
        </div>
    );
}

function EditBox({
    initial,
    onSave,
    onCancel,
}: {
    initial: string;
    onSave: (body: string) => Promise<void>;
    onCancel: () => void;
}) {
    const [draft, setDraft] = useState(initial);
    const [saving, setSaving] = useState(false);
    const [failed, setFailed] = useState(false);

    const save = async () => {
        setSaving(true);
        setFailed(false);
        try {
            await onSave(draft.trim());
        } catch {
            setFailed(true);
            setSaving(false);
        }
    };

    const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Escape') onCancel();
        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
            e.preventDefault();
            save();
        }
    };

    return (
        <div className="my-1 rounded-lg border border-slate-300 bg-white p-2">
            <textarea
                aria-label="Edit message"
                autoFocus
                rows={3}
                maxLength={4000}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={onKeyDown}
                className="block w-full resize-y rounded border-0 p-1 text-[15px] focus:ring-0"
            />
            {failed && <p className="text-sm text-red-600">Change not saved. Try again.</p>}
            <div className="mt-1 flex justify-end gap-2">
                <button type="button" onClick={onCancel} className="rounded px-3 py-1 text-sm text-slate-600 hover:bg-slate-100">
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
            </div>
        </div>
    );
}

export default function MessageList({
    messages,
    hasMore,
    me,
    members,
    emptyText,
    newLineId,
    onLoadOlder,
    onReact,
    onReply,
    onEdit,
    onDelete,
    canModerate,
    savedIds,
    onPin,
    onSave,
    onForward,
}: Props) {
    const scroller = useRef<HTMLDivElement>(null);
    const nearBottom = useRef(true);
    const heightBeforeLoad = useRef<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [pickerFor, setPickerFor] = useState<number | null>(null);
    const [editingId, setEditingId] = useState<number | null>(null);

    useLayoutEffect(() => {
        const el = scroller.current;
        if (!el) return;

        if (heightBeforeLoad.current !== null) {
            el.scrollTop += el.scrollHeight - heightBeforeLoad.current;
            heightBeforeLoad.current = null;
            return;
        }

        if (nearBottom.current || messages[messages.length - 1]?.user.id === me.id) {
            el.scrollTop = el.scrollHeight;
        }
    }, [messages, me.id]);

    const loadOlder = async () => {
        heightBeforeLoad.current = scroller.current?.scrollHeight ?? null;
        setLoading(true);
        try {
            await onLoadOlder();
        } finally {
            setLoading(false);
        }
    };

    const react = (messageId: number, emoji: string) => {
        setPickerFor(null);
        onReact(messageId, emoji);
    };

    const namesFor = (ids: number[]) =>
        ids
            .map((id) => (id === me.id ? 'You' : (members.find((m) => m.id === id)?.name ?? 'Someone')))
            .join(', ');

    return (
        <div
            ref={scroller}
            className="flex-1 overflow-y-auto pb-3"
            onScroll={(e) => {
                const el = e.currentTarget;
                nearBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
            }}
        >
            {hasMore && (
                <div className="py-3 text-center">
                    <button
                        type="button"
                        onClick={loadOlder}
                        disabled={loading}
                        className="rounded-full border border-slate-300 px-3 py-1 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                    >
                        {loading ? 'Loading…' : 'Load older messages'}
                    </button>
                </div>
            )}

            {messages.length === 0 && <p className="mt-10 text-center text-sm text-slate-500">{emptyText}</p>}

            {messages.map((message, index) => {
                const previous = messages[index - 1];
                const newDay = !previous || day(previous.created_at) !== day(message.created_at);
                const isNewLine = message.id === newLineId;
                const canEdit = !message.is_bot && message.user.id === me.id;
                const grouped =
                    !newDay &&
                    !isNewLine &&
                    previous.user.id === message.user.id &&
                    previous.user.name === message.user.name &&
                    !previous.removed &&
                    new Date(message.created_at).getTime() - new Date(previous.created_at).getTime() < GROUP_WINDOW_MS;

                return (
                    <div key={message.id}>
                        {newDay && (
                            <div className="sticky top-1 z-10 my-2 flex items-center">
                                <span className="h-px flex-1 bg-slate-200" />
                                <span className="rounded-full border border-slate-200 bg-white px-3 py-0.5 text-[13px] font-semibold text-slate-700">
                                    {day(message.created_at)}
                                </span>
                                <span className="h-px flex-1 bg-slate-200" />
                            </div>
                        )}

                        {isNewLine && (
                            <div className="my-1 flex items-center gap-2 pl-5 text-xs font-semibold text-rose-600">
                                <span className="h-px flex-1 bg-rose-400" />
                                <span className="pr-4">New</span>
                            </div>
                        )}

                        <div
                            className={`group relative flex gap-2 px-5 py-0.5 hover:bg-slate-50 ${grouped ? '' : 'mt-2 pt-1'}`}
                        >
                            {grouped ? (
                                <time
                                    dateTime={message.created_at}
                                    className="w-9 shrink-0 pt-1 text-right text-[11px] text-slate-500 opacity-0 group-hover:opacity-100"
                                >
                                    {time(message.created_at).replace(/\s?[AP]M/i, '')}
                                </time>
                            ) : (
                                <Avatar
                                    user={members.find((m) => m.id === message.user.id) ?? message.user}
                                    className="h-9 w-9 rounded-lg"
                                />
                            )}

                            <div className="min-w-0 flex-1">
                                {!grouped && (
                                    <div className="flex items-baseline gap-2">
                                        <span className="text-[15px] font-bold text-slate-900">{message.user.name}</span>
                                        {message.is_bot && (
                                            <span className="rounded bg-slate-200 px-1 text-[10px] font-semibold text-slate-600">
                                                APP
                                            </span>
                                        )}
                                        <time dateTime={message.created_at} className="text-xs text-slate-500">
                                            {time(message.created_at)}
                                        </time>
                                    </div>
                                )}

                                {message.pinned && (
                                    <p className="text-xs font-medium text-amber-700">📌 Pinned</p>
                                )}

                                {message.removed && (
                                    <p className="text-[15px] italic text-slate-500">This message was deleted.</p>
                                )}

                                {editingId === message.id ? (
                                    <EditBox
                                        initial={message.body}
                                        onCancel={() => setEditingId(null)}
                                        onSave={async (body) => {
                                            await onEdit(message.id, body);
                                            setEditingId(null);
                                        }}
                                    />
                                ) : (
                                    message.body && <Body text={message.body} members={members} meId={me.id} />
                                )}

                                {message.edited_at && editingId !== message.id && (
                                    <span className="text-xs text-slate-500">(edited)</span>
                                )}

                                {message.cards.length > 0 && <Cards cards={message.cards} members={members} meId={me.id} />}

                                {message.link_preview && (
                                    <a
                                        href={message.link_preview.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="mt-1 block max-w-lg border-l-4 border-slate-300 pl-3 hover:border-teal-600"
                                    >
                                        <span className="block text-xs font-semibold text-slate-500">{message.link_preview.site}</span>
                                        <span className="block text-[15px] font-semibold text-sky-700">{message.link_preview.title}</span>
                                        {message.link_preview.description && (
                                            <span className="block text-sm text-slate-600">{message.link_preview.description}</span>
                                        )}
                                    </a>
                                )}

                                {message.attachments.length > 0 && <Attachments files={message.attachments} />}

                                {message.reactions.length > 0 && (
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {message.reactions.map((reaction) => {
                                            const mine = reaction.user_ids.includes(me.id);

                                            return (
                                                <button
                                                    key={reaction.emoji}
                                                    type="button"
                                                    title={namesFor(reaction.user_ids)}
                                                    aria-pressed={mine}
                                                    onClick={() => react(message.id, reaction.emoji)}
                                                    className={`flex h-6 items-center gap-1 rounded-full border px-2 text-xs ${
                                                        mine
                                                            ? 'border-sky-500 bg-sky-50 text-sky-800'
                                                            : 'border-slate-200 bg-slate-100 text-slate-700 hover:border-slate-400'
                                                    }`}
                                                >
                                                    <span className="text-sm leading-none">{reaction.emoji}</span>
                                                    {reaction.user_ids.length}
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}

                                {onReply && message.reply_count > 0 && (
                                    <button
                                        type="button"
                                        onClick={() => onReply(message)}
                                        className="mt-1 flex items-center gap-2 rounded border border-transparent px-1 py-0.5 text-[13px] hover:border-slate-200 hover:bg-white"
                                    >
                                        <span className="font-bold text-sky-700">
                                            {message.reply_count} {message.reply_count === 1 ? 'reply' : 'replies'}
                                        </span>
                                        {message.last_reply_at && (
                                            <span className="text-slate-500">Last reply {time(message.last_reply_at)}</span>
                                        )}
                                    </button>
                                )}
                            </div>

                            {/* Toolbar */}
                            <div
                                className={`absolute -top-3 right-5 z-10 items-center gap-0.5 rounded-lg border border-slate-200 bg-white p-0.5 shadow-sm ${
                                    message.removed || editingId === message.id
                                        ? 'hidden'
                                        : `group-hover:flex group-focus-within:flex ${pickerFor === message.id ? 'flex' : 'hidden'}`
                                }`}
                            >
                                {QUICK_EMOJIS.map((emoji) => (
                                    <button
                                        key={emoji}
                                        type="button"
                                        aria-label={`React with ${emoji}`}
                                        onClick={() => react(message.id, emoji)}
                                        className="rounded p-1 text-base leading-none hover:bg-slate-100"
                                    >
                                        {emoji}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    aria-label="Add reaction"
                                    aria-expanded={pickerFor === message.id}
                                    onClick={() => setPickerFor(pickerFor === message.id ? null : message.id)}
                                    className="rounded p-1 text-slate-600 hover:bg-slate-100"
                                >
                                    <SmileIcon className="h-4 w-4" />
                                </button>
                                {onReply && (
                                    <button
                                        type="button"
                                        aria-label="Reply in thread"
                                        title="Reply in thread"
                                        onClick={() => onReply(message)}
                                        className="rounded p-1 text-slate-600 hover:bg-slate-100"
                                    >
                                        <ChatIcon className="h-4 w-4" />
                                    </button>
                                )}
                                <button
                                    type="button"
                                    aria-label={message.pinned ? 'Unpin message' : 'Pin message'}
                                    title={message.pinned ? 'Unpin message' : 'Pin message'}
                                    onClick={() => onPin(message.id)}
                                    className={`rounded p-1 hover:bg-slate-100 ${message.pinned ? 'text-amber-600' : 'text-slate-600'}`}
                                >
                                    <PinIcon className="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    aria-label={savedIds.has(message.id) ? 'Remove from saved items' : 'Save for later'}
                                    title={savedIds.has(message.id) ? 'Remove from saved items' : 'Save for later'}
                                    onClick={() => onSave(message.id)}
                                    className={`rounded p-1 hover:bg-slate-100 ${savedIds.has(message.id) ? 'text-rose-600' : 'text-slate-600'}`}
                                >
                                    <BookmarkIcon filled={savedIds.has(message.id)} className="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    aria-label="Forward message"
                                    title="Forward message"
                                    onClick={() => onForward(message)}
                                    className="rounded p-1 text-slate-600 hover:bg-slate-100"
                                >
                                    <ShareIcon className="h-4 w-4" />
                                </button>
                                {canEdit && (
                                    <button
                                        type="button"
                                        aria-label="Edit message"
                                        title="Edit message"
                                        onClick={() => setEditingId(message.id)}
                                        className="rounded p-1 text-slate-600 hover:bg-slate-100"
                                    >
                                        <PencilIcon className="h-4 w-4" />
                                    </button>
                                )}
                                {(canEdit || canModerate) && (
                                    <button
                                        type="button"
                                        aria-label="Delete message"
                                        title="Delete message"
                                        onClick={() => onDelete(message)}
                                        className="rounded p-1 text-rose-700 hover:bg-rose-50"
                                    >
                                        <TrashIcon className="h-4 w-4" />
                                    </button>
                                )}
                                {pickerFor === message.id && (
                                    <EmojiDialog onClose={() => setPickerFor(null)} onPick={(emoji) => react(message.id, emoji)} />
                                )}
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
