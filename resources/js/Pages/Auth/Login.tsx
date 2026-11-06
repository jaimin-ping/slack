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
