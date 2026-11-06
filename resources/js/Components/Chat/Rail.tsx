import Avatar from '@/Components/Chat/Avatar';
import { BellIcon, ChatIcon, FileIcon, HomeIcon } from '@/Components/Chat/Icons';
import { ProfileMenu } from '@/Components/Chat/Menus';
import { RailItem, WorkspaceInfo, WorkspaceMember } from '@/types/chat';
import { Link } from '@inertiajs/react';
import { ComponentType, SVGAttributes, useCallback, useState } from 'react';

interface Props {
    workspace: WorkspaceInfo;
    me: WorkspaceMember;
    current: RailItem | null;
    activityUnread: number;
    onChange: (item: RailItem) => void;
    onStatus: () => void;
    onPhoto: () => void;
    onPreferences: () => void;
    onToggleAway: () => void;
}

const ITEMS: { item: RailItem; label: string; icon: ComponentType<SVGAttributes<SVGElement>> }[] = [
    { item: 'home', label: 'Home', icon: HomeIcon },
    { item: 'dms', label: 'DMs', icon: ChatIcon },
    { item: 'activity', label: 'Activity', icon: BellIcon },
    { item: 'files', label: 'Files', icon: FileIcon },
];

export default function Rail({ workspace, me, current, activityUnread, onChange, onStatus, onPhoto, onPreferences, onToggleAway }: Props) {
    const [menuOpen, setMenuOpen] = useState(false);
    const closeMenu = useCallback(() => setMenuOpen(false), []);

    return (
        <nav aria-label="Main" className="hidden w-[70px] shrink-0 flex-col items-center gap-3 pb-3 pt-1 md:flex">
            <Link
                href={route('dashboard')}
                title="Switch workspace"
                className="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-50 text-base font-bold text-teal-800"
            >
                {workspace.name.charAt(0).toUpperCase()}
            </Link>

            {ITEMS.map(({ item, label, icon: ItemIcon }) => (
                <button
                    key={item}
                    type="button"
                    onClick={() => onChange(item)}
                    aria-current={current === item ? 'page' : undefined}
                    className="group relative flex flex-col items-center gap-0.5 text-[11px] font-semibold text-white"
                >
                    <span
                        className={`flex h-9 w-9 items-center justify-center rounded-lg ${
                            current === item ? 'bg-white/25' : 'group-hover:bg-white/15'
                        }`}
                    >
                        <ItemIcon className="h-5 w-5" />
                    </span>
                    {label}
                    {item === 'activity' && activityUnread > 0 && (
                        <span className="absolute -right-1 -top-1 rounded-full bg-amber-400 px-1 text-[10px] font-bold text-slate-900">
                            {activityUnread}
                        </span>
                    )}
                </button>
            ))}

            <div className="relative mt-auto">
                <button
                    type="button"
                    onClick={() => setMenuOpen(!menuOpen)}
                    aria-haspopup="menu"
                    aria-expanded={menuOpen}
                    aria-label="Your profile and status"
                    className="relative block"
                >
                    <Avatar user={me} className="h-9 w-9 rounded-lg" />
                    <span
                        className={`absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-[#0b2523] ${
                            me.is_away ? 'bg-slate-400' : 'bg-emerald-400'
                        }`}
                    />
                </button>

                {menuOpen && (
                    <ProfileMenu
                        me={me}
                        workspaceName={workspace.name}
                        onClose={closeMenu}
                        onStatus={onStatus}
                        onPhoto={onPhoto}
                        onPreferences={onPreferences}
                        onToggleAway={onToggleAway}
                    />
                )}
            </div>
        </nav>
    );
}
