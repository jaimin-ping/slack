<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Section extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'name',
        'icon',
        'type',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SectionItem::class);
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'section_items')
            ->withPivot('added_at')
            ->withTimestamps();
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'type' => $this->type,
            'is_system' => $this->is_system,
            'items_count' => $this->items()->count(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
