<?php

namespace App\Services\AI;

/**
 * A cheap first pass that runs before any AI call. It only throws away messages that clearly cannot
 * hold a task ("ok", "thanks 👍"), so most chatter costs nothing; anything plausible goes to the model.
 */
class MessagePrefilter
{
    private const SIGNALS = [
        '@', '?',
        'please', 'pls', 'plz', 'can you', 'could you', 'would you', 'will you', 'need to', 'needs to', 'have to', 'has to',
        'must', 'should', "let's", 'lets ', 'todo', 'to do', 'to-do', 'action item', 'follow up', 'follow-up', 'remind',
        'deadline', 'due', 'asap', 'urgent', 'by ', 'before ', 'until ', 'tomorrow', 'tonight', 'today', 'eod', 'eow',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'next week', 'this week',
        "i'll", 'i will', "i'm going to", 'i am going to', 'on it', 'assign', 'owner',
        'complete', 'finish', 'fix', 'review', 'send', 'share', 'prepare', 'update', 'deploy', 'test', 'check', 'coordinate',
        'schedule', 'draft', 'write', 'create', 'build', 'implement', 'investigate', 'ship', 'release', 'call ', 'email',
        'done', 'completed', 'finished', 'blocked', 'stuck', 'waiting on', 'started', 'working on', 'in progress',
    ];

    public function mightContainWork(?string $body): bool
    {
        $text = mb_strtolower(trim((string) $body));

        if (mb_strlen($text) < 8) {
            return false;
        }

        foreach (self::SIGNALS as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }
}
