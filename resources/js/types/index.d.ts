export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    theme?: 'light' | 'dark' | 'system';
    two_factor_enabled?: boolean;
    title?: string | null;
    phone?: string | null;
    dnd_until?: string | null;
    dnd_from?: string | null;
    dnd_to?: string | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
};
