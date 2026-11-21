import Avatar from '@/Components/Chat/Avatar';
import { ChatIcon } from '@/Components/Chat/Icons';
import { WorkspaceMember } from '@/types/chat';
import { Link } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

type Props = {
    member: WorkspaceMember;
    anchor: DOMRect;
    meId: number;
    online: boolean;
    onMessage: (member: WorkspaceMember) => void;
    onSetStatus: () => void;
    onClose: () => void;
};

const CARD_WIDTH = 300;
const GAP = 8;

function localTime(timezone?: string | null): string | null {
    if (!timezone) return null;
    try {
        return new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', timeZone: timezone });
    } catch {
        return null; // Unknown zone name.
    }
}

const roleBadge = (role?: string) =>
    role === 'owner' ? 'Workspace Owner' : role === 'admin' ? 'Workspace Admin' : null;

/** Slack-style popover shown when a name or avatar is clicked. */
export default function ProfileCard({ member, anchor, meId, online, onMessage, onSetStatus, onClose }: Props) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ left: anchor.right + GAP, top: anchor.top });
    const [time, setTime] = useState(() => localTime(member.timezone));
    const isMe = member.id === meId;

    // Keep the card next to what was clicked, but always on screen.
    useLayoutEffect(() => {
        const height = ref.current?.offsetHeight ?? 360;
        let left = anchor.right + GAP;
        if (left + CARD_WIDTH > window.innerWidth - GAP) left = Math.max(GAP, anchor.left - CARD_WIDTH - GAP);
        const top = Math.min(Math.max(GAP, anchor.top), window.innerHeight - height - GAP);
        setPos({ left, top: Math.max(GAP, top) });
    }, [anchor]);

    useEffect(() => {
        const away = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) onClose();
        };
        const esc = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        const timer = window.setInterval(() => setTime(localTime(member.timezone)), 30_000);
        document.addEventListener('mousedown', away);
        document.addEventListener('keydown', esc);
        window.addEventListener('resize', onClose);
        return () => {
            window.clearInterval(timer);
            document.removeEventListener('mousedown', away);
            document.removeEventListener('keydown', esc);
            window.removeEventListener('resize', onClose);
        };
    }, [member.timezone, onClose]);

    const active = online && !member.is_away;
    const badge = roleBadge(member.role);

    return (
        <div
            ref={ref}
            role="dialog"
            aria-label={`Profile of ${member.name}`}
            style={{ left: pos.left, top: pos.top, width: CARD_WIDTH }}
            className="fixed z-50 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl"
        >
            <div className="flex justify-center bg-slate-50 p-5">
                <Avatar user={member} className="h-28 w-28 rounded-xl text-4xl" />
            </div>

            <div className="space-y-2 px-5 py-4">
                <div>
                    <p className="flex items-center gap-2 text-lg font-bold text-slate-900">
                        <span className="truncate">{member.name}</span>
                        <span
                            className={`h-2.5 w-2.5 shrink-0 rounded-full ${active ? 'bg-emerald-500' : 'border-2 border-slate-400'}`}
                            title={active ? 'Active' : 'Away'}
                        />
                    </p>
                    {member.title && <p className="text-sm text-slate-700">{member.title}</p>}
                    {badge && (
                        <span className="mt-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-600">
                            {badge}
                        </span>
                    )}
                </div>

                {(member.status_emoji || member.status_text) && (
                    <p className="flex items-center gap-2 text-sm text-slate-700">
                        <span>{member.status_emoji}</span>
                        <span className="truncate">{member.status_text}</span>
                    </p>
                )}

                <ul className="space-y-1 text-sm text-slate-600">
                    <li>{active ? 'Active' : 'Away'}</li>
                    {member.in_dnd && <li>🔕 Notifications paused</li>}
                    {time && <li>🕒 {time} local time</li>}
                    {member.phone && (
                        <li>
                            📞{' '}
                            <a href={`tel:${member.phone}`} className="text-sky-700 hover:underline">
                                {member.phone}
                            </a>
                        </li>
                    )}
                </ul>

                <div className="flex gap-2 pt-2">
                    {isMe ? (
                        <>
                            <button
                                type="button"
                                onClick={() => {
                                    onSetStatus();
                                    onClose();
                                }}
                                className="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                            >
                                Set a status
                            </button>
                            <Link
                                href={route('profile.edit')}
                                className="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-center text-sm font-semibold text-slate-800 hover:bg-slate-50"
                            >
                                Edit profile
                            </Link>
                        </>
                    ) : (
                        <button
                            type="button"
                            onClick={() => onMessage(member)}
                            className="flex flex-1 items-center justify-center gap-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                        >
                            <ChatIcon className="h-4 w-4" />
                            Message
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
