import { HeadphonesIcon } from '@/Components/Chat/Icons';
import { echo } from '@/echo';
import { ChatUser } from '@/types/chat';
import { PointerEvent as ReactPointerEvent, useEffect, useRef, useState } from 'react';

interface Props {
    channelId: number;
    me: ChatUser;
    onStarted: () => void;
}

interface Signal {
    from: number;
    to: number;
    description?: RTCSessionDescriptionInit;
    candidate?: RTCIceCandidateInit;
}

type VideoKind = 'camera' | 'screen';

// The browser's resize handle sits in this corner, so a press there must not start a drag.
const RESIZE_CORNER_PX = 20;

const METER_EVERY_MS = 150;
const SILENCE_WARNING_MS = 6000;
const HEARD_LEVEL = 0.02;

const micConstraints = (deviceId?: string): MediaTrackConstraints => ({
    ...(deviceId ? { deviceId: { exact: deviceId } } : {}),
    echoCancellation: true,
    noiseSuppression: true,
    autoGainControl: true,
});

/** What someone picked in the invite window before joining. */
export interface HuddleChoice {
    micId: string;
    speakerId: string;
    cameraId: string;
    muted: boolean;
    video: boolean;
}

let pendingJoin: { channelId: number; choice: HuddleChoice } | null = null;

/** Joins the huddle as soon as this conversation's huddle control appears. */
export const requestHuddleJoin = (channelId: number, choice: HuddleChoice) => {
    pendingJoin = { channelId, choice };
};

// STUN finds a direct route. Networks that block direct media also need a TURN server.
const ICE_SERVERS = [{ urls: 'stun:stun.l.google.com:19302' }];

const videoTransceiver = (peer: RTCPeerConnection) =>
    peer.getTransceivers().find((transceiver) => transceiver.receiver.track.kind === 'video');

function Tile({ stream, label, kind, mirrored }: { stream: MediaStream; label: string; kind: VideoKind; mirrored: boolean }) {
    const element = useRef<HTMLVideoElement>(null);

    useEffect(() => {
        if (element.current) element.current.srcObject = stream;
    }, [stream]);

    return (
        <figure className="relative h-full min-h-0 overflow-hidden rounded-lg bg-slate-900">
            <video
                ref={element}
                autoPlay
                playsInline
                muted
                data-huddle-tile={kind}
                className={`h-full w-full ${kind === 'screen' ? 'object-contain' : 'object-cover'} ${mirrored ? '-scale-x-100' : ''}`}
            />
            <figcaption className="absolute bottom-1 left-1 rounded bg-black/60 px-1.5 py-0.5 text-xs text-white">
                {label}
                {kind === 'screen' && ' · screen'}
            </figcaption>
        </figure>
    );
}

/** Audio, camera and screen between channel members: browsers connect directly and signal over Reverb. */
export default function Huddle({ channelId, me, onStarted }: Props) {
    const [active, setActive] = useState(false);
    const [starting, setStarting] = useState(false);
    const [muted, setMuted] = useState(false);
    const [people, setPeople] = useState<ChatUser[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [localKind, setLocalKind] = useState<VideoKind | null>(null);
    const [localStream, setLocalStream] = useState<MediaStream | null>(null);
    const [remoteStreams, setRemoteStreams] = useState<Record<number, MediaStream>>({});
    const [remoteKinds, setRemoteKinds] = useState<Record<number, VideoKind | null>>({});
    const [expanded, setExpanded] = useState(false);
    const [fullScreen, setFullScreen] = useState(false);
    const [position, setPosition] = useState<{ x: number; y: number } | null>(null);
    const [mics, setMics] = useState<MediaDeviceInfo[]>([]);
    const [micId, setMicId] = useState('');
    const [micSilent, setMicSilent] = useState(false);
    const [micRevision, setMicRevision] = useState(0);
    const [soundBlocked, setSoundBlocked] = useState(false);

    const audioStream = useRef<MediaStream | null>(null);
    const localTrack = useRef<MediaStreamTrack | null>(null);
    const localKindRef = useRef<VideoKind | null>(null);
    const peers = useRef(new Map<number, RTCPeerConnection>());
    const audio = useRef(new Map<number, HTMLAudioElement>());
    const announce = useRef<() => void>(() => {});
    const speaker = useRef('');
    const stage = useRef<HTMLElement>(null);
    const grab = useRef<{ x: number; y: number } | null>(null);
    const meter = useRef<HTMLSpanElement>(null);
    const mutedRef = useRef(false);
    mutedRef.current = muted;
    const room = `huddle.${channelId}`;

    const closePeer = (id: number) => {
        peers.current.get(id)?.close();
        peers.current.delete(id);
        audio.current.get(id)?.remove();
        audio.current.delete(id);
        setRemoteStreams(({ [id]: _, ...rest }) => rest);
        setRemoteKinds(({ [id]: _, ...rest }) => rest);
    };

    const setVideoTrack = (track: MediaStreamTrack | null, kind: VideoKind | null) => {
        localTrack.current?.stop();
        localTrack.current = track;
        localKindRef.current = kind;
        setLocalKind(kind);
        setLocalStream(track ? new MediaStream([track]) : null);
        peers.current.forEach((peer) => videoTransceiver(peer)?.sender.replaceTrack(track));
        announce.current();
    };

    const leave = () => {
        [...peers.current.keys()].forEach(closePeer);
        localTrack.current?.stop();
        localTrack.current = null;
        localKindRef.current = null;
        audioStream.current?.getTracks().forEach((track) => track.stop());
        audioStream.current = null;
        announce.current = () => {};
        echo.leave(room);
        setActive(false);
        setMuted(false);
        setPeople([]);
        setLocalKind(null);
        setLocalStream(null);
        setExpanded(false);
    };

    const leaveRef = useRef(leave);
    leaveRef.current = leave;
    useEffect(() => () => leaveRef.current(), []);

    const toggleVideo = async (kind: VideoKind) => {
        setError(null);

        if (localKindRef.current === kind) {
            setVideoTrack(null, null);
            return;
        }

        try {
            const stream =
                kind === 'camera'
                    ? await navigator.mediaDevices.getUserMedia({ video: true })
                    : await navigator.mediaDevices.getDisplayMedia({ video: true });
            const [track] = stream.getVideoTracks();

            // Keeps text sharp on a shared screen, at the cost of smooth motion.
            if (kind === 'screen') track.contentHint = 'detail';

            // The browser's own "Stop sharing" button ends the track.
            track.onended = () => {
                if (localTrack.current === track) setVideoTrack(null, null);
            };
            setVideoTrack(track, kind);
        } catch {
            setError(kind === 'camera' ? 'Camera access is needed for video.' : 'Screen sharing was not started.');
        }
    };

    const start = async (choice?: HuddleChoice) => {
        setError(null);
        setStarting(true);
        speaker.current = choice?.speakerId ?? '';
        setMicId(choice?.micId ?? '');

        try {
            audioStream.current = await navigator.mediaDevices.getUserMedia({
                audio: micConstraints(choice?.micId),
            });
        } catch {
            setError('Microphone access is needed for a huddle.');
            setStarting(false);
            return;
        }

        if (choice?.muted) {
            audioStream.current.getAudioTracks().forEach((track) => {
                track.enabled = false;
            });
            setMuted(true);
        }

        // Before connecting, so the first call already carries the camera.
        if (choice?.video) {
            try {
                const camera = await navigator.mediaDevices.getUserMedia({
                    video: choice.cameraId ? { deviceId: { exact: choice.cameraId } } : true,
                });
                setVideoTrack(camera.getVideoTracks()[0], 'camera');
            } catch {
                setError('Your camera could not be opened, so you joined with audio only.');
            }
        }

        const channel = echo.join(room);
        const signal = (data: Omit<Signal, 'from'>) => channel.whisper('signal', { ...data, from: me.id });

        announce.current = () => channel.whisper('media', { from: me.id, kind: localKindRef.current });

        const connect = (id: number, caller: boolean) => {
            const peer = new RTCPeerConnection({ iceServers: ICE_SERVERS });
            peers.current.set(id, peer);

            audioStream.current?.getTracks().forEach((track) => peer.addTrack(track, audioStream.current as MediaStream));

            // A video slot exists from the start, so camera and screen can switch without reconnecting.
            if (caller) {
                peer.addTransceiver('video', { direction: 'sendrecv' }).sender.replaceTrack(localTrack.current);
            }

            peer.onicecandidate = ({ candidate }) => {
                if (candidate) signal({ to: id, candidate: candidate.toJSON() });
            };
            peer.ontrack = ({ track }) => {
                if (track.kind === 'video') {
                    setRemoteStreams((current) => ({ ...current, [id]: new MediaStream([track]) }));
                    return;
                }

                const element = audio.current.get(id) ?? document.createElement('audio');
                element.autoplay = true;
                element.srcObject = new MediaStream([track]);
                if (speaker.current) element.setSinkId?.(speaker.current).catch(() => {});
                element.play().catch(() => setSoundBlocked(true));
                audio.current.set(id, element);
                document.body.appendChild(element);
            };

            return peer;
        };

        channel
            .here(async (users: ChatUser[]) => {
                setPeople(users);
                setActive(true);
                setStarting(false);

                const others = users.filter((user) => user.id !== me.id);
                if (others.length === 0) onStarted();

                // The newcomer calls everyone already in the room.
                for (const user of others) {
                    const peer = connect(user.id, true);
                    await peer.setLocalDescription(await peer.createOffer());
                    signal({ to: user.id, description: peer.localDescription?.toJSON() });
                }
            })
            .joining((user: ChatUser) => setPeople((current) => [...current, user]))
            .leaving((user: ChatUser) => {
                closePeer(user.id);
                setPeople((current) => current.filter((person) => person.id !== user.id));
            })
            .error(() => {
                setError('Could not join the huddle.');
                setStarting(false);
                leaveRef.current();
            })
            .listenForWhisper('media', ({ from, kind }: { from: number; kind: VideoKind | null }) => {
                setRemoteKinds((current) => ({ ...current, [from]: kind }));
            })
            .listenForWhisper('signal', async ({ from, to, description, candidate }: Signal) => {
                if (to !== me.id) return;

                const peer = peers.current.get(from) ?? connect(from, false);

                if (description) {
                    await peer.setRemoteDescription(description);

                    if (description.type === 'offer') {
                        const video = videoTransceiver(peer);
                        if (video) {
                            video.direction = 'sendrecv';
                            await video.sender.replaceTrack(localTrack.current);
                        }
                        await peer.setLocalDescription(await peer.createAnswer());
                        signal({ to: from, description: peer.localDescription?.toJSON() });
                    }

                    // Tells someone who just connected what this person is already showing.
                    announce.current();
                } else if (candidate) {
                    await peer.addIceCandidate(candidate).catch(() => {});
                }
            });
    };

    useEffect(() => {
        if (pendingJoin?.channelId !== channelId) return;
        const { choice } = pendingJoin;
        pendingJoin = null;
        start(choice);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const toggleMute = () => {
        audioStream.current?.getAudioTracks().forEach((track) => {
            track.enabled = muted;
        });
        setMuted(!muted);
    };

    // Shows whether the microphone really picks anything up, which nothing else on screen can tell you.
    useEffect(() => {
        const stream = audioStream.current;
        if (!active || !stream) return;

        navigator.mediaDevices
            .enumerateDevices()
            .then((found) => setMics(found.filter((device) => device.kind === 'audioinput' && device.deviceId !== '')))
            .catch(() => setMics([]));

        const sound = new AudioContext();
        const analyser = sound.createAnalyser();
        const samples = new Uint8Array(analyser.fftSize);
        sound.createMediaStreamSource(stream).connect(analyser);
        sound.resume().catch(() => {});
        let lastHeard = Date.now();

        const timer = window.setInterval(() => {
            analyser.getByteTimeDomainData(samples);
            const level = samples.reduce((loudest, sample) => Math.max(loudest, Math.abs(sample - 128) / 128), 0);

            if (meter.current) meter.current.style.width = `${mutedRef.current ? 0 : Math.min(100, level * 250)}%`;
            if (mutedRef.current || level > HEARD_LEVEL) lastHeard = Date.now();
            setMicSilent(Date.now() - lastHeard > SILENCE_WARNING_MS);
        }, METER_EVERY_MS);

        return () => {
            window.clearInterval(timer);
            sound.close();
            setMicSilent(false);
        };
    }, [active, micRevision]);

    const changeMic = async (id: string) => {
        setError(null);

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: micConstraints(id) });
            const [track] = stream.getAudioTracks();
            track.enabled = !muted;

            peers.current.forEach((peer) => {
                peer.getSenders().find((sender) => sender.track?.kind === 'audio')?.replaceTrack(track);
            });
            audioStream.current?.getTracks().forEach((old) => old.stop());
            audioStream.current = stream;
            setMicId(id);
            setMicRevision((count) => count + 1);
        } catch {
            setError('That microphone could not be opened.');
        }
    };

    const turnOnSound = () => {
        setSoundBlocked(false);
        audio.current.forEach((element) => element.play().catch(() => setSoundBlocked(true)));
    };

    // Someone else's screen is meant to be read, so it opens large and shrinks again when they stop.
    const screenSharer = Object.keys(remoteStreams).find((id) => remoteKinds[Number(id)] === 'screen');

    useEffect(() => {
        setExpanded(Boolean(screenSharer));
        if (!screenSharer && document.fullscreenElement) document.exitFullscreen().catch(() => {});
    }, [screenSharer]);

    useEffect(() => {
        const update = () => setFullScreen(document.fullscreenElement !== null);
        document.addEventListener('fullscreenchange', update);

        return () => document.removeEventListener('fullscreenchange', update);
    }, []);

    const floating = !expanded && !fullScreen;

    // A size the person dragged would otherwise stay on the window after it grows.
    useEffect(() => {
        if (!floating && stage.current) {
            stage.current.style.width = '';
            stage.current.style.height = '';
        }
    }, [floating]);

    const startDrag = (e: ReactPointerEvent<HTMLElement>) => {
        const box = e.currentTarget.getBoundingClientRect();
        const onResizeHandle = e.clientX > box.right - RESIZE_CORNER_PX && e.clientY > box.bottom - RESIZE_CORNER_PX;

        if (!floating || onResizeHandle || (e.target as HTMLElement).closest('button')) return;

        e.currentTarget.setPointerCapture(e.pointerId);
        grab.current = { x: e.clientX - box.left, y: e.clientY - box.top };
        setPosition({ x: box.left, y: box.top });
    };

    const drag = (e: ReactPointerEvent<HTMLElement>) => {
        if (!grab.current) return;

        const box = e.currentTarget.getBoundingClientRect();
        setPosition({
            x: Math.min(Math.max(e.clientX - grab.current.x, 0), window.innerWidth - box.width),
            y: Math.min(Math.max(e.clientY - grab.current.y, 0), window.innerHeight - box.height),
        });
    };

    const toggleFullScreen = () => {
        const change = fullScreen ? document.exitFullscreen() : stage.current?.requestFullscreen();
        change?.catch(() => setError('Full screen is not available here.'));
    };

    if (!active) {
        return (
            <span className="flex items-center gap-2">
                {error && <span className="text-xs text-red-600">{error}</span>}
                <button
                    type="button"
                    onClick={() => start()}
                    disabled={starting}
                    className="flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                >
                    <HeadphonesIcon className="h-4 w-4" />
                    {starting ? 'Joining…' : 'Huddle'}
                </button>
            </span>
        );
    }

    const nameOf = (id: number) => people.find((person) => person.id === id)?.name ?? 'Someone';
    const tiles = Object.entries(remoteStreams).flatMap(([id, stream]) => {
        const kind = remoteKinds[Number(id)];

        return kind ? [{ id: Number(id), stream, kind }] : [];
    });
    const showStage = tiles.length > 0 || localStream !== null;
    const mainTile = tiles.find((tile) => tile.kind === 'screen');
    const sideTiles = tiles.filter((tile) => tile !== mainTile);
    const stageControl = 'rounded px-2 py-0.5 text-xs text-white/80 hover:bg-white/10';
    const control = 'rounded px-2 py-0.5 hover:bg-emerald-100';

    return (
        <>
            {error && <span className="text-xs text-red-600">{error}</span>}
            {micSilent && (
                <span role="alert" className="text-xs font-semibold text-red-700">
                    Your microphone is silent. Choose another one.
                </span>
            )}
            {soundBlocked && (
                <button type="button" onClick={turnOnSound} className="rounded bg-amber-500 px-2 py-0.5 text-xs font-semibold text-slate-900">
                    Turn on sound
                </button>
            )}

            <span className="flex items-center gap-1 rounded bg-emerald-50 px-2 py-0.5 text-sm text-emerald-900">
                <HeadphonesIcon className="h-4 w-4" />
                <span title={people.map((person) => person.name).join(', ')}>{people.length} in huddle</span>
                <button type="button" onClick={toggleMute} aria-pressed={muted} className={control}>
                    {muted ? 'Unmute' : 'Mute'}
                </button>
                <span role="img" aria-label="Microphone level" className="h-2 w-10 overflow-hidden rounded bg-emerald-200">
                    <span ref={meter} data-mic-meter className="block h-full w-0 bg-emerald-600" />
                </span>
                {mics.length > 0 && (
                    <select
                        aria-label="Microphone"
                        value={micId}
                        onChange={(e) => changeMic(e.target.value)}
                        className="w-24 truncate rounded border-emerald-200 bg-transparent py-0 pl-1 pr-5 text-xs"
                    >
                        <option value="">Default mic</option>
                        {mics.map((mic, index) => (
                            <option key={mic.deviceId} value={mic.deviceId}>
                                {mic.label || `Microphone ${index + 1}`}
                            </option>
                        ))}
                    </select>
                )}
                <button type="button" onClick={() => toggleVideo('camera')} aria-pressed={localKind === 'camera'} className={control}>
                    {localKind === 'camera' ? 'Stop video' : 'Video'}
                </button>
                <button type="button" onClick={() => toggleVideo('screen')} aria-pressed={localKind === 'screen'} className={control}>
                    {localKind === 'screen' ? 'Stop sharing' : 'Share'}
                </button>
                <button
                    type="button"
                    onClick={leave}
                    className="rounded bg-rose-700 px-2 py-0.5 font-semibold text-white hover:bg-rose-800"
                >
                    Leave
                </button>
            </span>

            {showStage && (
                <section
                    ref={stage}
                    aria-label="Huddle video"
                    onPointerDown={startDrag}
                    onPointerMove={drag}
                    onPointerUp={() => {
                        grab.current = null;
                    }}
                    style={floating && position ? { left: position.x, top: position.y, right: 'auto' } : undefined}
                    className={`fixed z-40 flex flex-col gap-2 bg-slate-950 p-2 shadow-2xl ${
                        fullScreen
                            ? 'inset-0'
                            : expanded
                              ? 'inset-2 rounded-xl'
                              : 'right-4 top-28 h-72 min-h-40 w-[26rem] min-w-60 max-w-[calc(100vw-2rem)] cursor-move touch-none resize overflow-hidden rounded-xl'
                    }`}
                >
                    <div className="flex items-center justify-end gap-1">
                        {mainTile && (
                            <span className="mr-auto truncate px-2 text-sm text-white">{nameOf(mainTile.id)} is sharing their screen</span>
                        )}
                        {(expanded || fullScreen) && (
                            <>
                                <button type="button" onClick={toggleMute} aria-pressed={muted} className={stageControl}>
                                    {muted ? 'Unmute' : 'Mute'}
                                </button>
                                <button type="button" onClick={leave} className={stageControl}>
                                    Leave
                                </button>
                            </>
                        )}
                        <button type="button" onClick={toggleFullScreen} className={stageControl}>
                            {fullScreen ? 'Exit full screen' : 'Full screen'}
                        </button>
                        {!fullScreen && (
                            <button type="button" onClick={() => setExpanded(!expanded)} className={stageControl}>
                                {expanded ? 'Make smaller' : 'Make larger'}
                            </button>
                        )}
                    </div>

                    {mainTile ? (
                        <div className="flex min-h-0 flex-1 gap-2">
                            <div className="min-w-0 flex-1">
                                <Tile stream={mainTile.stream} label={nameOf(mainTile.id)} kind={mainTile.kind} mirrored={false} />
                            </div>
                            {(sideTiles.length > 0 || localStream) && (
                                <div className="flex w-44 shrink-0 flex-col gap-2 overflow-y-auto">
                                    {sideTiles.map((tile) => (
                                        <div key={tile.id} className="h-28 shrink-0">
                                            <Tile stream={tile.stream} label={nameOf(tile.id)} kind={tile.kind} mirrored={false} />
                                        </div>
                                    ))}
                                    {localStream && localKind && (
                                        <div className="h-28 shrink-0">
                                            <Tile stream={localStream} label="You" kind={localKind} mirrored={localKind === 'camera'} />
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className={`grid min-h-0 flex-1 gap-2 ${tiles.length + (localStream ? 1 : 0) > 1 ? 'grid-cols-2' : 'grid-cols-1'}`}>
                            {tiles.map((tile) => (
                                <Tile key={tile.id} stream={tile.stream} label={nameOf(tile.id)} kind={tile.kind} mirrored={false} />
                            ))}
                            {localStream && localKind && (
                                <Tile stream={localStream} label="You" kind={localKind} mirrored={localKind === 'camera'} />
                            )}
                        </div>
                    )}
                </section>
            )}
        </>
    );
}
