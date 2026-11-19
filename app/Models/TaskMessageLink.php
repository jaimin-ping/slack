<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskMessageLink extends Model
{
    public const RELATIONS = ['source', 'update', 'completion'];

    protected $fillable = ['task_id', 'message_id', 'relation'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
