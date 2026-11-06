import { lazy, Suspense } from 'react';

// The emoji list is large, so it loads only when someone opens the picker.
const Picker = lazy(() => import('emoji-picker-react'));

export default function EmojiDialog({
    onPick,
    onClose,
    custom,
}: {
    onPick: (emoji: string) => void;
    onClose: () => void;
    custom?: { id: string; names: string[]; imgUrl: string }[];
}) {
    return (
        <div
            role="dialog"
            aria-label="Choose an emoji"
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/20 p-4"
            onMouseDown={onClose}
            onKeyDown={(e) => e.key === 'Escape' && onClose()}
        >
            <div onMouseDown={(e) => e.stopPropagation()}>
                <Suspense fallback={<p className="rounded-lg bg-white p-6 text-sm text-slate-600">Loading…</p>}>
                    <Picker
                        autoFocusSearch
                        emojiStyle={'native' as never}
                        lazyLoadEmojis
                        previewConfig={{ showPreview: false }}
                        customEmojis={custom}
                        onEmojiClick={(emoji) => onPick(emoji.isCustom ? `:${emoji.names[0]}:` : emoji.emoji)}
                    />
                </Suspense>
            </div>
        </div>
    );
}
