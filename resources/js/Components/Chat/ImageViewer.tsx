import { MouseEvent, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

export interface ViewerImage {
    id: number;
    name: string;
    url: string;
}

/** A plain click opens the viewer; Ctrl or Cmd click still opens a new tab, as links normally do. */
export const opensViewer = (e: MouseEvent) => !(e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0);

const control = 'rounded-md px-3 py-1.5 text-sm font-semibold text-white hover:bg-white/15';
const arrow = 'absolute top-1/2 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-2xl text-white hover:bg-white/30';

/** Shows images over the app, so looking at one never leaves the conversation. */
export default function ImageViewer({ images, start, onClose }: { images: ViewerImage[]; start: number; onClose: () => void }) {
    const [index, setIndex] = useState(start);
    const image = images[index];
    const several = images.length > 1;
    const move = (step: number) => setIndex((current) => (current + step + images.length) % images.length);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
            if (several && e.key === 'ArrowLeft') move(-1);
            if (several && e.key === 'ArrowRight') move(1);
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [several, onClose]);

    return createPortal(
        <div role="dialog" aria-modal="true" aria-label="Image viewer" onClick={onClose} className="fixed inset-0 z-[60] flex flex-col bg-black/90">
            <header onClick={(e) => e.stopPropagation()} className="flex items-center gap-2 px-4 py-2">
                <span className="min-w-0 flex-1 truncate text-sm font-medium text-white">
                    {image.name}
                    {several && <span className="ml-2 text-white/60">{index + 1} of {images.length}</span>}
                </span>
                <a href={image.url} download={image.name} className={control}>
                    Download
                </a>
                <button type="button" onClick={onClose} className={control}>
                    Close
                </button>
            </header>

            <div className="relative flex min-h-0 flex-1 items-center justify-center p-4">
                <img src={image.url} alt={image.name} onClick={(e) => e.stopPropagation()} className="max-h-full max-w-full rounded object-contain" />

                {several && (
                    <>
                        <button
                            type="button"
                            aria-label="Previous image"
                            onClick={(e) => {
                                e.stopPropagation();
                                move(-1);
                            }}
                            className={`${arrow} left-4`}
                        >
                            ‹
                        </button>
                        <button
                            type="button"
                            aria-label="Next image"
                            onClick={(e) => {
                                e.stopPropagation();
                                move(1);
                            }}
                            className={`${arrow} right-4`}
                        >
                            ›
                        </button>
                    </>
                )}
            </div>
        </div>,
        document.body,
    );
}
