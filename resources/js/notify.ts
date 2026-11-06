import axios from 'axios';

export type NotificationState = 'unsupported' | NotificationPermission;

export function notificationState(): NotificationState {
    return 'Notification' in window ? Notification.permission : 'unsupported';
}

export async function requestNotifications(): Promise<NotificationState> {
    if (!('Notification' in window)) return 'unsupported';

    return Notification.requestPermission();
}

export function showNotification(options: { title: string; body: string; tag: string; onClick: () => void }) {
    if (notificationState() !== 'granted') return;

    try {
        const notification = new Notification(options.title, { body: options.body, tag: options.tag });
        notification.onclick = () => {
            window.focus();
            options.onClick();
            notification.close();
        };
    } catch {
        // Some browsers refuse page notifications; the Activity list still has the item.
    }
}

function decodeKey(key: string): Uint8Array<ArrayBuffer> {
    const base64 = (key + '='.repeat((4 - (key.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}

/** Connects this browser to push, so notifications arrive even with every Ping Chat tab closed. */
export async function ensurePush(publicKey: string | null | undefined): Promise<void> {
    if (!publicKey || notificationState() !== 'granted') return;
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

    try {
        const registration = await navigator.serviceWorker.register('/sw.js');
        await navigator.serviceWorker.ready;

        const subscription =
            (await registration.pushManager.getSubscription()) ??
            (await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: decodeKey(publicKey),
            }));

        await axios.post(route('push.store'), subscription.toJSON());
    } catch {
        // Page notifications still work while the app is open.
    }
}

/** Same rule as the server: a pause for now, or the daily schedule. */
export function inDnd(user: { dnd_until?: string | null; dnd_from?: string | null; dnd_to?: string | null }): boolean {
    if (user.dnd_until && new Date(user.dnd_until) > new Date()) return true;
    if (!user.dnd_from || !user.dnd_to) return false;

    const now = new Date().toTimeString().slice(0, 5);

    return user.dnd_from <= user.dnd_to
        ? now >= user.dnd_from && now < user.dnd_to
        : now >= user.dnd_from || now < user.dnd_to;
}
