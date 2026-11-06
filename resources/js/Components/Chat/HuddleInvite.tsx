import { HeadphonesIcon } from '@/Components/Chat/Icons';
import { useEffect } from 'react';

export interface HuddleCall {
    channel_id: number;
    by: { id: number; name: string };
    place: string | null;
}

const RING_EVERY_MS = 2500;
const GIVE_UP_AFTER_MS = 45000;

/** Two soft notes. Browsers stay silent until the person has used the page, and that is fine. */
function ring(sound: AudioContext) {
    [660, 880].forEach((pitch, index) => {
        const start = sound.currentTime + index * 0.22;
        const tone = sound.createOscillator();
        const volume = sound.createGain();

        tone.frequency.value = pitch;
        volume.gain.setValueAtTime(0.08, start);
        volume.gain.exponentialRampToValueAtTime(0.001, start + 0.2);
        tone.connect(volume).connect(sound.destination);
        tone.start(start);
        tone.stop(start + 0.2);
    });
}

export default function HuddleInvite({ call, onJoin, onDismiss }: { call: HuddleCall; onJoin: () => void; onDismiss: () => void }) {
    useEffect(() => {
        const sound = new AudioContext();
        ring(sound);
        const repeat = window.setInterval(() => ring(sound), RING_EVERY_MS);
        const giveUp = window.setTimeout(onDismiss, GIVE_UP_AFTER_MS);

        return () => {
            window.clearInterval(repeat);
            window.clearTimeout(giveUp);
            sound.close();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [call.channel_id, call.by.id]);

    return (
        <aside
            role="alertdialog"
            aria-label="Incoming huddle"
            className="fixed right-4 top-14 z-50 w-80 max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white p-4 shadow-2xl dark:border-slate-700 dark:bg-slate-900"
        >
            <div className="flex items-start gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-800">
                    <HeadphonesIcon className="h-5 w-5" />
                </span>
                <p className="min-w-0 text-sm text-slate-700 dark:text-slate-200">
                    <strong className="block truncate text-slate-900 dark:text-white">{call.by.name}</strong>
                    started a huddle{call.place ? ` in ${call.place}` : ' with you'}
                </p>
            </div>
            <div className="mt-3 flex justify-end gap-2">
                <button
                    type="button"
                    onClick={onDismiss}
                    className="rounded-md px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    Dismiss
                </button>
                <button
                    type="button"
                    onClick={onJoin}
                    className="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800"
                >
                    Join
                </button>
            </div>
        </aside>
    );
}
