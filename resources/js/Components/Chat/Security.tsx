import Modal from '@/Components/Modal';
import { router, usePage } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { FormEvent, useEffect, useState } from 'react';

const field = 'w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600';
const primary = 'rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50';
const secondary = 'rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100';

const reason = (e: unknown, fallback: string) =>
    (isAxiosError<{ message?: string }>(e) && e.response?.status === 422 && e.response.data?.message) || fallback;

/** Sends someone with a password to the provider, so they can sign in with it from now on. */
export function SsoConnect() {
    const sso = usePage().props.sso as { name: string; connected: boolean } | null;
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);

    if (!sso) return null;

    if (sso.connected) return <span className="px-2 py-1 text-sm text-slate-700">{sso.name} sign in: Connected</span>;

    const connect = async () => {
        setError(null);
        try {
            const { data } = await axios.post<{ url: string }>(route('sso.connect'), { password });
            window.location.href = data.url;
        } catch (err) {
            setError(reason(err, 'Could not start single sign-on.'));
        }
    };

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="rounded-md border border-slate-300 px-2 py-1 text-sm text-slate-700 hover:bg-slate-100"
            >
                Connect {sso.name} sign in
            </button>
        );
    }

    return (
        <div className="w-full space-y-1">
            <div className="flex gap-1">
                <input
                    aria-label="Your password"
                    type="password"
                    autoComplete="current-password"
                    className={field}
                    placeholder="Your password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                />
                <button type="button" className={primary} disabled={!password} onClick={connect}>
                    Continue
                </button>
            </div>
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}

export function TwoFactorModal({ show, enabled, onClose }: { show: boolean; enabled: boolean; onClose: () => void }) {
    const [password, setPassword] = useState('');
    const [setup, setSetup] = useState<{ secret: string; qr: string } | null>(null);
    const [code, setCode] = useState('');
    const [recovery, setRecovery] = useState<string[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (show) {
            setPassword('');
            setSetup(null);
            setCode('');
            setRecovery(null);
            setError(null);
        }
    }, [show]);

    const refresh = () => router.reload({ only: ['auth'] });

    const start = async (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        try {
            const { data } = await axios.post(route('two-factor.setup'), { password });
            setSetup(data);
            setPassword('');
        } catch (err) {
            setError(reason(err, 'Could not start. Try again.'));
        }
    };

    const confirm = async (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        try {
            const { data } = await axios.post(route('two-factor.confirm'), { code });
            setRecovery(data.recovery_codes);
            refresh();
        } catch (err) {
            setError(reason(err, 'That code did not work.'));
        }
    };

    const disable = async (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        try {
            await axios.delete(route('two-factor.disable'), { data: { password } });
            refresh();
            onClose();
        } catch (err) {
            setError(reason(err, 'Could not turn it off.'));
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="space-y-3 p-5">
                <h2 className="text-lg font-semibold text-slate-900">Two-step sign in</h2>

                {recovery ? (
                    <>
                        <p className="text-sm font-semibold text-amber-800">
                            Save these recovery codes now. They will not be shown again. Each one works once if you lose your phone.
                        </p>
                        <ul className="grid grid-cols-2 gap-1 rounded-md border border-slate-200 bg-slate-50 p-3 font-mono text-sm text-slate-800">
                            {recovery.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                        <div className="flex justify-end">
                            <button type="button" className={primary} onClick={onClose}>
                                I saved them
                            </button>
                        </div>
                    </>
                ) : setup ? (
                    <form onSubmit={confirm} className="space-y-3">
                        <p className="text-sm text-slate-600">Scan this with Google Authenticator, Authy or a similar app, then enter its code.</p>
                        <img src={setup.qr} alt="QR code for your authenticator app" className="mx-auto h-44 w-44 rounded bg-white p-2" />
                        <p className="break-all text-center font-mono text-xs text-slate-500">{setup.secret}</p>
                        <input
                            aria-label="Code from your app"
                            autoComplete="one-time-code"
                            className={field}
                            inputMode="numeric"
                            placeholder="123456"
                            required
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                        />
                        {error && <p className="text-sm text-red-600">{error}</p>}
                        <div className="flex justify-end gap-2">
                            <button type="button" className={secondary} onClick={onClose}>
                                Cancel
                            </button>
                            <button type="submit" className={primary}>
                                Turn on
                            </button>
                        </div>
                    </form>
                ) : (
                    <form onSubmit={enabled ? disable : start} className="space-y-3">
                        <p className="text-sm text-slate-600">
                            {enabled
                                ? 'Two-step sign in is on. Enter your password to turn it off.'
                                : 'Protect your account with a code from your phone. Enter your password to begin.'}
                        </p>
                        <input
                            aria-label="Your password"
                            autoComplete="current-password"
                            className={field}
                            required
                            type="password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                        />
                        {error && <p className="text-sm text-red-600">{error}</p>}
                        <div className="flex justify-end gap-2">
                            <button type="button" className={secondary} onClick={onClose}>
                                Cancel
                            </button>
                            <button type="submit" className={primary}>
                                {enabled ? 'Turn off' : 'Continue'}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </Modal>
    );
}

export function ExportModal({
    show,
    url,
    title,
    description,
    fileName,
    onClose,
}: {
    show: boolean;
    url: string;
    title: string;
    description: string;
    fileName: string;
    onClose: () => void;
}) {
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (show) {
            setPassword('');
            setError(null);
        }
    }, [show]);

    const download = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const { data } = await axios.post<Blob>(url, { password }, { responseType: 'blob' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(data);
            link.download = fileName;
            link.click();
            URL.revokeObjectURL(link.href);
            onClose();
        } catch (err) {
            setError(isAxiosError(err) && err.response?.status === 422 ? 'That password is not right.' : 'Export failed. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form onSubmit={download} className="space-y-3 p-5">
                <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
                <p className="text-sm text-slate-600">{description}</p>
                <input
                    aria-label="Your password"
                    autoComplete="current-password"
                    className={field}
                    placeholder="Your password"
                    required
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                />
                {error && <p className="text-sm text-red-600">{error}</p>}
                <div className="flex justify-end gap-2">
                    <button type="button" className={secondary} onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" className={primary} disabled={busy}>
                        {busy ? 'Preparing…' : 'Download'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
