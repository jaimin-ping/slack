import ApplicationLogo from '@/Components/ApplicationLogo';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface Props extends Record<string, unknown> {
    workspaces: { id: number; name: string; slug: string }[];
    code: string | null;
}

const input =
    'w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600';
const button =
    'rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50';

export default function Dashboard({ auth, workspaces, code }: PageProps<Props>) {
    const create = useForm({ name: '' });
    const join = useForm({ code: code ?? '' });

    const submitCreate = (e: FormEvent) => {
        e.preventDefault();
        create.post(route('workspaces.store'));
    };

    const submitJoin = (e: FormEvent) => {
        e.preventDefault();
        join.post(route('workspaces.join'));
    };

    return (
        <div className="min-h-screen bg-slate-50 px-4 py-10">
            <Head title="Your workspaces" />

            <div className="mx-auto max-w-xl">
                <header className="mb-8 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <ApplicationLogo className="h-9 w-9" />
                        <span className="text-xl font-bold text-slate-900">Ping Chat</span>
                    </div>
                    <div className="flex items-center gap-4 text-sm text-slate-600">
                        <Link href={route('profile.edit')} className="hover:text-slate-900">
                            {auth.user.name}
                        </Link>
                        <Link href={route('logout')} method="post" as="button" className="hover:text-slate-900">
                            Log out
                        </Link>
                    </div>
                </header>

                <section className="mb-6">
                    <h1 className="mb-3 text-lg font-semibold text-slate-900">Your workspaces</h1>
                    {workspaces.length === 0 ? (
                        <p className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                            You are not in a workspace yet. Create one or join with an invite code.
                        </p>
                    ) : (
                        <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white">
                            {workspaces.map((workspace) => (
                                <li key={workspace.id}>
                                    <Link
                                        href={route('workspaces.show', workspace.slug)}
                                        className="flex items-center justify-between px-4 py-3 font-medium text-slate-800 hover:bg-slate-50"
                                    >
                                        {workspace.name}
                                        <span aria-hidden className="text-slate-400">→</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <div className="grid gap-4 sm:grid-cols-2">
                    <form onSubmit={submitCreate} className="rounded-lg border border-slate-200 bg-white p-4">
                        <label htmlFor="workspace-name" className="mb-2 block text-sm font-semibold text-slate-800">
                            Create a workspace
                        </label>
                        <input
                            id="workspace-name"
                            className={input}
                            value={create.data.name}
                            onChange={(e) => create.setData('name', e.target.value)}
                            placeholder="Acme team"
                            required
                        />
                        {create.errors.name && <p className="mt-1 text-sm text-red-600">{create.errors.name}</p>}
                        <button type="submit" className={`${button} mt-3`} disabled={create.processing}>
                            Create
                        </button>
                    </form>

                    <form onSubmit={submitJoin} className="rounded-lg border border-slate-200 bg-white p-4">
                        <label htmlFor="invite-code" className="mb-2 block text-sm font-semibold text-slate-800">
                            Join with an invite code
                        </label>
                        <input
                            id="invite-code"
                            className={input}
                            value={join.data.code}
                            onChange={(e) => join.setData('code', e.target.value)}
                            placeholder="Invite code"
                            required
                        />
                        {join.errors.code && <p className="mt-1 text-sm text-red-600">{join.errors.code}</p>}
                        <button type="submit" className={`${button} mt-3`} disabled={join.processing}>
                            Join
                        </button>
                    </form>
                </div>
            </div>
        </div>
    );
}
