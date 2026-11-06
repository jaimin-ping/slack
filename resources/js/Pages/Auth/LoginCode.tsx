import InputError from '@/Components/InputError';
import SignInLayout, { signInButton, signInInput } from '@/Layouts/SignInLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

export default function LoginCode({ email, minutes }: { email: string; minutes: number }) {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });
    const [resent, setResent] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('login.code'), { onError: () => reset('code') });
    };

    const resend = () => {
        router.post(route('login.email'), { email }, { preserveScroll: true, onSuccess: () => setResent(true) });
    };

    return (
        <SignInLayout
            title="Check your email"
            subtitle={
                <>
                    If <strong>{email}</strong> has been invited, a 6-digit code is on its way. It works for {minutes} minutes.
                </>
            }
        >
            <Head title="Enter your code" />

            <form onSubmit={submit}>
                <input
                    aria-label="Sign in code"
                    autoComplete="one-time-code"
                    autoFocus
                    className={`${signInInput} text-center tracking-[0.4em]`}
                    inputMode="numeric"
                    maxLength={10}
                    onChange={(e) => setData('code', e.target.value)}
                    placeholder="000000"
                    required
                    value={data.code}
                />
                <InputError message={errors.code} className="mt-2" />
                <button type="submit" disabled={processing} className={`${signInButton} mt-4`}>
                    Sign in
                </button>
            </form>

            <div className="mt-6 space-y-2 text-center text-sm text-slate-600">
                <p>
                    {resent ? (
                        'If the address is invited, another code was sent.'
                    ) : (
                        <button type="button" onClick={resend} className="text-teal-700 underline hover:text-teal-900">
                            Send the code again
                        </button>
                    )}
                </p>
                <p>
                    <Link href={route('login')} className="text-teal-700 underline hover:text-teal-900">
                        Use a different email
                    </Link>
                </p>
            </div>
        </SignInLayout>
    );
}
