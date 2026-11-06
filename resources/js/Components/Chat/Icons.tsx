import { ReactNode, SVGAttributes } from 'react';

function Icon({ children, ...props }: SVGAttributes<SVGElement> & { children: ReactNode }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden
            {...props}
        >
            {children}
        </svg>
    );
}

type P = SVGAttributes<SVGElement>;

export const HomeIcon = (p: P) => <Icon {...p}><path d="M4 11l8-7 8 7v8a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1v-8Z" /></Icon>;
export const ChatIcon = (p: P) => <Icon {...p}><path d="M5 5h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H10l-5 4v-4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z" /></Icon>;
export const BellIcon = (p: P) => <Icon {...p}><path d="M6 16V11a6 6 0 1 1 12 0v5l2 2H4l2-2ZM10 21h4" /></Icon>;
export const SearchIcon = (p: P) => <Icon {...p}><circle cx="11" cy="11" r="6.5" /><path d="m20 20-4.2-4.2" /></Icon>;
export const BackIcon = (p: P) => <Icon {...p}><path d="M19 12H5m6-6-6 6 6 6" /></Icon>;
export const ForwardIcon = (p: P) => <Icon {...p}><path d="M5 12h14m-6-6 6 6-6 6" /></Icon>;
export const LockIcon = (p: P) => <Icon {...p}><rect x="5" y="11" width="14" height="9" rx="1.5" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></Icon>;
export const StarIcon = ({ filled, ...p }: P & { filled?: boolean }) => (
    <Icon {...p} fill={filled ? 'currentColor' : 'none'}>
        <path d="m12 4 2.5 5.2 5.5.8-4 4 1 5.6-5-2.7-5 2.7 1-5.6-4-4 5.5-.8L12 4Z" />
    </Icon>
);
export const CaretIcon = ({ open, ...p }: P & { open: boolean }) => (
    <Icon {...p} className={`${p.className ?? ''} transition-transform ${open ? 'rotate-90' : ''}`}>
        <path d="m9 6 6 6-6 6" />
    </Icon>
);
export const PlusIcon = (p: P) => <Icon {...p}><path d="M12 5v14M5 12h14" /></Icon>;
export const UsersIcon = (p: P) => <Icon {...p}><circle cx="9" cy="8" r="3.5" /><path d="M3 20c.5-4 3-6 6-6s5.5 2 6 6M16 5a3.5 3.5 0 0 1 0 6.5M18 14c2 .7 3 2.7 3.3 6" /></Icon>;
export const SendIcon = (p: P) => <Icon {...p}><path d="M4 12 20 4l-4 16-4-7-8-1Z" /></Icon>;
export const ComposeIcon = (p: P) => <Icon {...p}><path d="M12 5H6a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6M10 14l1-4 8-7 2 2-7 8-4 1Z" /></Icon>;
export const FileIcon = (p: P) => <Icon {...p}><path d="M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1ZM14 3v5h5" /></Icon>;
export const PencilIcon = (p: P) => <Icon {...p}><path d="m4 20 1-4L16 5l3 3L8 19l-4 1Z" /></Icon>;
export const TrashIcon = (p: P) => <Icon {...p}><path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13M10 11v5M14 11v5" /></Icon>;
export const ThreadIcon = (p: P) => <Icon {...p}><path d="M4 6h16v9H9l-5 4V6ZM8 10h8" /></Icon>;
export const HeadphonesIcon = (p: P) => <Icon {...p}><path d="M4 15v-3a8 8 0 0 1 16 0v3M4 15a2 2 0 0 1 2-2h1v7H6a2 2 0 0 1-2-2v-3ZM20 15a2 2 0 0 0-2-2h-1v7h1a2 2 0 0 0 2-2v-3Z" /></Icon>;
export const PinIcon = (p: P) => <Icon {...p}><path d="m14 4 6 6-3 1-4 4 1 4-2 1-4-4-4 4M9 12l-4-4 1-2 4 1 4-4" /></Icon>;
export const BookmarkIcon = ({ filled, ...p }: P & { filled?: boolean }) => <Icon {...p} fill={filled ? 'currentColor' : 'none'}><path d="M7 4h10v16l-5-3.5L7 20V4Z" /></Icon>;
export const ShareIcon = (p: P) => <Icon {...p}><path d="M14 6l6 6-6 6M20 12H9a5 5 0 0 0-5 5v1" /></Icon>;
export const SmileIcon = (p: P) => <Icon {...p}><circle cx="12" cy="12" r="8.5" /><path d="M8.5 14a4 4 0 0 0 7 0M9 10h.01M15 10h.01" /></Icon>;
