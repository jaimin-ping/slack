import ApplicationLogo from '@/Components/ApplicationLogo';
import Avatar from '@/Components/Chat/Avatar';
import { PageProps } from '@/types';
import { ChatUser } from '@/types/chat';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface WorkspaceCard {
    id: number;
    name: string;
    slug: string;
    role: string;
    members_count: number;
    preview: ChatUser[];
}

interface Props extends Record<string, unknown> {
    workspaces: WorkspaceCard[];
}

const TILE_COLORS = ['bg-teal-700', 'bg-indigo-600', 'bg-rose-600', 'bg-amber-600', 'bg-sky-700', 'bg-violet-600', 'bg-emerald-700', 'bg-slate-700'];

const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]!.toUpperCase())
        .join('') || 'W';

const roleLabel = (role: string) => (role === 'owner' ? 'Owner' : role === 'admin' ? 'Admin' : 'Member');

function ArrowIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5" aria-hidden>
            <path d="M5 12h14M13 6l6 6-6 6" />
        </svg>
    );
}

export default function Dashboard({ auth, workspaces }: PageProps<Props>) {
    const create = useForm({ name: '' });
    const [creating, setCreating] = useState(workspaces.length === 0);

    const submitCreate = (e: FormEvent) => {
        e.preventDefault();
        create.post(route('workspaces.store'));
    };

    return (
        <div className="flex min-h-screen flex-col bg-white px-4">
            <Head title="Launch a workspace" />

            <header className="relative mx-auto flex w-full max-w-6xl items-center justify-center py-8">
                <div className="flex items-center gap-2">
                    <ApplicationLogo className="h-9 w-9" />
                    <span className="text-2xl font-extrabold tracking-tight text-slate-900">Ping Chat</span>
                </div>
                <div className="absolute right-0 top-1/2 hidden -translate-y-1/2 text-right text-sm sm:block">
                    <p className="text-slate-500">Signed in as {auth.user.name}</p>
                    <Link href={route('logout')} method="post" as="button" className="font-semibold text-sky-700 hover:underline">
                        Sign in to another account
                    </Link>
                </div>
            </header>

            <main className="mx-auto w-full max-w-3xl flex-1 pb-12">
                <div className="mb-10 text-center">
                    <h1 className="text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">Welcome back!</h1>
                    <p className="mt-3 text-slate-600">You can create a new workspace or choose from an existing one.</p>
                </div>

                {creating ? (
                    <form onSubmit={submitCreate} className="rounded-xl border border-stone-200 bg-stone-100 p-6">
                        <label htmlFor="workspace-name" className="mb-2 block text-base font-bold text-slate-900">
                            What's the name of your company or team?
                        </label>
                        <p className="mb-3 text-sm text-slate-600">This will be the name of your workspace. Choose something your team will recognise.</p>
                        <input
                            id="workspace-name"
                            autoFocus
                            className="w-full rounded-lg border-slate-300 px-4 py-3 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600"
                            value={create.data.name}
                            onChange={(e) => create.setData('name', e.target.value)}
                            placeholder="Ex: Acme Marketing or Acme Co"
                            maxLength={80}
                            required
                        />
                        {create.errors.name && <p className="mt-1 text-sm text-red-600">{create.errors.name}</p>}
                        <div className="mt-4 flex gap-2">
                            <button
                                type="submit"
                                disabled={create.processing || create.data.name.trim() === ''}
                                className="rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-teal-800 disabled:opacity-50"
                            >
                                Create workspace
                            </button>
                            {workspaces.length > 0 && (
                                <button
                                    type="button"
                                    onClick={() => setCreating(false)}
                                    className="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    Cancel
                                </button>
                            )}
                        </div>
                    </form>
                ) : (
                    <button
                        type="button"
                        onClick={() => setCreating(true)}
                        className="flex w-full items-center justify-center gap-4 rounded-xl border border-stone-200 bg-stone-100 px-6 py-6 text-lg font-semibold text-slate-900 transition hover:bg-stone-200"
                    >
                        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-white text-2xl font-light shadow-sm">+</span>
                        Create a new workspace
                    </button>
                )}

                {workspaces.length > 0 && (
                    <>
                        <div className="my-8 flex items-center gap-4">
                            <span className="h-px flex-1 bg-slate-200" />
                            <span className="text-sm text-slate-700">OR continue to existing workspaces</span>
                            <span className="h-px flex-1 bg-slate-200" />
                        </div>

                        <h2 className="text-base font-bold text-slate-900">Ready to launch</h2>
                        <p className="mb-3 text-sm text-slate-500">{auth.user.email}</p>

                        <ul className="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            {workspaces.map((workspace) => (
                                <li key={workspace.id}>
                                    <Link
                                        href={route('workspaces.show', workspace.slug)}
                                        className="group flex items-center gap-4 px-6 py-5 hover:bg-slate-50"
                                    >
                                        <span
                                            className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-lg font-bold text-white ${
                                                TILE_COLORS[workspace.id % TILE_COLORS.length]
                                            }`}
                                        >
                                            {initials(workspace.name)}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-lg font-bold text-slate-900">{workspace.name}</span>
                                            <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-500">
                                                <span className="flex">
                                                    {workspace.preview.map((member) => (
                                                        <span key={member.id} title={member.name} className="-ml-0.5 first:ml-0">
                                                            <Avatar user={member} className="h-5 w-5 rounded text-[10px] ring-1 ring-white" />
                                                        </span>
                                                    ))}
                                                </span>
                                                <span>
                                                    {workspace.members_count} {workspace.members_count === 1 ? 'member' : 'members'}
                                                </span>
                                                <span aria-hidden>•</span>
                                                <span>{roleLabel(workspace.role)}</span>
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-slate-700 transition group-hover:translate-x-1">
                                            <ArrowIcon />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                {workspaces.length === 0 && (
                    <p className="mt-6 text-center text-sm text-slate-500">
                        Expecting an invite? Ask an admin to invite <span className="font-semibold">{auth.user.email}</span>.
                    </p>
                )}
            </main>

            <footer className="flex justify-center gap-6 py-6 text-sm text-slate-500">
                <Link href={route('profile.edit')} className="hover:text-slate-800">
                    Profile
                </Link>
                <Link href={route('logout')} method="post" as="button" className="hover:text-slate-800">
                    Log out
                </Link>
            </footer>
        </div>
    );
}
