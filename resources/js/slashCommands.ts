const COMMANDS = [
    { insert: '/remind ', hint: 'Remind yourself: /remind 30m Call the bank' },
    { insert: '/remind list', hint: 'Show your reminders' },
    { insert: '/remind cancel ', hint: 'Cancel a reminder by its number' },
    { insert: '/me ', hint: 'Post an action' },
    { insert: '/topic ', hint: "Set this channel's topic" },
    { insert: '/mute', hint: 'Mute or unmute this channel' },
    { insert: '/away', hint: 'Switch between active and away' },
    { insert: '/help', hint: 'Show every command' },
];

/** Commands the typed text could still become; a finished one drops out so Enter runs it. */
export function matchCommands(text: string) {
    const typed = text.toLowerCase().trimEnd();

    if (!typed.startsWith('/')) return [];

    return COMMANDS.filter(({ insert }) => {
        const full = insert.trimEnd();

        return full.startsWith(typed) && full !== typed;
    });
}
