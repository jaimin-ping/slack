import Modal from '@/Components/Modal';
import { ChatUser, WorkspaceInfo } from '@/types/chat';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface Props {
    show: boolean;
    workspace: WorkspaceInfo;
    people: ChatUser[];
    onClose: () => void;
}

export default function NewChannelModal({ show, workspace, people, onClose }: Props) {
    const form = useForm<{ name: string; is_private: boolean; member_ids: number[] }>({
        name: '',
        is_private: false,
        member_ids: [],
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('channels.store', workspace.slug), { onSuccess: close });
    };

    const toggle = (id: number) =>
        form.setData(
            'member_ids',
            form.data.member_ids.includes(id)
                ? form.data.member_ids.filter((memberId) => memberId !== id)
                : [...form.data.member_ids, id],
        );

    return (
        <Modal show={show} onClose={close} maxWidth="md">
            <form onSubmit={submit} className="p-5">
                <h2 className="mb-4 text-lg font-semibold text-slate-900">Create a channel</h2>

                <label htmlFor="channel-name" className="mb-1 block text-sm font-medium text-slate-700">
                    Name
                </label>
                <input
                    id="channel-name"
                    className="w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder="project-updates"
                    required
                />
                {form.errors.name && <p className="mt-1 text-sm text-red-600">{form.errors.name}</p>}

                <label className="mt-4 flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        className="rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                        checked={form.data.is_private}
                        onChange={(e) => form.setData('is_private', e.target.checked)}
                    />
                    Make private (only people you choose can see it)
                </label>

                {form.data.is_private && people.length > 0 && (
                    <fieldset className="mt-3 max-h-40 space-y-1 overflow-y-auto rounded-md border border-slate-200 p-2">
                        <legend className="px-1 text-xs font-medium text-slate-500">Add people</legend>
                        {people.map((person) => (
                            <label key={person.id} className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    className="rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                                    checked={form.data.member_ids.includes(person.id)}
                                    onChange={() => toggle(person.id)}
                                />
                                {person.name}
                            </label>
                        ))}
                    </fieldset>
                )}

                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={close} className="rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50"
                    >
                        Create
                    </button>
                </div>
            </form>
        </Modal>
    );
}
