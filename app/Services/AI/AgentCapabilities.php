<?php

namespace App\Services\AI;

/**
 * The fixed list of things an agent can be allowed to do, and the presets behind each automation mode.
 * Every action has its own policy, so a mode is only a starting point the owner can adjust.
 */
final class AgentCapabilities
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_INACTIVE];

    public const DENY = 'deny';

    public const APPROVE = 'approve';

    public const ALLOW = 'allow';

    public const POLICIES = [self::DENY, self::APPROVE, self::ALLOW];

    public const MODES = ['suggest', 'auto_tasks', 'controlled'];

    /** @var array<string, string> */
    public const ACTIONS = [
        'create_task' => 'Create tasks',
        'assign_task' => 'Assign tasks to other members',
        'update_task' => 'Update task status and details',
        'send_direct_message' => 'Send direct messages',
        'send_channel_message' => 'Post in channels',
        'reply_in_thread' => 'Reply in threads',
        'create_reminder' => 'Create reminders for you',
        'follow_up' => 'Follow up on pending tasks',
    ];

    public const AVATAR_PRESETS = ['robot', 'spark', 'owl', 'compass', 'rocket', 'leaf'];

    /** @var array<string, array<string, string>> */
    private const PRESETS = [
        // Mode 1: find tasks and propose them; nothing happens without the owner.
        'suggest' => [
            'create_task' => self::APPROVE,
            'assign_task' => self::APPROVE,
            'update_task' => self::APPROVE,
            'send_direct_message' => self::DENY,
            'send_channel_message' => self::DENY,
            'reply_in_thread' => self::DENY,
            'create_reminder' => self::APPROVE,
            'follow_up' => self::DENY,
        ],
        // Mode 2: create confident tasks on its own; anything that reaches other people waits for approval.
        'auto_tasks' => [
            'create_task' => self::ALLOW,
            'assign_task' => self::APPROVE,
            'update_task' => self::ALLOW,
            'send_direct_message' => self::APPROVE,
            'send_channel_message' => self::APPROVE,
            'reply_in_thread' => self::APPROVE,
            'create_reminder' => self::ALLOW,
            'follow_up' => self::DENY,
        ],
        // Mode 3: act within the granted conversations without asking.
        'controlled' => [
            'create_task' => self::ALLOW,
            'assign_task' => self::ALLOW,
            'update_task' => self::ALLOW,
            'send_direct_message' => self::ALLOW,
            'send_channel_message' => self::ALLOW,
            'reply_in_thread' => self::ALLOW,
            'create_reminder' => self::ALLOW,
            'follow_up' => self::ALLOW,
        ],
    ];

    /** @return array<string, string> */
    public static function preset(string $mode): array
    {
        return self::PRESETS[$mode] ?? self::PRESETS['suggest'];
    }

    /** @return array<string, mixed> */
    public static function defaultSettings(): array
    {
        return [
            'task_detection' => [
                'enabled' => true,
                'realtime' => true,
                'min_confidence' => 0.7,
                'only_mine' => false,
            ],
            'notifications' => [
                'notify_assignees' => true,
                'overdue_alerts' => true,
            ],
            'daily_summary' => [
                'enabled' => false,
                'time' => '18:00',
            ],
        ];
    }

    /** Validation rules for the settings object, shared by create and update. */
    public static function settingsRules(): array
    {
        return [
            'settings' => ['sometimes', 'array'],
            'settings.task_detection' => ['sometimes', 'array'],
            'settings.task_detection.enabled' => ['sometimes', 'boolean'],
            'settings.task_detection.realtime' => ['sometimes', 'boolean'],
            'settings.task_detection.min_confidence' => ['sometimes', 'numeric', 'between:0,1'],
            'settings.task_detection.only_mine' => ['sometimes', 'boolean'],
            'settings.notifications' => ['sometimes', 'array'],
            'settings.notifications.notify_assignees' => ['sometimes', 'boolean'],
            'settings.notifications.overdue_alerts' => ['sometimes', 'boolean'],
            'settings.daily_summary' => ['sometimes', 'array'],
            'settings.daily_summary.enabled' => ['sometimes', 'boolean'],
            'settings.daily_summary.time' => ['sometimes', 'date_format:H:i'],
        ];
    }

    /**
     * Keeps only known keys, so stored settings never carry unexpected values.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function mergeSettings(array $current, array $incoming): array
    {
        $merged = self::defaultSettings();

        foreach ($merged as $group => $values) {
            foreach ($values as $key => $default) {
                $value = $incoming[$group][$key] ?? $current[$group][$key] ?? $default;
                $merged[$group][$key] = match (true) {
                    is_bool($default) => (bool) $value,
                    is_float($default) => round((float) $value, 2),
                    default => (string) $value,
                };
            }
        }

        return $merged;
    }
}
