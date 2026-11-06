export const REACTION_EMOJIS = ['👍', '❤️', '😂', '🎉', '😮', '😢', '👀', '✅', '🙏', '🔥'];

export const COMPOSER_EMOJIS = [
    '😀', '😄', '😂', '🙂', '😉', '😍', '😎', '🤔', '😅', '😢', '😮', '😴',
    '👍', '👎', '👏', '🙌', '🙏', '💪', '👀', '✅', '❌', '⚠️', '🔥', '🎉',
    '❤️', '💯', '🚀', '💡', '📌', '📎', '🌴', '☕',
];

export interface ChatUser {
    id: number;
    name: string;
    avatar_url?: string | null;
}

export interface WorkspaceMember extends ChatUser {
    status_emoji: string | null;
    status_text: string | null;
    is_away: boolean;
    in_dnd?: boolean;
    title?: string | null;
    phone?: string | null;
}

export interface MessageReaction {
    emoji: string;
    user_ids: number[];
}

export interface MessageAttachment {
    id: number;
    name: string;
    size: number;
    is_image: boolean;
    url: string;
}

export interface MessageCard {
    color: string;
    title: string;
    text: string;
    fields: { title: string; value: string }[];
}

export interface ChatMessage {
    id: number;
    channel_id: number;
    parent_id: number | null;
    body: string;
    created_at: string;
    edited_at: string | null;
    removed: boolean;
    pinned: boolean;
    link_preview: { url: string; title: string; description: string; site: string } | null;
    is_bot: boolean;
    user: ChatUser;
    cards: MessageCard[];
    reactions: MessageReaction[];
    attachments: MessageAttachment[];
    reply_count: number;
    last_reply_at: string | null;
    parent_reply_count: number | null;
}

export interface SearchResult extends ChatMessage {
    channel_label: string;
}

export interface ActiveChannel {
    id: number;
    name: string | null;
    type: 'public' | 'private' | 'dm';
    dm_user_id: number | null;
    topic?: string | null;
    can_manage?: boolean;
    can_delete?: boolean;
    can_leave?: boolean;
    archived?: boolean;
}

export interface SidebarChannel extends ActiveChannel {
    unread: number;
    starred: boolean;
    notify_all: boolean;
    muted: boolean;
    section_id: number | null;
}

export interface WorkspaceInfo {
    id: number;
    name: string;
    slug: string;
    is_owner: boolean;
    is_admin: boolean;
}

export interface UserGroupInfo {
    id: number;
    handle: string;
    member_ids: number[];
}

export interface CustomEmojiInfo {
    id: number;
    name: string;
    url: string;
}

export interface SectionInfo {
    id: number;
    name: string;
}

export interface WorkspaceExtras {
    groups: UserGroupInfo[];
    emojis: CustomEmojiInfo[];
    sections: SectionInfo[];
}

export type WorkspaceTool = 'groups' | 'emoji' | 'workflows' | 'export' | 'analytics' | 'audit';

export type RailView = 'home' | 'dms';
export type InfoPanelName = 'activity' | 'threads' | 'drafts' | 'files' | 'channels' | 'saved' | 'scheduled';
export type RailItem = RailView | 'activity' | 'files';

const AVATAR_COLORS = ['bg-teal-600', 'bg-amber-600', 'bg-sky-600', 'bg-rose-600', 'bg-violet-600', 'bg-lime-700'];

export const avatarColor = (userId: number) => AVATAR_COLORS[Math.abs(userId) % AVATAR_COLORS.length];
