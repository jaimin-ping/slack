import { echo } from '@/echo';
import { useEffect, useRef } from 'react';

/**
 * Listens on the signed-in user's private channel (which the workspace page already joins) for the AI
 * and task events. Only these listeners are removed on unmount; the channel itself stays joined.
 */
export function usePersonalEvents(userId: number, handlers: Record<string, (event: never) => void>) {
    const latest = useRef(handlers);
    latest.current = handlers;
    const names = Object.keys(handlers).sort().join(',');

    useEffect(() => {
        const channel = echo.private(`App.Models.User.${userId}`);
        const events = names.split(',').filter(Boolean);

        events.forEach((name) => channel.listen(`.${name}`, (event: never) => latest.current[name]?.(event)));

        return () => events.forEach((name) => channel.stopListening(`.${name}`));
    }, [userId, names]);
}
