import {
    BookmarkIcon,
    CheckCircleIcon,
    DownloadIcon,
    ExternalLinkIcon,
    FolderPlusIcon,
    ImageIcon,
    InfoIcon,
    LinkIcon,
    ListIcon,
    PlusIcon,
    ShareIcon,
    TrashIcon,
} from '@/Components/Chat/Icons';
import { MessageAttachment } from '@/types/chat';
import axios from 'axios';
import { ReactNode, useEffect, useRef, useState } from 'react';

interface Folder {
    id: number;
    name: string;
    color: string;
}

interface Props {
    attachment: MessageAttachment;
    position: { x: number; y: number };
    onClose: () => void;
    onAddToSaved: (attachment: MessageAttachment) => void;
    onAddToTodo: (attachment: MessageAttachment) => void;
    onShare: (attachment: MessageAttachment) => void;
    onViewDetails: (attachment: MessageAttachment) => void;
    onDelete: (attachment: MessageAttachment) => void;
    canDelete: boolean;
}

const item = 'flex w-full items-center gap-3 px-4 py-2 text-left text-[13px] text-slate-800 hover:bg-slate-100 cursor-pointer';
const dangerItem = 'flex w-full items-center gap-3 px-4 py-2 text-left text-[13px] text-rose-600 hover:bg-rose-50 cursor-pointer';

function MenuItem({ icon, children, onClick, danger }: { icon: ReactNode; children: ReactNode; onClick: () => void; danger?: boolean }) {
    return (
        <button type="button" role="menuitem" className={danger ? dangerItem : item} onClick={onClick}>
            {icon}
            <span>{children}</span>
        </button>
    );
}

function SubMenu({ icon, label, items }: { icon: ReactNode; label: string; items: ReactNode }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="relative" onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)}>
            <button
                type="button"
                role="menuitem"
                aria-haspopup="menu"
                aria-expanded={open}
                className={`${item} justify-between`}
                onClick={() => setOpen(!open)}
            >
                <span className="flex items-center gap-3">
                    {icon}
                    <span>{label}</span>
                </span>
                <svg className="h-3 w-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
            </button>
            {open && (
                <div role="menu" className="absolute left-full top-0 ml-1 z-[60] min-w-[180px] rounded-md border border-slate-200 bg-white py-1.5 shadow-lg">
                    {items}
                </div>
            )}
        </div>
    );
}

const Divider = () => <hr className="my-1 border-slate-100" />;

function FolderSubMenu({ attachment, onClose }: { attachment: MessageAttachment; onClose: () => void }) {
    const [open, setOpen] = useState(false);
    const [folders, setFolders] = useState<Folder[]>([]);
    const [loading, setLoading] = useState(false);
    const [creating, setCreating] = useState(false);
    const [newName, setNewName] = useState('');

    useEffect(() => {
        if (open && folders.length === 0) {
            loadFolders();
        }
    }, [open]);

    const loadFolders = async () => {
        setLoading(true);
        try {
            const { data } = await axios.get('/folders');
            setFolders(data);
        } catch (error) {
            console.error('Failed to load folders:', error);
        } finally {
            setLoading(false);
        }
    };

    const addToFolder = async (folderId: number) => {
        try {
            await axios.post(`/folders/${folderId}/items`, {
                attachment_id: attachment.id,
            });
            onClose();
        } catch (error) {
            console.error('Failed to add to folder:', error);
        }
    };

    const createAndAdd = async () => {
        if (!newName.trim()) return;
        try {
            const { data } = await axios.post('/folders', {
                name: newName.trim(),
            });
            await axios.post(`/folders/${data.id}/items`, {
                attachment_id: attachment.id,
            });
            onClose();
        } catch (error) {
            console.error('Failed to create folder:', error);
        }
    };

    return (
        <div className="relative" onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)}>
            <button
                type="button"
                role="menuitem"
                aria-haspopup="menu"
                aria-expanded={open}
                className={`${item} justify-between`}
                onClick={() => setOpen(!open)}
            >
                <span className="flex items-center gap-3">
                    <FolderPlusIcon className="h-4 w-4" />
                    <span>Add to folder</span>
                </span>
                <svg className="h-3 w-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
            </button>
            {open && (
                <div role="menu" className="absolute left-full top-0 ml-1 z-[60] min-w-[200px] rounded-md border border-slate-200 bg-white py-1.5 shadow-lg">
                    {loading ? (
                        <div className="px-4 py-2 text-sm text-slate-500">Loading...</div>
                    ) : (
                        <>
                            {folders.map(folder => (
                                <button
                                    key={folder.id}
                                    type="button"
                                    onClick={() => addToFolder(folder.id)}
                                    className={item}
                                >
                                    <span
                                        className="h-3 w-3 rounded"
                                        style={{ backgroundColor: folder.color }}
                                    />
                                    <span>{folder.name}</span>
                                </button>
                            ))}
                            {folders.length > 0 && <Divider />}
                            {creating ? (
                                <div className="px-3 py-2">
                                    <input
                                        type="text"
                                        placeholder="Folder name"
                                        value={newName}
                                        onChange={(e) => setNewName(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') createAndAdd();
                                            if (e.key === 'Escape') setCreating(false);
                                        }}
                                        autoFocus
                                        className="w-full rounded border border-slate-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none"
                                    />
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setCreating(true)}
                                    className={item}
                                >
                                    <PlusIcon className="h-4 w-4" />
                                    <span>New folder</span>
                                </button>
                            )}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

export default function AttachmentContextMenu({
    attachment,
    position,
    onClose,
    onAddToSaved,
    onAddToTodo,
    onShare,
    onViewDetails,
    onDelete,
    canDelete,
}: Props) {
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const down = (e: MouseEvent) => {
            if (!box.current?.contains(e.target as Node)) onClose();
        };
        const key = (e: globalThis.KeyboardEvent) => e.key === 'Escape' && onClose();
        const scroll = () => onClose();

        document.addEventListener('mousedown', down);
        document.addEventListener('keydown', key);
        window.addEventListener('scroll', scroll, true); // true for capture phase to catch all scrolls

        return () => {
            document.removeEventListener('mousedown', down);
            document.removeEventListener('keydown', key);
            window.removeEventListener('scroll', scroll, true);
        };
    }, [onClose]);

    // Adjust position to stay within viewport
    const adjustedPosition = { ...position };
    if (typeof window !== 'undefined') {
        const menuWidth = 220;
        const menuHeight = 350;
        if (position.x + menuWidth > window.innerWidth) {
            adjustedPosition.x = window.innerWidth - menuWidth - 10;
        }
        if (position.y + menuHeight > window.innerHeight) {
            adjustedPosition.y = window.innerHeight - menuHeight - 10;
        }
    }

    const copyLink = () => {
        navigator.clipboard.writeText(window.location.origin + attachment.url);
        onClose();
    };

    const copyImage = async () => {
        if (attachment.is_image) {
            try {
                const response = await fetch(attachment.url);
                const blob = await response.blob();
                await navigator.clipboard.write([
                    new ClipboardItem({ [blob.type]: blob }),
                ]);
            } catch {
                // Fallback: copy URL
                navigator.clipboard.writeText(window.location.origin + attachment.url);
            }
        }
        onClose();
    };

    const openInBrowser = () => {
        window.open(attachment.url, '_blank');
        onClose();
    };

    const download = () => {
        const link = document.createElement('a');
        link.href = attachment.url;
        link.download = attachment.name;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        onClose();
    };

    return (
        <div
            ref={box}
            role="menu"
            style={{ left: adjustedPosition.x, top: adjustedPosition.y }}
            className="fixed z-[100] min-w-[220px] rounded-md border border-slate-200 bg-white py-1.5 text-slate-800 shadow-xl"
        >
            <MenuItem
                icon={<BookmarkIcon className="h-4 w-4" />}
                onClick={() => { onAddToSaved(attachment); onClose(); }}
            >
                Add to saved
            </MenuItem>
            <MenuItem
                icon={<CheckCircleIcon className="h-4 w-4" />}
                onClick={() => { onAddToTodo(attachment); onClose(); }}
            >
                Add to-do
            </MenuItem>
            <MenuItem
                icon={<ShareIcon className="h-4 w-4" />}
                onClick={() => { onShare(attachment); onClose(); }}
            >
                Share file...
            </MenuItem>

            <Divider />

            <MenuItem
                icon={<LinkIcon className="h-4 w-4" />}
                onClick={copyLink}
            >
                Copy link
            </MenuItem>
            {attachment.is_image && (
                <MenuItem
                    icon={<ImageIcon className="h-4 w-4" />}
                    onClick={copyImage}
                >
                    Copy image
                </MenuItem>
            )}

            <Divider />

            <SubMenu
                icon={<ExternalLinkIcon className="h-4 w-4" />}
                label="Open in"
                items={
                    <>
                        <MenuItem icon={<ExternalLinkIcon className="h-4 w-4" />} onClick={openInBrowser}>
                            New browser tab
                        </MenuItem>
                        <MenuItem icon={<DownloadIcon className="h-4 w-4" />} onClick={download}>
                            Download
                        </MenuItem>
                    </>
                }
            />
            <MenuItem
                icon={<InfoIcon className="h-4 w-4" />}
                onClick={() => { onViewDetails(attachment); onClose(); }}
            >
                View file details
            </MenuItem>

            <Divider />

            <FolderSubMenu
                attachment={attachment}
                onClose={onClose}
            />
            <SubMenu
                icon={<ListIcon className="h-4 w-4" />}
                label="Add to section"
                items={
                    <div className="px-4 py-2 text-sm text-slate-500">
                        No sections yet
                    </div>
                }
            />

            {canDelete && (
                <>
                    <Divider />
                    <MenuItem
                        icon={<TrashIcon className="h-4 w-4" />}
                        onClick={() => { onDelete(attachment); onClose(); }}
                        danger
                    >
                        Delete file
                    </MenuItem>
                </>
            )}
        </div>
    );
}
