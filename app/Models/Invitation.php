<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Permission for one email address to join one workspace. */
class Invitation extends Model
{
    protected $fillable = ['workspace_id', 'email', 'invited_by', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at');
    }
}
