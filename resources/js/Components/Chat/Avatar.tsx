import { avatarColor, ChatUser } from '@/types/chat';

export default function Avatar({ user, className }: { user: ChatUser; className: string }) {
    if (user.avatar_url) {
        return <img src={user.avatar_url} alt="" className={`shrink-0 object-cover ${className}`} />;
    }

    return (
        <span
            aria-hidden
            className={`flex shrink-0 items-center justify-center font-semibold text-white ${avatarColor(user.id)} ${className}`}
        >
            {user.name.charAt(0).toUpperCase()}
        </span>
    );
}
