export type Theme = 'light' | 'dark' | 'system';

export function applyTheme(theme: Theme | null | undefined): void {
    const choice = theme ?? 'system';
    const dark =
        choice === 'dark' || (choice === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', dark);

    try {
        window.localStorage.setItem('theme', choice);
    } catch {
        // Only the first paint uses this copy; the account keeps the real choice.
    }
}
