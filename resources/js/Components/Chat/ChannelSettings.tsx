import Modal from '@/Components/Modal';
import { ActiveChannel, ChatUser, SectionInfo, WorkspaceInfo } from '@/types/chat';
import { router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, useEffect, useState } from 'react';

const field = 'w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600';
const primary = 'rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50';
const secondary = 'rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100';
const danger = 'rounded-md bg-rose-700 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-800';

export default function ChannelSettings({
    show,
    workspace,
    channel,
    muted,
    nonMembers,
    sections,
    sectionId,
    onClose,
}: {
    show: boolean;
    workspace: WorkspaceInfo;
    channel: ActiveChannel;
    muted: boolean;
    nonMembers: ChatUser[];
    sections: SectionInfo[];
    sectionId: number | null;
    onClose: () => void;
}) {
    const url = [workspace.slug, channel.id];
    const topic = useForm({ topic: channel.topic ?? '' });
    const rename = useForm({ name: channel.name ?? '' });
    const invite = useForm<{ member_ids: number[] }>({ member_ids: [] });
    const [confirming, setConfirming] = useState<'delete' | 'archive' | null>(null);
    const [newSection, setNewSection] = useState('');
    const leaveError = (usePage().props.errors as Record<string, string>).leave;

    useEffect(() => {
        if (show) {
            topic.setData('topic', channel.topic ?? '');
            rename.setData('name', channel.name ?? '');
            rename.clearErrors();
            invite.reset();
            setConfirming(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, channel.id, channel.topic, channel.name]);

    const toggle = (id: number) =>
        invite.setData(
            'member_ids',
            invite.data.member_ids.includes(id)
                ? invite.data.member_ids.filter((memberId) => memberId !== id)
                : [...invite.data.member_ids, id],
        );

    const moveTo = async (id: number | null) => {
        await axios.post(route('channels.section', url), { section_id: id });
        router.reload({ only: ['sidebar', 'extras'] });
    };

    const addSection = async () => {
        const name = newSection.trim();
        if (!name) return;

        const { data } = await axios.post<{ id: number }>(route('sections.store', workspace.slug), { name });
        setNewSection('');
        await moveTo(data.id);
    };

    const toggleMute = async () => {
        await axios.post(route('channels.mute', url));
        router.reload({ only: ['sidebar'] });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="space-y-5 p-5">
                <h2 className="text-lg font-semibold text-slate-900">
                    {channel.type === 'private' ? '🔒 ' : '# '}
                    {channel.name}
                </h2>

                {channel.can_manage && (
                    <form
                        onSubmit={(e: FormEvent) => {
                            e.preventDefault();
                            rename.patch(route('channels.update', url), { preserveScroll: true, onSuccess: onClose });
                        }}
                    >
                        <label htmlFor="channel-rename" className="mb-1 block text-sm font-medium text-slate-700">
                            Name
                        </label>
                        <div className="flex gap-2">
                            <input
                                id="channel-rename"
                                className={field}
                                maxLength={60}
                                required
                                value={rename.data.name}
                                onChange={(e) => rename.setData('name', e.target.value)}
                            />
                            <button type="submit" className={primary} disabled={rename.processing}>
                                Rename
                            </button>
                        </div>
                        {rename.errors.name && <p className="mt-1 text-sm text-red-600">{rename.errors.name}</p>}
                    </form>
                )}

                <form
                    onSubmit={(e: FormEvent) => {
                        e.preventDefault();
                        topic.patch(route('channels.update', url), { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    <label htmlFor="channel-topic" className="mb-1 block text-sm font-medium text-slate-700">
                        Topic
                    </label>
                    <div className="flex gap-2">
                        <input
                            id="channel-topic"
                            className={field}
                            maxLength={250}
                            value={topic.data.topic}
                            onChange={(e) => topic.setData('topic', e.target.value)}
                            placeholder="What is this channel about?"
                        />
                        <button type="submit" className={primary} disabled={topic.processing}>
                            Save
                        </button>
                    </div>
                </form>

                {channel.type === 'private' && nonMembers.length > 0 && (
                    <form
                        onSubmit={(e: FormEvent) => {
                            e.preventDefault();
                            invite.post(route('channels.members', url), { preserveScroll: true, onSuccess: onClose });
                        }}
                    >
                        <fieldset className="max-h-40 space-y-1 overflow-y-auto rounded-md border border-slate-200 p-2">
                            <legend className="px-1 text-sm font-medium text-slate-700">Add people</legend>
                            {nonMembers.map((person) => (
                                <label key={person.id} className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        className="rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                                        checked={invite.data.member_ids.includes(person.id)}
                                        onChange={() => toggle(person.id)}
                                    />
                                    {person.name}
                                </label>
                            ))}
                        </fieldset>
                        <button
                            type="submit"
                            className={`${primary} mt-2`}
                            disabled={invite.processing || invite.data.member_ids.length === 0}
                        >
                            Add
                        </button>
                    </form>
                )}

                <div>
                    <label htmlFor="channel-section" className="mb-1 block text-sm font-medium text-slate-700">
                        Sidebar section
                    </label>
                    <div className="flex gap-2">
                        <select
                            id="channel-section"
                            className={field}
                            value={sectionId ?? ''}
                            onChange={(e) => moveTo(e.target.value ? Number(e.target.value) : null)}
                        >
                            <option value="">Channels</option>
                            {sections.map((section) => (
                                <option key={section.id} value={section.id}>
                                    {section.name}
                                </option>
                            ))}
                        </select>
                        <input
                            aria-label="New section name"
                            className={field}
                            maxLength={40}
                            placeholder="New section"
                            value={newSection}
                            onChange={(e) => setNewSection(e.target.value)}
                        />
                        <button type="button" className={secondary} onClick={addSection}>
                            Add
                        </button>
                    </div>
                </div>

                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        className="rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                        checked={muted}
                        onChange={toggleMute}
                    />
                    Mute this channel (you still hear about @mentions)
                </label>

                {leaveError && <p className="text-sm text-red-600">{leaveError}</p>}

                {confirming ? (
                    <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 pt-4">
                        <span className="text-sm text-slate-700">
                            {confirming === 'delete'
                                ? 'Delete all its messages and files?'
                                : 'Archive it? Nobody can post until it is restored.'}
                        </span>
                        <button type="button" className={secondary} onClick={() => setConfirming(null)}>
                            Keep
                        </button>
                        <button
                            type="button"
                            className={danger}
                            onClick={() =>
                                confirming === 'delete'
                                    ? router.delete(route('channels.destroy', url))
                                    : router.post(route('channels.archive', url))
                            }
                        >
                            {confirming === 'delete' ? 'Delete' : 'Archive'}
                        </button>
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 pt-4">
                        {channel.can_leave && (
                            <button
                                type="button"
                                className={secondary}
                                onClick={() => router.post(route('channels.leave', url), {}, { preserveScroll: true })}
                            >
                                Leave channel
                            </button>
                        )}
                        {channel.can_delete && (
                            <>
                                <button type="button" className={secondary} onClick={() => setConfirming('archive')}>
                                    Archive
                                </button>
                                <button type="button" className={secondary} onClick={() => setConfirming('delete')}>
                                    Delete channel
                                </button>
                            </>
                        )}
                        <button type="button" className={secondary} onClick={onClose}>
                            Close
                        </button>
                    </div>
                )}
            </div>
        </Modal>
    );
}
