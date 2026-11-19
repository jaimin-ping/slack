<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAgentRunStep extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ai_agent_run_id', 'sequence', 'type', 'name', 'status', 'input', 'output'];

    protected function casts(): array
    {
        return ['input' => 'array', 'output' => 'array', 'created_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'sequence' => $this->sequence,
            'type' => $this->type,
            'name' => $this->name,
            'status' => $this->status,
            'input' => $this->input,
            'output' => $this->output,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
