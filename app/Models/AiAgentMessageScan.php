<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAgentMessageScan extends Model
{
    protected $fillable = ['ai_agent_id', 'message_id', 'content_hash', 'status', 'ai_agent_run_id'];

    public static function hashFor(Message $message): string
    {
        return hash('sha256', $message->body ?? '');
    }
}
