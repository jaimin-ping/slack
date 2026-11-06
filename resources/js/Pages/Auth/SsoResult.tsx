import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link } from '@inertiajs/react';

export default function SsoResult({ message }: { message: string }) {
    return (
        <GuestLayout>
            <Head title="Single sign-on" />
            <p className="text-sm text-gray-700">{message}</p>
            <Link href={route('dashboard')} className="mt-4 inline-block text-sm text-gray-600 underline">
                Back to Ping Chat
            </Link>
        </GuestLayout>
    );
}
