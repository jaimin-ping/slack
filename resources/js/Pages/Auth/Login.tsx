import InputError from '@/Components/InputError';
import SignInLayout, { signInButton, signInInput } from '@/Layouts/SignInLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Login({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });
    const sso = usePage().props.sso as { name: string } | null;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('login.email'));
    };

    return (
        <SignInLayout
            title="First, enter your email"
            subtitle={
                <>
                    Use the <strong>email address you were invited with.</strong>
                </>
            }
        >
            <Head title="Sign in" />

            {status && <p className="mb-4 text-center text-sm font-medium text-green-700">{status}</p>}

            <form onSubmit={submit}>
                <input
                    aria-label="Email"
                    autoComplete="username"
                    autoFocus
                    className={signInInput}
                    id="email"
                    onChange={(e) => setData('email', e.target.value)}
                    placeholder="name@work-email.com"
                    required
                    type="email"
                    value={data.email}
                />
                <InputError message={errors.email} className="mt-2" />
                <button type="submit" disabled={processing} className={`${signInButton} mt-4`}>
                    Continue
                </button>
            </form>

            <div className="my-6 flex items-center gap-4 text-sm text-slate-500">
                <span className="h-px flex-1 bg-slate-200" />
                OR
                <span className="h-px flex-1 bg-slate-200" />
            </div>

            <a
                href={route('google.redirect')}
                className="mb-3 flex items-center justify-center gap-3 rounded-lg border-2 border-slate-300 px-4 py-2.5 font-semibold text-slate-800 hover:bg-slate-50"
            >
                <svg className="h-5 w-5" viewBox="0 0 24 24">
                    <path
                        fill="#4285F4"
                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                    />
                    <path
                        fill="#34A853"
                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                    />
                    <path
                        fill="#FBBC05"
                        d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                    />
                    <path
                        fill="#EA4335"
                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                    />
                </svg>
                Sign in with Google
            </a>

            {sso && (
                <a
                    href={route('sso.redirect')}
                    className="mb-3 block rounded-lg border-2 border-slate-300 px-4 py-2.5 text-center font-semibold text-slate-800 hover:bg-slate-50"
                >
                    Sign in with {sso.name}
                </a>
            )}

            <Link
                href={route('login.password')}
                className="block rounded-lg border-2 border-slate-300 px-4 py-2.5 text-center font-semibold text-slate-800 hover:bg-slate-50"
            >
                Sign in with a password
            </Link>

            <p className="mt-8 text-center text-sm text-slate-600">
                Ping Chat is invite only. Ask a workspace admin to invite you.
            </p>
        </SignInLayout>
    );
}
