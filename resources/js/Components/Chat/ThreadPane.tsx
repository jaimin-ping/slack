import Composer from '@/Components/Chat/Composer';
import MessageList from '@/Components/Chat/MessageList';
import { ChatMessage, ChatUser } from '@/types/chat';
import { useEffect } from 'react';

interface Props {
    parent: ChatMessage;
    replies: ChatMessage[];
    channelLabel: string;
    me: ChatUser;
    members: ChatUser[];
    people: ChatUser[];
    onClose: () => void;
    onSend: (body: string, files: File[]) => Promise<void>;
    onReact: (messageId: number, emoji: string) => void;
    onEdit: (messageId: number, body: string) => Promise<void>;
    onDelete: (message: ChatMessage) => void;
    canModerate: boolean;
    savedIds: Set<number>;
    onPin: (messageId: number) => void;
    onSave: (messageId: number) => void;
    onForward: (message: ChatMessage) => void;
}

export default function ThreadPane({
    parent,
    replies,
    channelLabel,
    me,
    members,
    people,
    onClose,
    onSend,
    onReact,
    onEdit,
    onDelete,
    canModerate,
    savedIds,
    onPin,
    onSave,
    onForward,
}: Props) {
    useEffect(() => {
        const key = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', key);

        return () => document.removeEventListener('keydown', key);
    }, [onClose]);

    return (
        <aside aria-label="Thread" className="flex min-w-0 flex-1 flex-col border-l border-slate-200 md:w-96 md:flex-none">
            <header className="flex h-12 shrink-0 items-center justify-between border-b border-slate-200 px-4">
                <h2 className="min-w-0 truncate font-bold text-slate-900">
                    Thread <span className="text-sm font-normal text-slate-500">{channelLabel}</span>
                </h2>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close thread"
                    className="rounded px-2 py-0.5 text-lg leading-none text-slate-600 hover:bg-slate-100"
                >
                    ×
                </button>
            </header>

            <MessageList
                key={parent.id}
                messages={[parent, ...replies]}
                hasMore={false}
                me={me}
                members={members}
                emptyText=""
                newLineId={null}
                onLoadOlder={async () => {}}
                onReact={onReact}
                onEdit={onEdit}
                onDelete={onDelete}
                canModerate={canModerate}
                savedIds={savedIds}
                onPin={onPin}
                onSave={onSave}
                onForward={onForward}
            />

            <div className="pb-3">
                <Composer key={`reply-${parent.id}`} placeholder="Reply…" members={people} onSend={onSend} />
            </div>
        </aside>
    );
}
