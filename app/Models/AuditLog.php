<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An append-only record of admin actions. Nothing in the app edits or deletes these. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'actor_id', 'actor_name', 'action', 'detail', 'ip'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public static function record(Workspace $workspace, User $actor, string $action, string $detail = ''): void
    {
        self::create([
            'workspace_id' => $workspace->id,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'action' => $action,
            'detail' => mb_substr($detail, 0, 255),
            'ip' => request()->ip(),
        ]);
    }
}
