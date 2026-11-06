import Avatar from '@/Components/Chat/Avatar';
import EmojiDialog from '@/Components/Chat/EmojiDialog';
import { getCustomEmojis } from '@/Components/Chat/RichText';
import { FileIcon, PlusIcon, SendIcon, SmileIcon } from '@/Components/Chat/Icons';
import { serialize, textToDoc } from '@/composerFormat';
import { matchCommands } from '@/slashCommands';
import { ChatUser } from '@/types/chat';
import { Editor, markInputRule } from '@tiptap/core';
import Bold from '@tiptap/extension-bold';
import Italic from '@tiptap/extension-italic';
import Placeholder from '@tiptap/extension-placeholder';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { isAxiosError } from 'axios';
import { ReactNode, useEffect, useRef, useState } from 'react';

interface Props {
    placeholder: string;
    members: ChatUser[];
    onSend: (body: string, files: File[]) => Promise<void>;
    onTyping?: () => void;
    initialBody?: string;
    onDraft?: (body: string) => void;
    onSchedule?: (body: string, sendAt: string) => Promise<void>;
    /** Offers slash commands; only the channel composer runs them. */
    commands?: boolean;
}

const TYPING_THROTTLE_MS = 2500;
const DRAFT_DELAY_MS = 800;
const MAX_SUGGESTIONS = 6;
const MAX_FILES = 3;
const MAX_FILE_BYTES = 10 * 1024 * 1024;

// Same shortcuts as the stored format: *bold* and _italic_.
const ChatBold = Bold.extend({
    addInputRules() {
        return [markInputRule({ find: /(?:^|\s)(\*([^*\s][^*]*)\*)$/, type: this.type })];
    },
    addPasteRules: () => [],
});

const ChatItalic = Italic.extend({
    addInputRules() {
        return [markInputRule({ find: /(?:^|\s)(_([^_\s][^_]*)_)$/, type: this.type })];
    },
    addPasteRules: () => [],
});

function ToolButton({
    label,
    active = false,
    onClick,
    children,
}: {
    label: string;
    active?: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            aria-pressed={active}
            onMouseDown={(e) => e.preventDefault()}
            onClick={onClick}
            className={`flex h-7 min-w-7 items-center justify-center rounded px-1 text-sm hover:bg-slate-200 ${
                active ? 'bg-slate-300 text-slate-900' : 'text-slate-600'
            }`}
        >
            {children}
        </button>
    );
}

export default function Composer({ placeholder, members, onSend, onTyping, initialBody = '', onDraft, onSchedule, commands = false }: Props) {
    const [text, setText] = useState(initialBody);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [showToolbar, setShowToolbar] = useState(true);
    const [emojiOpen, setEmojiOpen] = useState(false);
    const [mention, setMention] = useState<{ start: number; end: number; query: string } | null>(null);
    const [slash, setSlash] = useState<string | null>(null);
    const [highlight, setHighlight] = useState(0);
    const [files, setFiles] = useState<File[]>([]);
    const [dragging, setDragging] = useState(false);
    const [scheduleOpen, setScheduleOpen] = useState(false);
    const [sendAt, setSendAt] = useState('');
    const picker = useRef<HTMLInputElement>(null);
    const lastTyping = useRef(0);

    const savedDraft = useRef(initialBody);
    const latestText = useRef(initialBody);
    const saveDraft = useRef(onDraft);
    const typing = useRef(onTyping);
    saveDraft.current = onDraft;
    typing.current = onTyping;
    latestText.current = text;

    // While a message is on its way, saving its text as a draft would bring it back later.
    const submitting = useRef(false);

    const flushDraft = () => {
        if (submitting.current || latestText.current === savedDraft.current) return;
        savedDraft.current = latestText.current;
        saveDraft.current?.(latestText.current);
    };

    const suggestions = mention
        ? members.filter((m) => m.name.toLowerCase().includes(mention.query.toLowerCase())).slice(0, MAX_SUGGESTIONS)
        : [];

    const slashOptions = slash === null ? [] : matchCommands(slash);

    // The editor is created once, so its key handling reads current values from these refs.
    const keys = useRef<(event: KeyboardEvent) => boolean>(() => false);
    const paste = useRef<(event: ClipboardEvent) => boolean>(() => false);

    const detectMention = (current: Editor) => {
        const { from } = current.state.selection;
        const before = current.state.doc.textBetween(Math.max(0, from - 40), from, '\n', ' ');
        const match = before.match(/(?:^|\s)@([^\s@]*)$/);

        setMention(match ? { start: from - match[1].length - 1, end: from, query: match[1] } : null);

        const atEnd = from === current.state.doc.content.size - 1;
        setSlash(commands && atEnd && current.state.doc.childCount === 1 ? current.getText() : null);
        setHighlight(0);
    };

    const editor = useEditor({
        extensions: [
            StarterKit.configure({ heading: false, horizontalRule: false, link: false, bold: false, italic: false }),
            ChatBold,
            ChatItalic,
            Placeholder.configure({ placeholder }),
        ],
        content: initialBody ? textToDoc(initialBody) : '',
        autofocus: 'end',
        shouldRerenderOnTransaction: true,
        editorProps: {
            attributes: {
                class: 'chat-editor max-h-48 overflow-y-auto px-3 pt-2 text-[15px] leading-[22px] focus:outline-none',
                role: 'textbox',
                'aria-multiline': 'true',
                'aria-label': placeholder,
            },
            handleKeyDown: (_, event) => keys.current(event),
            handlePaste: (_, event) => paste.current(event),
        },
        onUpdate: ({ editor: current }) => {
            setText(serialize(current.getJSON()));
            detectMention(current);
            if (Date.now() - lastTyping.current > TYPING_THROTTLE_MS) {
                lastTyping.current = Date.now();
                typing.current?.();
            }
        },
        onSelectionUpdate: ({ editor: current }) => detectMention(current),
    });

    useEffect(() => {
        const timer = window.setTimeout(flushDraft, DRAFT_DELAY_MS);

        return () => window.clearTimeout(timer);
    }, [text]);

    // Leaving the channel saves whatever was typed in the last moment.
    useEffect(() => flushDraft, []);

    const pickMention = (member: ChatUser) => {
        if (!mention || !editor) return;
        editor.chain().focus().insertContentAt({ from: mention.start, to: mention.end }, `@${member.name} `).run();
        setMention(null);
    };

    const pickCommand = (insert: string) => {
        if (!editor) return;
        const end = editor.state.doc.content.size - 1;
        editor.chain().focus().insertContentAt({ from: 1, to: end }, insert).run();
    };

    const addFiles = (incoming: File[]) => {
        const tooBig = incoming.filter((file) => file.size > MAX_FILE_BYTES);
        const merged = [...files, ...incoming.filter((file) => file.size <= MAX_FILE_BYTES)];

        setFiles(merged.slice(0, MAX_FILES));
        setError(
            tooBig.length > 0
                ? `${tooBig[0].name} is larger than 10 MB.`
                : merged.length > MAX_FILES
                  ? `You can attach up to ${MAX_FILES} files per message.`
                  : null,
        );
    };

    const send = async () => {
        if (!editor || (!text && files.length === 0) || sending) return;

        const outgoing = { text, files };

        submitting.current = true;
        setSending(true);
        setError(null);

        // The box empties now, so anything typed while this is on its way is kept.
        editor.commands.clearContent(true);
        setFiles([]);

        try {
            await onSend(outgoing.text, outgoing.files);
            savedDraft.current = '';
        } catch (e) {
            const reason = isAxiosError<{ message?: string }>(e) ? e.response?.data?.message : null;
            setError(reason ?? 'Message not sent. Check your connection and try again.');

            if (!editor.isDestroyed) {
                const typedSince = latestText.current;
                editor.commands.setContent(textToDoc(typedSince ? `${outgoing.text}\n${typedSince}` : outgoing.text), {
                    emitUpdate: true,
                });
                setFiles((current) => [...outgoing.files, ...current]);
            }
        } finally {
            submitting.current = false;
            setSending(false);
        }
    };

    const schedule = async () => {
        if (!editor || !text || !sendAt || !onSchedule) return;

        submitting.current = true;
        setError(null);
        try {
            await onSchedule(text, new Date(sendAt).toISOString());
            savedDraft.current = '';
            editor.commands.clearContent(true);
            setScheduleOpen(false);
            setSendAt('');
        } catch (e) {
            const reason = isAxiosError<{ message?: string }>(e) ? e.response?.data?.message : null;
            setError(reason ?? 'Message not scheduled. Try again.');
        } finally {
            submitting.current = false;
        }
    };

    paste.current = (event) => {
        const pasted = Array.from(event.clipboardData?.files ?? []);
        if (pasted.length === 0) return false;
        addFiles(pasted);

        return true;
    };

    keys.current = (event) => {
        if (slashOptions.length > 0) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                const step = event.key === 'ArrowDown' ? 1 : slashOptions.length - 1;
                setHighlight((highlight + step) % slashOptions.length);
                return true;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                pickCommand(slashOptions[highlight % slashOptions.length].insert);
                return true;
            }
            if (event.key === 'Escape') {
                setSlash(null);
                return true;
            }
        }

        if (suggestions.length > 0) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                const step = event.key === 'ArrowDown' ? 1 : suggestions.length - 1;
                setHighlight((highlight + step) % suggestions.length);
                return true;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                pickMention(suggestions[highlight]);
                return true;
            }
            if (event.key === 'Escape') {
                setMention(null);
                return true;
            }
        }

        const inBlock = editor?.isActive('codeBlock') || editor?.isActive('bulletList') || editor?.isActive('orderedList');

        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && !inBlock) {
            send();
            return true;
        }

        return false;
    };

    return (
        <div
            className="relative px-5"
            onDragOver={(e) => {
                e.preventDefault();
                setDragging(true);
            }}
            onDragLeave={(e) => {
                if (!e.currentTarget.contains(e.relatedTarget as Node)) setDragging(false);
            }}
            onDrop={(e) => {
                e.preventDefault();
                setDragging(false);
                addFiles(Array.from(e.dataTransfer.files));
            }}
        >
            {dragging && (
                <div className="pointer-events-none absolute inset-x-5 inset-y-0 z-20 flex items-center justify-center rounded-lg border-2 border-dashed border-teal-600 bg-teal-50/90 text-sm font-semibold text-teal-900">
                    Drop files to attach
                </div>
            )}
            {slashOptions.length > 0 && (
                <ul
                    role="listbox"
                    aria-label="Commands"
                    className="absolute bottom-full left-5 z-30 mb-1 w-96 max-w-[calc(100%-2.5rem)] overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                >
                    {slashOptions.map((option, index) => (
                        <li key={option.insert} role="option" aria-selected={index === highlight % slashOptions.length}>
                            <button
                                type="button"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => pickCommand(option.insert)}
                                className={`block w-full px-3 py-1.5 text-left text-sm ${
                                    index === highlight % slashOptions.length ? 'bg-sky-700 text-white' : 'text-slate-800'
                                }`}
                            >
                                <span className="font-semibold">{option.insert.trimEnd()}</span>
                                <span className="ml-2 opacity-80">{option.hint}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {suggestions.length > 0 && (
                <ul
                    role="listbox"
                    aria-label="Mention someone"
                    className="absolute bottom-full left-5 z-30 mb-1 w-72 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-xl"
                >
                    {suggestions.map((member, index) => (
                        <li key={member.id} role="option" aria-selected={index === highlight}>
                            <button
                                type="button"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => pickMention(member)}
                                className={`flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm ${
                                    index === highlight ? 'bg-sky-700 text-white' : 'text-slate-800'
                                }`}
                            >
                                <Avatar user={member} className="h-5 w-5 rounded text-xs" />
                                {member.name}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {emojiOpen && (
                <EmojiDialog
                    custom={getCustomEmojis()}
                    onClose={() => setEmojiOpen(false)}
                    onPick={(emoji) => {
                        setEmojiOpen(false);
                        editor?.chain().focus().insertContent(emoji).run();
                    }}
                />
            )}

            <div className="overflow-hidden rounded-lg border border-slate-300 focus-within:border-slate-500 focus-within:shadow-sm">
                {showToolbar && (
                    <div role="toolbar" aria-label="Formatting" className="flex flex-wrap items-center gap-0.5 bg-slate-50 px-1.5 py-1">
                        <ToolButton label="Bold (Ctrl+B)" active={editor?.isActive('bold')} onClick={() => editor?.chain().focus().toggleBold().run()}><b>B</b></ToolButton>
                        <ToolButton label="Italic (Ctrl+I)" active={editor?.isActive('italic')} onClick={() => editor?.chain().focus().toggleItalic().run()}><i className="font-serif">I</i></ToolButton>
                        <ToolButton label="Underline (Ctrl+U)" active={editor?.isActive('underline')} onClick={() => editor?.chain().focus().toggleUnderline().run()}><u>U</u></ToolButton>
                        <ToolButton label="Strikethrough" active={editor?.isActive('strike')} onClick={() => editor?.chain().focus().toggleStrike().run()}><s>S</s></ToolButton>
                        <span className="mx-1 h-4 w-px bg-slate-300" />
                        <ToolButton label="Numbered list" active={editor?.isActive('orderedList')} onClick={() => editor?.chain().focus().toggleOrderedList().run()}>1.</ToolButton>
                        <ToolButton label="Bulleted list" active={editor?.isActive('bulletList')} onClick={() => editor?.chain().focus().toggleBulletList().run()}>•</ToolButton>
                        <span className="mx-1 h-4 w-px bg-slate-300" />
                        <ToolButton label="Quote" active={editor?.isActive('blockquote')} onClick={() => editor?.chain().focus().toggleBlockquote().run()}>❝</ToolButton>
                        <ToolButton label="Code" active={editor?.isActive('code')} onClick={() => editor?.chain().focus().toggleCode().run()}><code>{'</>'}</code></ToolButton>
                        <ToolButton label="Code block" active={editor?.isActive('codeBlock')} onClick={() => editor?.chain().focus().toggleCodeBlock().run()}><code>{'{ }'}</code></ToolButton>
                    </div>
                )}

                <EditorContent editor={editor} />

                {files.length > 0 && (
                    <ul className="flex flex-wrap gap-2 px-3 pb-2 pt-2">
                        {files.map((file, index) => (
                            <li
                                key={`${file.name}-${index}`}
                                className="flex max-w-[14rem] items-center gap-1.5 rounded-md border border-slate-200 bg-slate-50 py-1 pl-2 pr-1 text-sm"
                            >
                                <FileIcon className="h-4 w-4 shrink-0 text-sky-700" />
                                <span className="truncate">{file.name}</span>
                                <button
                                    type="button"
                                    aria-label={`Remove ${file.name}`}
                                    onClick={() => setFiles(files.filter((_, i) => i !== index))}
                                    className="rounded px-1 text-slate-500 hover:bg-slate-200"
                                >
                                    ×
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="flex items-center gap-0.5 px-1.5 pb-1.5">
                    <input
                        ref={picker}
                        type="file"
                        multiple
                        hidden
                        aria-label="Attach files"
                        onChange={(e) => {
                            addFiles(Array.from(e.target.files ?? []));
                            e.target.value = '';
                        }}
                    />
                    <ToolButton label="Attach files" onClick={() => picker.current?.click()}>
                        <PlusIcon className="h-4 w-4" />
                    </ToolButton>
                    <ToolButton label={showToolbar ? 'Hide formatting' : 'Show formatting'} onClick={() => setShowToolbar(!showToolbar)}>
                        <u>Aa</u>
                    </ToolButton>
                    <ToolButton label="Emoji" onClick={() => setEmojiOpen(!emojiOpen)}>
                        <SmileIcon className="h-4 w-4" />
                    </ToolButton>
                    <ToolButton label="Mention someone" onClick={() => editor?.chain().focus().insertContent(' @').run()}>
                        @
                    </ToolButton>
                    {onSchedule && (
                        <ToolButton label="Schedule message" onClick={() => setScheduleOpen(!scheduleOpen)}>
                            🕒
                        </ToolButton>
                    )}
                    <button
                        type="button"
                        onClick={send}
                        aria-label="Send message"
                        disabled={sending || (!text && files.length === 0)}
                        className="ml-auto rounded bg-teal-700 p-1.5 text-white hover:bg-teal-800 disabled:bg-transparent disabled:text-slate-400"
                    >
                        <SendIcon className="h-4 w-4" />
                    </button>
                </div>
            </div>
            {scheduleOpen && (
                <div className="mt-1 flex items-center gap-2">
                    <input
                        aria-label="Send at"
                        type="datetime-local"
                        value={sendAt}
                        onChange={(e) => setSendAt(e.target.value)}
                        className="rounded-md border-slate-300 py-1 text-sm"
                    />
                    <button
                        type="button"
                        onClick={schedule}
                        disabled={!text || !sendAt}
                        className="rounded-md bg-teal-700 px-3 py-1 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-40"
                    >
                        Schedule
                    </button>
                </div>
            )}
            {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
        </div>
    );
}
