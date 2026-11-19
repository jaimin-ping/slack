const PRESET_EMOJI: Record<string, string> = {
    robot: '🤖',
    spark: '✨',
    owl: '🦉',
    compass: '🧭',
    rocket: '🚀',
    leaf: '🍃',
};

export const presetEmoji = (preset: string | null | undefined) => PRESET_EMOJI[preset ?? 'robot'] ?? '🤖';

/** An agent's picture: its uploaded photo, or its chosen built-in avatar. Always visibly an AI. */
export default function AgentAvatar({
    agent,
    className = 'h-9 w-9 rounded-lg',
}: {
    agent: { name: string; avatar_url?: string | null; avatar_preset?: string | null };
    className?: string;
}) {
    if (agent.avatar_url) {
        return <img src={agent.avatar_url} alt="" className={`shrink-0 object-cover ring-2 ring-violet-300 ${className}`} />;
    }

    return (
        <span
            aria-hidden
            title={`${agent.name} (AI Assistant)`}
            className={`flex shrink-0 items-center justify-center bg-violet-100 text-lg ring-2 ring-violet-300 ${className}`}
        >
            {presetEmoji(agent.avatar_preset)}
        </span>
    );
}
