<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Workflow extends Model
{
    public const MAX_STEPS = 10;

    public const MAX_WAIT_MINUTES = 43200;

    protected $fillable = [
        'workspace_id', 'created_by', 'name', 'trigger', 'time', 'timezone', 'steps', 'enabled', 'last_run_on',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'steps' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $names = Channel::whereIn('id', collect($this->steps)->pluck('channel_id')->filter())->pluck('name', 'id');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'trigger' => $this->trigger,
            'time' => $this->time,
            'steps' => collect($this->steps)->map(fn (array $step) => $step['type'] === 'message'
                ? [...$step, 'channel_name' => $names[$step['channel_id']] ?? null]
                : $step)->all(),
            'enabled' => $this->enabled,
        ];
    }
}
