<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One pass through a workflow. It only stays in the table while waiting for a later step. */
class WorkflowRun extends Model
{
    protected $fillable = ['workflow_id', 'member_id', 'step', 'resume_at'];

    protected function casts(): array
    {
        return ['resume_at' => 'datetime'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }
}
