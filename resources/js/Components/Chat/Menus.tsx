import Avatar from '@/Components/Chat/Avatar';
import { CaretIcon, SmileIcon } from '@/Components/Chat/Icons';
import { WorkspaceInfo, WorkspaceMember, WorkspaceTool } from '@/types/chat';
import { Link } from '@inertiajs/react';
import { ReactNode, useEffect, useRef, useState } from 'react';

const item = 'block w-full px-6 py-1 text-left text-[15px] text-slate-800 hover:bg-sky-700 hover:text-white';

function Popover({ onClose, className, children }: { onClose: () => void; className: string; children: ReactNode }) {
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const down = (e: MouseEvent) => {
            if (!box.current?.contains(e.target as Node)) onClose();
        };
        const key = (e: globalThis.KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('mousedown', down);
        document.addEventListener('keydown', key);

        return () => {
            document.removeEventListener('mousedown', down);
            document.removeEventListener('keydown', key);
        };
    }, [onClose]);

    return (
        <div
            ref={box}
            role="menu"
            className={`absolute z-50 rounded-lg border border-slate-200 bg-white py-2 text-slate-800 shadow-2xl ${className}`}
        >
            {children}
        </div>
    );
}

const Divider = () => <hr className="my-2 border-slate-200" />;

export function WorkspaceMenu({
    workspace,
    onClose,
    onInvite,
    onCustomize,
    onManageApps,
    onManageMembers,
    onPreferences,
    onTool,
}: {
    workspace: WorkspaceInfo;
    onClose: () => void;
    onInvite: () => void;
    onCustomize: () => void;
    onManageApps: () => void;
    onManageMembers: () => void;
    onPreferences: () => void;
    onTool: (tool: WorkspaceTool) => void;
}) {
    const [toolsOpen, setToolsOpen] = useState(false);

    return (
        <Popover onClose={onClose} className="left-2 top-11 w-80">
            <div className="flex items-center gap-3 px-6 py-2">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-teal-700 text-lg font-bold text-white">
                    {workspace.name.charAt(0).toUpperCase()}
                </span>
                <span className="truncate font-bold">{workspace.name}</span>
            </div>
            <Divider />
            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onInvite(); }}>
                Invite people to {workspace.name}
            </button>
            <Divider />
            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onPreferences(); }}>
                Preferences
            </button>
            {workspace.is_admin && (
                <div className="relative" onMouseEnter={() => setToolsOpen(true)} onMouseLeave={() => setToolsOpen(false)}>
                    <button
                        type="button"
                        role="menuitem"
                        aria-haspopup="menu"
                        aria-expanded={toolsOpen}
                        className={`${item} flex items-center justify-between`}
                        onClick={() => setToolsOpen(!toolsOpen)}
                    >
                        Tools &amp; settings
                        <CaretIcon open={false} className="h-3.5 w-3.5" />
                    </button>
                    {toolsOpen && (
                        <div role="menu" className="absolute left-full top-0 z-50 w-64 rounded-lg border border-slate-200 bg-white py-2 shadow-2xl">
                            <p className="px-6 pb-1 text-xs font-semibold text-slate-500">Tools</p>
                            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onCustomize(); }}>
                                Customize workspace
                            </button>
                            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onTool('workflows'); }}>
                                Workflow Builder
                            </button>
                            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onTool('groups'); }}>
                                User groups
                            </button>
                            <Divider />
                            <p className="px-6 pb-1 text-xs font-semibold text-slate-500">Administration</p>
                            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onManageApps(); }}>
                                Manage apps
                            </button>
                            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onManageMembers(); }}>
                                Manage members
                            </button>
                            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onTool('analytics'); }}>
                                Analytics
                            </button>
                            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onTool('audit'); }}>
                                Audit log
                            </button>
                            {workspace.is_owner && (
                                <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onTool('export'); }}>
                                    Export data
                                </button>
                            )}
                        </div>
                    )}
                </div>
            )}
            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onTool('emoji'); }}>
                Custom emoji
            </button>
            <Divider />
            <Link href={route('dashboard')} role="menuitem" className={item}>
                Switch workspace
            </Link>
            <Link href={route('logout')} method="post" as="button" role="menuitem" className={item}>
                Sign out
            </Link>
        </Popover>
    );
}

export function ProfileMenu({
    me,
    workspaceName,
    onClose,
    onStatus,
    onPhoto,
    onPreferences,
    onToggleAway,
}: {
    me: WorkspaceMember;
    workspaceName: string;
    onClose: () => void;
    onStatus: () => void;
    onPhoto: () => void;
    onPreferences: () => void;
    onToggleAway: () => void;
}) {
    return (
        <Popover onClose={onClose} className="bottom-0 left-full ml-2 w-72">
            <div className="flex items-center gap-3 px-6 py-2">
                <Avatar user={me} className="h-10 w-10 rounded-lg text-lg" />
                <span className="min-w-0">
                    <span className="block truncate font-bold">{me.name}</span>
                    <span className="flex items-center gap-1 text-sm text-slate-600">
                        <span className={`h-2 w-2 rounded-full ${me.is_away ? 'bg-slate-400' : 'bg-emerald-500'}`} />
                        {me.is_away ? 'Away' : 'Active'}
                    </span>
                </span>
            </div>
            <div className="px-6 py-1">
                <button
                    type="button"
                    role="menuitem"
                    onClick={() => { onClose(); onStatus(); }}
                    className="flex w-full items-center gap-2 rounded-md border border-slate-300 px-3 py-1.5 text-left text-sm text-slate-600 hover:border-slate-500"
                >
                    {me.status_emoji ?? <SmileIcon className="h-4 w-4" />}
                    <span className="truncate">{me.status_text || 'Update your status'}</span>
                </button>
            </div>
            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onToggleAway(); }}>
                Set yourself as <b>{me.is_away ? 'active' : 'away'}</b>
            </button>
            <Divider />
            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onPhoto(); }}>
                Change profile photo
            </button>
            <button type="button" role="menuitem" className={item} onClick={() => { onClose(); onPreferences(); }}>
                Preferences
            </button>
            <Link href={route('profile.edit')} role="menuitem" className={item}>
                Profile
            </Link>
            <Divider />
            <Link href={route('logout')} method="post" as="button" role="menuitem" className={item}>
                Sign out of {workspaceName}
            </Link>
        </Popover>
    );
}
