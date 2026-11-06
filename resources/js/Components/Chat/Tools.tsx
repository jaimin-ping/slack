import Modal from '@/Components/Modal';
import { ChatUser, CustomEmojiInfo, SidebarChannel, UserGroupInfo, WorkspaceInfo } from '@/types/chat';
import { router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { FormEvent, useEffect, useRef, useState } from 'react';

const field = 'w-full rounded-md border-slate-300 text-sm focus:border-teal-600 focus:ring-teal-600';
const primary = 'rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50';
const secondary = 'rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100';

const reason = (e: unknown, fallback: string) =>
    (isAxiosError<{ message?: string }>(e) && e.response?.data?.message) || fallback;

const refresh = () => router.reload({ only: ['extras'] });

export function GroupsModal({
    show,
    workspace,
    groups,
    members,
    onClose,
}: {
    show: boolean;
    workspace: WorkspaceInfo;
    groups: UserGroupInfo[];
    members: ChatUser[];
    onClose: () => void;
}) {
    const [handle, setHandle] = useState('');
    const [picked, setPicked] = useState<number[]>([]);
    const [error, setError] = useState<string | null>(null);

    const create = async (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        try {
            await axios.post(route('groups.store', workspace.slug), { handle, member_ids: picked });
            setHandle('');
            setPicked([]);
            refresh();
        } catch (err) {
            setError(reason(err, 'Group not created.'));
        }
    };

    const remove = async (id: number) => {
        await axios.delete(route('groups.destroy', [workspace.slug, id]));
        refresh();
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="p-5">
                <h2 className="text-lg font-semibold text-slate-900">User groups</h2>
                <p className="mb-3 mt-1 text-sm text-slate-600">Mention a whole team at once, for example @support.</p>

                <ul className="mb-4 divide-y divide-slate-200">
                    {groups.map((group) => (
                        <li key={group.id} className="flex items-center justify-between py-2 text-sm text-slate-800">
                            <span>
                                @{group.handle}{' '}
                                <span className="text-slate-500">
                                    · {group.member_ids.length} {group.member_ids.length === 1 ? 'person' : 'people'}
                                </span>
                            </span>
                            <button type="button" className={secondary} onClick={() => remove(group.id)}>
                                Delete
                            </button>
                        </li>
                    ))}
                </ul>

                <form onSubmit={create} className="space-y-2">
                    <input
                        aria-label="Group handle"
                        className={field}
                        placeholder="support"
                        required
                        value={handle}
                        onChange={(e) => setHandle(e.target.value.toLowerCase())}
                    />
                    <fieldset className="max-h-32 space-y-1 overflow-y-auto rounded-md border border-slate-200 p-2">
                        {members.map((member) => (
                            <label key={member.id} className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    className="rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                                    checked={picked.includes(member.id)}
                                    onChange={() =>
                                        setPicked(
                                            picked.includes(member.id)
                                                ? picked.filter((id) => id !== member.id)
                                                : [...picked, member.id],
                                        )
                                    }
                                />
                                {member.name}
                            </label>
                        ))}
                    </fieldset>
                    {error && <p className="text-sm text-red-600">{error}</p>}
                    <div className="flex justify-end gap-2">
                        <button type="button" className={secondary} onClick={onClose}>
                            Done
                        </button>
                        <button type="submit" className={primary} disabled={picked.length === 0}>
                            Create group
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    );
}

export function EmojiModal({
    show,
    workspace,
    emojis,
    onClose,
}: {
    show: boolean;
    workspace: WorkspaceInfo;
    emojis: CustomEmojiInfo[];
    onClose: () => void;
}) {
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const file = useRef<HTMLInputElement>(null);

    const upload = async (e: FormEvent) => {
        e.preventDefault();
        const image = file.current?.files?.[0];
        if (!image) return;

        const form = new FormData();
        form.append('name', name);
        form.append('image', image);
        setError(null);
        try {
            await axios.post(route('emoji.store', workspace.slug), form);
            setName('');
            if (file.current) file.current.value = '';
            refresh();
        } catch (err) {
            setError(reason(err, 'Emoji not added.'));
        }
    };

    const remove = async (id: number) => {
        setError(null);
        try {
            await axios.delete(route('emoji.destroy', [workspace.slug, id]));
            refresh();
        } catch (err) {
            setError(reason(err, 'Only the person who added it or an admin can delete it.'));
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="p-5">
                <h2 className="text-lg font-semibold text-slate-900">Custom emoji</h2>
                <p className="mb-3 mt-1 text-sm text-slate-600">Type :name: in a message. PNG, JPG, GIF or WebP, up to 256 KB.</p>

                <ul className="mb-4 max-h-48 divide-y divide-slate-200 overflow-y-auto">
                    {emojis.map((emoji) => (
                        <li key={emoji.id} className="flex items-center gap-2 py-1.5 text-sm text-slate-800">
                            <img src={emoji.url} alt="" className="h-6 w-6 object-contain" />
                            <span className="flex-1">:{emoji.name}:</span>
                            <button type="button" className={secondary} onClick={() => remove(emoji.id)}>
                                Delete
                            </button>
                        </li>
                    ))}
                </ul>

                <form onSubmit={upload} className="space-y-2">
                    <input
                        aria-label="Emoji name"
                        className={field}
                        placeholder="party-cat"
                        required
                        value={name}
                        onChange={(e) => setName(e.target.value.toLowerCase())}
                    />
                    <input ref={file} aria-label="Emoji image" type="file" required accept="image/png,image/gif,image/webp,image/jpeg" className="text-sm" />
                    {error && <p className="text-sm text-red-600">{error}</p>}
                    <div className="flex justify-end gap-2">
                        <button type="button" className={secondary} onClick={onClose}>
                            Done
                        </button>
                        <button type="submit" className={primary}>
                            Add emoji
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    );
}

type WaitUnit = 'minutes' | 'hours' | 'days';

const UNIT_MINUTES: Record<WaitUnit, number> = { minutes: 1, hours: 60, days: 1440 };

interface DraftStep {
    key: number;
    type: 'message' | 'dm' | 'wait';
    channelId: number | '';
    text: string;
    amount: number;
    unit: WaitUnit;
}

type SavedStep =
    | { type: 'message'; channel_id: number; channel_name: string | null; text: string }
    | { type: 'dm'; text: string }
    | { type: 'wait'; minutes: number };

interface WorkflowInfo {
    id: number;
    name: string;
    trigger: 'daily' | 'member_joined';
    time: string | null;
    steps: SavedStep[];
    enabled: boolean;
}

const waitLabel = (minutes: number) => {
    if (minutes % 1440 === 0) return `${minutes / 1440} d`;
    if (minutes % 60 === 0) return `${minutes / 60} h`;

    return `${minutes} min`;
};

const stepSummary = (steps: SavedStep[]) =>
    steps
        .map((step) => {
            if (step.type === 'wait') return `Wait ${waitLabel(step.minutes)}`;
            if (step.type === 'dm') return 'Message the new member';

            return step.channel_name ? `#${step.channel_name}` : 'Deleted channel';
        })
        .join(' → ');

// The first step keeps the plain name; later ones say which step they belong to.
const stepLabel = (name: string, index: number) => (index === 0 ? name : `${name}, step ${index + 1}`);

export function WorkflowsModal({
    show,
    workspace,
    channels,
    onClose,
}: {
    show: boolean;
    workspace: WorkspaceInfo;
    channels: SidebarChannel[];
    onClose: () => void;
}) {
    const nextKey = useRef(1);
    const newStep = (type: DraftStep['type']): DraftStep => ({
        key: nextKey.current++,
        type,
        channelId: '',
        text: '',
        amount: 1,
        unit: 'hours',
    });

    const [items, setItems] = useState<WorkflowInfo[]>([]);
    const [name, setName] = useState('');
    const [trigger, setTrigger] = useState<'daily' | 'member_joined'>('daily');
    const [time, setTime] = useState('09:30');
    const [steps, setSteps] = useState<DraftStep[]>(() => [newStep('message')]);
    const [error, setError] = useState<string | null>(null);

    const load = () =>
        axios.get<WorkflowInfo[]>(route('workflows.index', workspace.slug)).then(({ data }) => setItems(data));

    useEffect(() => {
        if (show) load().catch(() => setError('Could not load workflows.'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show]);

    const change = (key: number, values: Partial<DraftStep>) =>
        setSteps((current) => current.map((step) => (step.key === key ? { ...step, ...values } : step)));

    const removeStep = (key: number) => setSteps((current) => current.filter((step) => step.key !== key));

    const moveUp = (index: number) =>
        setSteps((current) => {
            const next = [...current];
            [next[index - 1], next[index]] = [next[index], next[index - 1]];

            return next;
        });

    const changeTrigger = (value: 'daily' | 'member_joined') => {
        setTrigger(value);
        // Only a new member has someone to message directly.
        if (value === 'daily') setSteps((current) => current.filter((step) => step.type !== 'dm'));
    };

    const create = async (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        try {
            await axios.post(route('workflows.store', workspace.slug), {
                name,
                trigger,
                time: trigger === 'daily' ? time : null,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                steps: steps.map((step) =>
                    step.type === 'wait'
                        ? { type: 'wait', minutes: step.amount * UNIT_MINUTES[step.unit] }
                        : { type: step.type, channel_id: step.channelId || null, text: step.text },
                ),
            });
            setName('');
            setSteps([newStep('message')]);
            await load();
        } catch (err) {
            setError(reason(err, 'Workflow not created.'));
        }
    };

    const toggle = async (id: number) => {
        await axios.post(route('workflows.toggle', [workspace.slug, id]));
        await load();
    };

    const remove = async (id: number) => {
        await axios.delete(route('workflows.destroy', [workspace.slug, id]));
        await load();
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="xl">
            <div className="max-h-[85vh] overflow-y-auto p-5">
                <h2 className="text-lg font-semibold text-slate-900">Workflow Builder</h2>
                <p className="mb-3 mt-1 text-sm text-slate-600">
                    Run steps in order, every day or when someone joins. Pausing a workflow also stops runs that are waiting.
                </p>

                <ul className="mb-4 divide-y divide-slate-200">
                    {items.map((item) => (
                        <li key={item.id} className="flex items-center gap-2 py-2 text-sm">
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-semibold text-slate-800">{item.name}</span>
                                <span className="block text-xs text-slate-500">
                                    {item.trigger === 'daily' ? `Every day at ${item.time}` : 'When someone joins'} ·{' '}
                                    {stepSummary(item.steps)}
                                </span>
                            </span>
                            <button type="button" className={secondary} onClick={() => toggle(item.id)}>
                                {item.enabled ? 'Pause' : 'Resume'}
                            </button>
                            <button type="button" className={secondary} onClick={() => remove(item.id)}>
                                Delete
                            </button>
                        </li>
                    ))}
                </ul>

                <form onSubmit={create} className="space-y-2">
                    <input aria-label="Workflow name" className={field} placeholder="Daily standup" required maxLength={80} value={name} onChange={(e) => setName(e.target.value)} />
                    <div className="flex gap-2">
                        <select aria-label="Trigger" className={field} value={trigger} onChange={(e) => changeTrigger(e.target.value as typeof trigger)}>
                            <option value="daily">Every day at a time</option>
                            <option value="member_joined">When someone joins</option>
                        </select>
                        {trigger === 'daily' && (
                            <input aria-label="Time" type="time" required className={field} value={time} onChange={(e) => setTime(e.target.value)} />
                        )}
                    </div>

                    <ol className="space-y-2">
                        {steps.map((step, index) => (
                            <li key={step.key} className="space-y-1 rounded-md border border-slate-200 p-2">
                                <div className="flex items-center justify-between gap-2 text-xs font-semibold text-slate-600">
                                    <span>
                                        Step {index + 1}:{' '}
                                        {step.type === 'wait' ? 'Wait' : step.type === 'dm' ? 'Message the new member' : 'Post in a channel'}
                                    </span>
                                    <span className="flex gap-2 font-normal">
                                        {index > 0 && (
                                            <button type="button" aria-label={`Move step ${index + 1} up`} className="text-sky-700 hover:underline" onClick={() => moveUp(index)}>
                                                Move up
                                            </button>
                                        )}
                                        {steps.length > 1 && (
                                            <button type="button" aria-label={`Remove step ${index + 1}`} className="text-sky-700 hover:underline" onClick={() => removeStep(step.key)}>
                                                Remove
                                            </button>
                                        )}
                                    </span>
                                </div>

                                {step.type === 'wait' ? (
                                    <div className="flex gap-2">
                                        <input
                                            aria-label={stepLabel('Wait for', index)}
                                            type="number"
                                            min={1}
                                            required
                                            className={field}
                                            value={step.amount}
                                            onChange={(e) => change(step.key, { amount: Number(e.target.value) })}
                                        />
                                        <select
                                            aria-label={stepLabel('Wait unit', index)}
                                            className={field}
                                            value={step.unit}
                                            onChange={(e) => change(step.key, { unit: e.target.value as WaitUnit })}
                                        >
                                            <option value="minutes">Minutes</option>
                                            <option value="hours">Hours</option>
                                            <option value="days">Days</option>
                                        </select>
                                    </div>
                                ) : (
                                    <>
                                        {step.type === 'message' && (
                                            <select
                                                aria-label={stepLabel('Channel', index)}
                                                required
                                                className={field}
                                                value={step.channelId}
                                                onChange={(e) => change(step.key, { channelId: Number(e.target.value) })}
                                            >
                                                <option value="" disabled>
                                                    Choose a channel
                                                </option>
                                                {channels.filter((c) => c.type !== 'dm').map((c) => (
                                                    <option key={c.id} value={c.id}>
                                                        #{c.name}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                        <textarea
                                            aria-label={stepLabel('Message', index)}
                                            className={field}
                                            required
                                            rows={2}
                                            maxLength={2000}
                                            placeholder={trigger === 'daily' ? 'Time for standup!' : 'Welcome {name}!'}
                                            value={step.text}
                                            onChange={(e) => change(step.key, { text: e.target.value })}
                                        />
                                    </>
                                )}
                            </li>
                        ))}
                    </ol>

                    {steps.length < 10 && (
                        <div className="flex flex-wrap gap-1">
                            <button type="button" className={secondary} onClick={() => setSteps([...steps, newStep('message')])}>
                                Add message
                            </button>
                            <button type="button" className={secondary} onClick={() => setSteps([...steps, newStep('wait')])}>
                                Add wait
                            </button>
                            {trigger === 'member_joined' && (
                                <button type="button" className={secondary} onClick={() => setSteps([...steps, newStep('dm')])}>
                                    Add message to new member
                                </button>
                            )}
                        </div>
                    )}

                    {error && <p className="text-sm text-red-600">{error}</p>}
                    <div className="flex justify-end gap-2">
                        <button type="button" className={secondary} onClick={onClose}>
                            Done
                        </button>
                        <button type="submit" className={primary}>
                            Create workflow
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    );
}
