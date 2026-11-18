import { FileIcon, SearchIcon, LinkIcon } from '@/Components/Chat/Icons';
import { ChatUser, MessageAttachment } from '@/types/chat';
import { useEffect, useRef, useState } from 'react';

interface Channel {
    id: number;
    name: string;
    is_private: boolean;
}

interface Props {
    attachment: MessageAttachment;
    channels: Channel[];
    people: ChatUser[];
    me: ChatUser;
    onClose: () => void;
    onShare: (attachment: MessageAttachment, targetType: 'channel' | 'user', targetId: number, message: string) => Promise<void>;
}

function ChannelIcon({ isPrivate }: { isPrivate: boolean }) {
    if (isPrivate) {
        return (
            <svg className="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}>
                <rect x="5" y="11" width="14" height="9" rx="1.5" />
                <path d="M8 11V8a4 4 0 0 1 8 0v3" />
            </svg>
        );
    }
    return (
        <span className="text-slate-500 font-medium text-sm">#</span>
    );
}

function UserAvatar({ user, size = 'sm' }: { user: ChatUser; size?: 'sm' | 'md' }) {
    const sizeClass = size === 'sm' ? 'h-6 w-6 text-xs' : 'h-8 w-8 text-sm';

    if (user.avatar_url) {
        return (
            <img
                src={user.avatar_url}
                alt={user.name}
                className={`${sizeClass} rounded object-cover`}
            />
        );
    }

    return (
        <div className={`${sizeClass} rounded bg-slate-300 flex items-center justify-center font-medium text-slate-600`}>
            {user.name.charAt(0).toUpperCase()}
        </div>
    );
}

export default function ShareFileModal({
    attachment,
    channels,
    people,
    me,
    onClose,
    onShare,
}: Props) {
    const [search, setSearch] = useState('');
    const [message, setMessage] = useState('');
    const [selectedTarget, setSelectedTarget] = useState<{ type: 'channel' | 'user'; id: number; name: string } | null>(null);
    const [isSharing, setIsSharing] = useState(false);
    const [copied, setCopied] = useState(false);
    const modalRef = useRef<HTMLDivElement>(null);
    const searchRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        searchRef.current?.focus();

        const handleEscape = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };

        const handleClickOutside = (e: MouseEvent) => {
            if (modalRef.current && !modalRef.current.contains(e.target as Node)) {
                onClose();
            }
        };

        document.addEventListener('keydown', handleEscape);
        document.addEventListener('mousedown', handleClickOutside);

        return () => {
            document.removeEventListener('keydown', handleEscape);
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, [onClose]);

    const filteredChannels = channels.filter(ch =>
        ch.name.toLowerCase().includes(search.toLowerCase())
    );

    const filteredPeople = people.filter(p =>
        p.id !== me.id && p.name.toLowerCase().includes(search.toLowerCase())
    );

    const handleCopyLink = () => {
        const link = window.location.origin + attachment.url;
        navigator.clipboard.writeText(link);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const handleShare = async () => {
        if (!selectedTarget) return;

        setIsSharing(true);
        try {
            await onShare(attachment, selectedTarget.type, selectedTarget.id, message);
            onClose();
        } catch (error) {
            console.error('Share failed:', error);
        } finally {
            setIsSharing(false);
        }
    };

    const selectTarget = (type: 'channel' | 'user', id: number, name: string) => {
        setSelectedTarget({ type, id, name });
    };

    const formatFileSize = (bytes: number) => {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    };

    return (
        <div className="fixed inset-0 z-[200] flex items-center justify-center bg-black/50">
            <div
                ref={modalRef}
                className="w-full max-w-lg rounded-lg bg-white shadow-2xl"
            >
                {/* Header */}
                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h2 className="text-lg font-bold text-slate-900">Share this file</h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Search */}
                <div className="border-b border-slate-200 px-4 py-3">
                    <div className="relative">
                        <SearchIcon className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            ref={searchRef}
                            type="text"
                            placeholder="Search for a channel or person"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full rounded-md border border-slate-300 py-2 pl-10 pr-4 text-sm placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        />
                    </div>

                    {selectedTarget && (
                        <div className="mt-2 flex items-center gap-2">
                            <span className="text-sm text-slate-500">Share to:</span>
                            <span className="inline-flex items-center gap-1 rounded bg-blue-100 px-2 py-0.5 text-sm font-medium text-blue-800">
                                {selectedTarget.type === 'channel' ? '#' : '@'}{selectedTarget.name}
                                <button
                                    type="button"
                                    onClick={() => setSelectedTarget(null)}
                                    className="ml-1 text-blue-600 hover:text-blue-800"
                                >
                                    ×
                                </button>
                            </span>
                        </div>
                    )}
                </div>

                {/* Channels & People List */}
                {!selectedTarget && (
                    <div className="max-h-64 overflow-y-auto">
                        {filteredChannels.length > 0 && (
                            <div>
                                <div className="px-4 py-2 text-xs font-semibold uppercase text-slate-500">
                                    Channels
                                </div>
                                {filteredChannels.map(channel => (
                                    <button
                                        key={`channel-${channel.id}`}
                                        type="button"
                                        onClick={() => selectTarget('channel', channel.id, channel.name)}
                                        className="flex w-full items-center gap-3 px-4 py-2 text-left hover:bg-slate-50"
                                    >
                                        <ChannelIcon isPrivate={channel.is_private} />
                                        <span className="text-sm text-slate-800">{channel.name}</span>
                                    </button>
                                ))}
                            </div>
                        )}

                        {filteredPeople.length > 0 && (
                            <div>
                                <div className="px-4 py-2 text-xs font-semibold uppercase text-slate-500">
                                    Direct Messages
                                </div>
                                {filteredPeople.map(person => (
                                    <button
                                        key={`user-${person.id}`}
                                        type="button"
                                        onClick={() => selectTarget('user', person.id, person.name)}
                                        className="flex w-full items-center gap-3 px-4 py-2 text-left hover:bg-slate-50"
                                    >
                                        <UserAvatar user={person} />
                                        <span className="text-sm text-slate-800">{person.name}</span>
                                    </button>
                                ))}
                            </div>
                        )}

                        {filteredChannels.length === 0 && filteredPeople.length === 0 && (
                            <div className="px-4 py-8 text-center text-sm text-slate-500">
                                No channels or people found
                            </div>
                        )}
                    </div>
                )}

                {/* Message Input (shown when target selected) */}
                {selectedTarget && (
                    <div className="px-4 py-3">
                        <textarea
                            placeholder="Add a message (optional)"
                            value={message}
                            onChange={(e) => setMessage(e.target.value)}
                            rows={3}
                            className="w-full resize-none rounded-md border border-slate-300 px-3 py-2 text-sm placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        />
                    </div>
                )}

                {/* File Preview */}
                <div className="border-t border-slate-200 px-4 py-3">
                    <div className="flex items-center gap-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                        {attachment.is_image ? (
                            <img
                                src={attachment.url}
                                alt={attachment.name}
                                className="h-12 w-12 rounded object-cover"
                            />
                        ) : (
                            <div className="flex h-12 w-12 items-center justify-center rounded bg-slate-200">
                                <FileIcon className="h-6 w-6 text-slate-500" />
                            </div>
                        )}
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-slate-800">{attachment.name}</p>
                            <p className="text-xs text-slate-500">
                                {formatFileSize(attachment.size)}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Footer */}
                <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                    <button
                        type="button"
                        onClick={handleCopyLink}
                        className="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        <LinkIcon className="h-4 w-4" />
                        {copied ? 'Copied!' : 'Copy Link'}
                    </button>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={handleShare}
                            disabled={!selectedTarget || isSharing}
                            className="rounded-md bg-green-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isSharing ? 'Sharing...' : 'Share'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
