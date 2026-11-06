import { HuddleChoice } from '@/Components/Chat/Huddle';
import { HeadphonesIcon } from '@/Components/Chat/Icons';
import { ChatUser } from '@/types/chat';
import { ReactNode, useEffect, useRef, useState } from 'react';

export interface HuddleCall {
    channel_id: number;
    by: { id: number; name: string };
    place: string | null;
}

interface Props {
    call: HuddleCall;
    me: ChatUser;
    onJoin: (choice: HuddleChoice) => void;
    onReply: (status: 'declined' | 'soon') => void;
    onTimeout: () => void;
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

function Glyph({ children }: { children: ReactNode }) {
    return (
        <svg aria-hidden viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            {children}
        </svg>
    );
}

const SLASH = <path d="M4 4l16 16" />;

function DevicePicker({ label, devices, value, onChange }: { label: string; devices: MediaDeviceInfo[]; value: string; onChange: (id: string) => void }) {
    return (
        <select
            aria-label={label}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            disabled={devices.length === 0}
            className="min-w-0 flex-1 truncate rounded-md border-white/20 bg-black/30 py-1.5 pl-2 pr-7 text-xs text-white focus:border-teal-400 focus:ring-teal-400 disabled:opacity-50"
        >
            <option value="" className="text-slate-900">{label}</option>
            {devices.map((device, index) => (
                <option key={device.deviceId || index} value={device.deviceId} className="text-slate-900">
                    {device.label || `${label} ${index + 1}`}
                </option>
            ))}
        </select>
    );
}

function Action({ label, onClick, primary, children }: { label: string; onClick: () => void; primary?: boolean; children: ReactNode }) {
    return (
        <button type="button" aria-label={label} onClick={onClick} className="group flex flex-1 flex-col items-center gap-1.5 text-sm font-medium text-white">
            <span className={`flex h-12 w-12 items-center justify-center rounded-xl ${primary ? 'bg-teal-300 text-teal-950 group-hover:bg-teal-200' : 'bg-white/15 group-hover:bg-white/25'}`}>
                {children}
            </span>
            {label}
        </button>
    );
}

/** The incoming huddle window: check the microphone and camera, then answer. */
export default function HuddleInvite({ call, me, onJoin, onReply, onTimeout }: Props) {
    const [devices, setDevices] = useState<MediaDeviceInfo[]>([]);
    const [devicesRead, setDevicesRead] = useState(false);
    const [micId, setMicId] = useState('');
    const [speakerId, setSpeakerId] = useState('');
    const [cameraId, setCameraId] = useState('');
    const [muted, setMuted] = useState(false);
    const [preview, setPreview] = useState<MediaStream | null>(null);
    const [cameraError, setCameraError] = useState<string | null>(null);
    const [engaged, setEngaged] = useState(false);
    const video = useRef<HTMLVideoElement>(null);
    const previewRef = useRef<MediaStream | null>(null);

    const cameras = devices.filter((device) => device.kind === 'videoinput');
    const noCamera = devicesRead && cameras.length === 0;
    const speakersWork = 'setSinkId' in HTMLMediaElement.prototype;

    const readDevices = async () => {
        const found = await navigator.mediaDevices.enumerateDevices().catch(() => []);
        setDevices(found.filter((device) => device.deviceId !== '' || device.kind === 'videoinput'));
        setDevicesRead(true);
    };

    const stopPreview = () => {
        previewRef.current?.getTracks().forEach((track) => track.stop());
        previewRef.current = null;
        setPreview(null);
    };

    const startPreview = async (id: string) => {
        stopPreview();
        setCameraError(null);

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: id ? { deviceId: { exact: id } } : true });
            previewRef.current = stream;
            setPreview(stream);
            await readDevices();
        } catch {
            setCameraError('Your camera could not be opened. Check that it is connected and that this site may use it.');
        }
    };

    useEffect(() => {
        readDevices();

        return () => previewRef.current?.getTracks().forEach((track) => track.stop());
    }, []);

    useEffect(() => {
        if (video.current) video.current.srcObject = preview;
    }, [preview]);

    // Keeps ringing until they touch the window, and never closes it while they are setting up.
    useEffect(() => {
        if (engaged) return;

        const sound = new AudioContext();
        ring(sound);
        const repeat = window.setInterval(() => ring(sound), RING_EVERY_MS);
        const giveUp = window.setTimeout(onTimeout, GIVE_UP_AFTER_MS);

        return () => {
            window.clearInterval(repeat);
            window.clearTimeout(giveUp);
            sound.close();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [engaged, call.channel_id, call.by.id]);

    const join = () => {
        const withVideo = preview !== null;
        stopPreview();
        onJoin({ micId, speakerId, cameraId, muted, video: withVideo });
    };

    const toggle = 'relative flex h-11 w-11 items-center justify-center rounded-xl text-white disabled:opacity-60';

    return (
        <aside
            role="alertdialog"
            aria-label="Incoming huddle"
            onPointerDown={() => setEngaged(true)}
            className="fixed bottom-4 right-4 top-4 z-50 flex w-[23rem] max-w-[calc(100vw-2rem)] flex-col gap-3 overflow-y-auto rounded-2xl bg-teal-950 p-4 text-white shadow-2xl"
        >
            {(noCamera || cameraError) && (
                <p role="status" className="rounded-lg bg-amber-900/70 p-3 text-sm">
                    <strong className="block">No camera available</strong>
                    {cameraError ?? 'You can still join with your microphone.'}
                </p>
            )}

            <p className="text-sm">
                <strong>{call.by.name}</strong> is inviting you to a huddle{call.place ? ` in ${call.place}` : ''}.{' '}
                <strong>{call.by.name}</strong> is already there.
            </p>

            <div className="rounded-xl bg-white/10 p-3">
                <div className="relative aspect-square overflow-hidden rounded-lg bg-teal-100">
                    {preview ? (
                        <video ref={video} aria-label="Your camera" autoPlay muted playsInline className="h-full w-full -scale-x-100 object-cover" />
                    ) : (
                        <div className="flex h-full items-center justify-center">
                            <span className="flex h-28 w-28 items-center justify-center rounded-full bg-teal-700 text-5xl font-bold text-white">
                                {me.name.charAt(0).toUpperCase()}
                            </span>
                        </div>
                    )}

                    <div className="absolute inset-x-0 bottom-3 flex justify-center gap-2">
                        <button
                            type="button"
                            aria-label={muted ? 'Turn microphone on' : 'Turn microphone off'}
                            aria-pressed={muted}
                            onClick={() => setMuted(!muted)}
                            className={`${toggle} ${muted ? 'bg-rose-700' : 'bg-black/60 hover:bg-black/75'}`}
                        >
                            <Glyph>
                                <rect x="9" y="3" width="6" height="11" rx="3" />
                                <path d="M5 11a7 7 0 0 0 14 0M12 18v3" />
                                {muted && SLASH}
                            </Glyph>
                        </button>
                        <button
                            type="button"
                            aria-label={preview ? 'Turn camera off' : 'Turn camera on'}
                            aria-pressed={preview !== null}
                            disabled={noCamera}
                            onClick={() => (preview ? stopPreview() : startPreview(cameraId))}
                            className={`${toggle} ${preview ? 'bg-black/60 hover:bg-black/75' : 'bg-rose-700'}`}
                        >
                            <Glyph>
                                <rect x="3" y="6" width="13" height="12" rx="2" />
                                <path d="M16 10l5-3v10l-5-3" />
                                {!preview && SLASH}
                            </Glyph>
                        </button>
                    </div>
                </div>

                <div className="mt-3 flex gap-2">
                    <DevicePicker label="Microphone" devices={devices.filter((d) => d.kind === 'audioinput')} value={micId} onChange={setMicId} />
                    {speakersWork && (
                        <DevicePicker label="Speaker" devices={devices.filter((d) => d.kind === 'audiooutput')} value={speakerId} onChange={setSpeakerId} />
                    )}
                    <DevicePicker
                        label="Camera"
                        devices={cameras}
                        value={cameraId}
                        onChange={(id) => {
                            setCameraId(id);
                            if (preview) startPreview(id);
                        }}
                    />
                </div>
            </div>

            <div className="mt-auto flex items-start justify-between gap-2 pt-1">
                <Action label="Decline" onClick={() => onReply('declined')}>
                    <Glyph>
                        <path d="M6 6l12 12M18 6L6 18" />
                    </Glyph>
                </Action>
                <Action label="Be there soon" onClick={() => onReply('soon')}>
                    <Glyph>
                        <path d="M7 3h10M7 21h10M8 3c0 5 8 5 8 9s-8 4-8 9M16 3c0 5-8 5-8 9s8 4 8 9" />
                    </Glyph>
                </Action>
                <Action label="Join" onClick={join} primary>
                    <HeadphonesIcon className="h-5 w-5" />
                </Action>
            </div>
        </aside>
    );
}
