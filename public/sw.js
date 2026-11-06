// Shows notifications when no Ping Chat page is open.

self.addEventListener('push', (event) => {
    const data = event.data ? event.data.json() : {};

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const active = windows.some((client) => client.focused && client.visibilityState === 'visible');

            await self.registration.showNotification(data.title ?? 'Ping Chat', {
                body: data.body ?? '',
                tag: data.tag ?? 'ping-chat',
                data: { url: data.url ?? '/' },
                silent: active,
            });

            // Someone reading the app already sees the message, so nothing should stay on screen.
            if (active) {
                const shown = await self.registration.getNotifications({ tag: data.tag ?? 'ping-chat' });
                shown.forEach((notification) => notification.close());
            }
        })(),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        (async () => {
            const url = new URL(event.notification.data?.url ?? '/', self.location.origin).href;
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const open = windows.find((client) => new URL(client.url).origin === self.location.origin);

            if (open) {
                await open.focus();
                return open.navigate(url);
            }

            return self.clients.openWindow(url);
        })(),
    );
});
