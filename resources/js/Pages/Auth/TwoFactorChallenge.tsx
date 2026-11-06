import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function TwoFactorChallenge() {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('two-factor.verify'), { onFinish: () => reset('code') });
    };

    return (
        <GuestLayout>
            <Head title="Two-step sign in" />

            <p className="mb-4 text-sm text-gray-600">
                Enter the 6-digit code from your authenticator app, or one of your recovery codes.
            </p>

            <form onSubmit={submit}>
                <InputLabel htmlFor="code" value="Code" />
                <TextInput
                    id="code"
                    name="code"
                    value={data.code}
                    className="mt-1 block w-full"
                    autoComplete="one-time-code"
                    isFocused
                    onChange={(e) => setData('code', e.target.value)}
                />
                <InputError message={errors.code} className="mt-2" />

                <div className="mt-4 flex items-center justify-end">
                    <PrimaryButton disabled={processing}>Continue</PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
