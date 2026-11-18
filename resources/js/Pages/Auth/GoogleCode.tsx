import GuestLayout from '@/Layouts/GuestLayout';
import { Head } from '@inertiajs/react';

interface Props {
    code?: string;
    error?: string;
}

export default function GoogleCode({ code, error }: Props) {
    if (error) {
        return (
            <GuestLayout>
                <Head title="Google Sign-in" />
                <div className="text-center">
                    <div className="mb-4 text-red-600">
                        <svg className="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h2 className="text-lg font-semibold text-gray-900 mb-2">Sign-in Failed</h2>
                    <p className="text-sm text-gray-600">{error}</p>
                </div>
            </GuestLayout>
        );
    }

    return (
        <GuestLayout>
            <Head title="Google Sign-in" />
            <div className="text-center">
                <div className="mb-4 text-green-600">
                    <svg className="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h2 className="text-lg font-semibold text-gray-900 mb-2">Google Sign-in Successful</h2>
                <p className="text-sm text-gray-600 mb-4">
                    Copy the code below and paste it in your Ping Chat desktop app:
                </p>
                <div className="bg-gray-100 rounded-lg p-4 mb-4">
                    <code className="text-lg font-mono font-bold text-purple-700 select-all break-all">
                        {code}
                    </code>
                </div>
                <button
                    onClick={() => {
                        navigator.clipboard.writeText(code || '');
                        alert('Code copied to clipboard!');
                    }}
                    className="inline-flex items-center px-4 py-2 bg-purple-600 text-white text-sm font-medium rounded-lg hover:bg-purple-700 transition-colors"
                >
                    <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
                    </svg>
                    Copy Code
                </button>
                <p className="mt-6 text-xs text-gray-500">
                    You can close this window after copying the code.
                </p>
            </div>
        </GuestLayout>
    );
}
