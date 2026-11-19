<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Something an agent already said on a schedule; the unique key makes each notice happen once. */
class AiAgentNotice extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ai_agent_id', 'kind', 'subject'];

    /** Records the notice; false when it was already given (also across overlapping workers). */
    public static function claim(int $agentId, string $kind, string $subject): bool
    {
        return self::insertOrIgnore([
            'ai_agent_id' => $agentId,
            'kind' => $kind,
            'subject' => $subject,
            'created_at' => now(),
        ]) === 1;
    }

    public static function release(int $agentId, string $kind, string $subject): void
    {
        self::where(['ai_agent_id' => $agentId, 'kind' => $kind, 'subject' => $subject])->delete();
    }
}
