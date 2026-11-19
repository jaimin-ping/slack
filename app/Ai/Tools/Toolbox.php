<?php

namespace App\Ai\Tools;

/** The set of tools a run may use, in a fixed order so the provider can cache the tool list. */
class Toolbox
{
    public function __construct(
        private MessageTools $messages,
        private TaskTools $tasks,
        private ReminderTools $reminders,
        private AgentTools $agents,
        private MessagingTools $messaging,
    ) {}

    /** @return array<string, Tool> */
    public function all(bool $includeWrites = true): array
    {
        $tools = [
            ...$this->messages->tools(),
            ...$this->tasks->tools(),
            ...$this->reminders->tools(),
            ...$this->agents->tools(),
            ...$this->messaging->tools(),
        ];

        $byName = [];

        foreach ($tools as $tool) {
            if ($includeWrites || ! $tool->writes()) {
                $byName[$tool->name] = $tool;
            }
        }

        return $byName;
    }
}
