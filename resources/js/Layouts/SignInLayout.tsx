import ApplicationLogo from '@/Components/ApplicationLogo';
import { PropsWithChildren, ReactNode } from 'react';

export const signInInput =
    'block w-full rounded-lg border-slate-300 px-4 py-3 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600';
export const signInButton =
    'block w-full rounded-lg bg-teal-700 px-4 py-3 text-center text-base font-semibold text-white hover:bg-teal-800 disabled:opacity-50';

export default function SignInLayout({ title, subtitle, children }: PropsWithChildren<{ title: string; subtitle: ReactNode }>) {
    return (
        <main className="flex min-h-screen flex-col items-center bg-white px-4 py-10">
            <div className="mb-10 flex items-center gap-2">
                <ApplicationLogo className="h-9 w-9" />
                <span className="text-xl font-bold text-slate-900">Ping Chat</span>
            </div>

            <div className="w-full max-w-sm">
                <h1 className="text-center text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">{title}</h1>
                <p className="mb-8 mt-3 text-center text-slate-600">{subtitle}</p>
                {children}
            </div>
        </main>
    );
}
