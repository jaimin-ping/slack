import { FileIcon, FolderIcon, PlusIcon } from '@/Components/Chat/Icons';
import { WorkspaceInfo } from '@/types/chat';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

interface Folder {
    id: number;
    name: string;
    color: string;
    items_count: number;
}

interface FolderItem {
    id: number;
    attachment_id: number;
    attachment: {
        id: number;
        name: string;
        size: number;
        url: string;
        is_image: boolean;
    };
}

interface Props {
    show: boolean;
    workspace: WorkspaceInfo;
    onClose: () => void;
}

const FOLDER_COLORS = [
    '#3b82f6', // blue
    '#10b981', // green
    '#f59e0b', // amber
    '#ef4444', // red
    '#8b5cf6', // violet
    '#ec4899', // pink
    '#06b6d4', // cyan
    '#84cc16', // lime
];

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

export default function DirectoriesModal({ show, workspace, onClose }: Props) {
    const [folders, setFolders] = useState<Folder[]>([]);
    const [selectedFolder, setSelectedFolder] = useState<Folder | null>(null);
    const [folderItems, setFolderItems] = useState<FolderItem[]>([]);
    const [loading, setLoading] = useState(false);
    const [creating, setCreating] = useState(false);
    const [newFolderName, setNewFolderName] = useState('');
    const modalRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (show) {
            loadFolders();
        }
    }, [show]);

    useEffect(() => {
        if (creating && inputRef.current) {
            inputRef.current.focus();
        }
    }, [creating]);

    useEffect(() => {
        if (!show) return;

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
    }, [show, onClose]);

    const loadFolders = async () => {
        try {
            const { data } = await axios.get('/folders');
            setFolders(data);
            if (data.length > 0 && !selectedFolder) {
                setSelectedFolder(data[0]);
                loadFolderItems(data[0].id);
            }
        } catch (error) {
            console.error('Failed to load folders:', error);
        }
    };

    const loadFolderItems = async (folderId: number) => {
        setLoading(true);
        try {
            const { data } = await axios.get(`/folders/${folderId}/items`);
            setFolderItems(data);
        } catch (error) {
            console.error('Failed to load folder items:', error);
            setFolderItems([]);
        } finally {
            setLoading(false);
        }
    };

    const selectFolder = (folder: Folder) => {
        setSelectedFolder(folder);
        loadFolderItems(folder.id);
    };

    const createFolder = async () => {
        if (!newFolderName.trim()) return;

        try {
            const color = FOLDER_COLORS[folders.length % FOLDER_COLORS.length];
            const { data } = await axios.post('/folders', {
                name: newFolderName.trim(),
                color,
            });
            setFolders([...folders, data]);
            setNewFolderName('');
            setCreating(false);
            setSelectedFolder(data);
            setFolderItems([]);
        } catch (error) {
            console.error('Failed to create folder:', error);
        }
    };

    const deleteFolder = async (folderId: number) => {
        if (!confirm('Are you sure you want to delete this folder?')) return;

        try {
            await axios.delete(`/folders/${folderId}`);
            const updated = folders.filter(f => f.id !== folderId);
            setFolders(updated);
            if (selectedFolder?.id === folderId) {
                setSelectedFolder(updated[0] || null);
                if (updated[0]) {
                    loadFolderItems(updated[0].id);
                } else {
                    setFolderItems([]);
                }
            }
        } catch (error) {
            console.error('Failed to delete folder:', error);
        }
    };

    const removeItem = async (itemId: number) => {
        if (!selectedFolder) return;

        try {
            await axios.delete(`/folders/${selectedFolder.id}/items/${itemId}`);
            setFolderItems(folderItems.filter(item => item.id !== itemId));
            // Update folder count
            setFolders(folders.map(f =>
                f.id === selectedFolder.id
                    ? { ...f, items_count: f.items_count - 1 }
                    : f
            ));
        } catch (error) {
            console.error('Failed to remove item:', error);
        }
    };

    if (!show) return null;

    return (
        <div className="fixed inset-0 z-[200] flex items-center justify-center bg-black/50">
            <div
                ref={modalRef}
                className="flex h-[80vh] w-full max-w-4xl flex-col rounded-lg bg-white shadow-2xl"
            >
                {/* Header */}
                <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <div className="flex items-center gap-3">
                        <FolderIcon className="h-6 w-6 text-slate-600" />
                        <h2 className="text-xl font-bold text-slate-900">Directories</h2>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Content */}
                <div className="flex min-h-0 flex-1">
                    {/* Folders List */}
                    <div className="w-64 shrink-0 border-r border-slate-200 bg-slate-50">
                        <div className="p-4">
                            <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                                Folders
                            </h3>
                        </div>
                        <div className="space-y-1 px-2">
                            {folders.map(folder => (
                                <div
                                    key={folder.id}
                                    className={`group flex items-center justify-between rounded-md px-3 py-2 cursor-pointer ${
                                        selectedFolder?.id === folder.id
                                            ? 'bg-blue-100 text-blue-900'
                                            : 'hover:bg-slate-100 text-slate-700'
                                    }`}
                                    onClick={() => selectFolder(folder)}
                                >
                                    <div className="flex items-center gap-2 min-w-0">
                                        <span
                                            className="h-3 w-3 shrink-0 rounded"
                                            style={{ backgroundColor: folder.color }}
                                        />
                                        <span className="truncate text-sm font-medium">
                                            {folder.name}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <span className="text-xs text-slate-500">
                                            {folder.items_count}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                deleteFolder(folder.id);
                                            }}
                                            className="hidden rounded p-1 text-slate-400 hover:bg-slate-200 hover:text-red-600 group-hover:block"
                                        >
                                            <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            ))}

                            {/* New Folder Input */}
                            {creating ? (
                                <div className="mx-2 mt-2 rounded-md border border-slate-200 bg-white p-3 shadow-sm">
                                    <input
                                        ref={inputRef}
                                        type="text"
                                        placeholder="Folder name..."
                                        value={newFolderName}
                                        onChange={(e) => setNewFolderName(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') createFolder();
                                            if (e.key === 'Escape') {
                                                setCreating(false);
                                                setNewFolderName('');
                                            }
                                        }}
                                        className="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                                    />
                                    <div className="mt-3 flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={createFolder}
                                            disabled={!newFolderName.trim()}
                                            className="rounded-md bg-cyan-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            Create
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setCreating(false);
                                                setNewFolderName('');
                                            }}
                                            className="rounded-md px-4 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setCreating(true)}
                                    className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100"
                                >
                                    <PlusIcon className="h-4 w-4" />
                                    <span>New folder</span>
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Folder Contents */}
                    <div className="flex-1 overflow-y-auto p-6">
                        {selectedFolder ? (
                            <>
                                <div className="mb-4 flex items-center gap-2">
                                    <span
                                        className="h-4 w-4 rounded"
                                        style={{ backgroundColor: selectedFolder.color }}
                                    />
                                    <h3 className="text-lg font-semibold text-slate-900">
                                        {selectedFolder.name}
                                    </h3>
                                    <span className="text-sm text-slate-500">
                                        ({folderItems.length} items)
                                    </span>
                                </div>

                                {loading ? (
                                    <div className="flex items-center justify-center py-12">
                                        <div className="text-slate-500">Loading...</div>
                                    </div>
                                ) : folderItems.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center py-12 text-center">
                                        <FolderIcon className="h-12 w-12 text-slate-300" />
                                        <p className="mt-2 text-sm text-slate-500">
                                            This folder is empty
                                        </p>
                                        <p className="text-xs text-slate-400">
                                            Add files from attachments using "Add to folder"
                                        </p>
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                                        {folderItems.map(item => (
                                            <div
                                                key={item.id}
                                                className="group relative rounded-lg border border-slate-200 bg-white p-3 hover:border-slate-300 hover:shadow-sm"
                                            >
                                                {/* Remove button */}
                                                <button
                                                    type="button"
                                                    onClick={() => removeItem(item.id)}
                                                    className="absolute right-2 top-2 hidden rounded bg-white p-1 text-slate-400 shadow-sm hover:bg-red-50 hover:text-red-600 group-hover:block"
                                                >
                                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>

                                                {/* Thumbnail */}
                                                <a
                                                    href={item.attachment.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="block"
                                                >
                                                    {item.attachment.is_image ? (
                                                        <div className="mb-2 aspect-square overflow-hidden rounded bg-slate-100">
                                                            <img
                                                                src={item.attachment.url}
                                                                alt={item.attachment.name}
                                                                className="h-full w-full object-cover"
                                                            />
                                                        </div>
                                                    ) : (
                                                        <div className="mb-2 flex aspect-square items-center justify-center rounded bg-slate-100">
                                                            <FileIcon className="h-12 w-12 text-slate-400" />
                                                        </div>
                                                    )}
                                                    <p className="truncate text-sm font-medium text-slate-800">
                                                        {item.attachment.name}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {formatFileSize(item.attachment.size)}
                                                    </p>
                                                </a>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <FolderIcon className="h-12 w-12 text-slate-300" />
                                <p className="mt-2 text-sm text-slate-500">
                                    No folders yet
                                </p>
                                <p className="text-xs text-slate-400">
                                    Create a folder to organize your files
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
