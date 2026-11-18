<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Attachment extends Model
{
    /** Only these open in the browser. SVG is excluded because it can carry scripts. */
    public const INLINE_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    protected $fillable = ['message_id', 'name', 'path', 'mime', 'size', 'description', 'download_count'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(AttachmentShare::class);
    }

    public function savedItems(): MorphMany
    {
        return $this->morphMany(SavedItem::class, 'saveable');
    }

    public function folderItems(): HasMany
    {
        return $this->hasMany(FolderItem::class);
    }

    public function sectionItems(): HasMany
    {
        return $this->hasMany(SectionItem::class);
    }

    public function isInlineImage(): bool
    {
        return in_array($this->mime, self::INLINE_IMAGE_MIMES, true);
    }

    public function incrementDownloadCount(): void
    {
        $this->increment('download_count');
    }

    public function formatSize(): string
    {
        $bytes = $this->size;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'is_image' => $this->isInlineImage(),
            'url' => route('attachments.show', $this->id, false),
        ];
    }

    public function toDetailPayload(?int $userId = null): array
    {
        $isSaved = false;
        if ($userId) {
            $isSaved = $this->savedItems()->where('user_id', $userId)->exists();
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'original_name' => $this->name,
            'description' => $this->description,
            'url' => route('attachments.show', $this->id, false),
            'download_url' => route('api.attachments.download', $this->id, false),
            'mime_type' => $this->mime,
            'size' => $this->size,
            'size_formatted' => $this->formatSize(),
            'is_image' => $this->isInlineImage(),
            'uploaded_by' => $this->message?->user ? [
                'id' => $this->message->user->id,
                'name' => $this->message->user->name,
                'avatar_url' => $this->message->user->avatar ? route('api.avatars.show', $this->message->user->id, false) : null,
            ] : null,
            'message' => $this->message ? [
                'id' => $this->message->id,
                'channel_id' => $this->message->channel_id,
                'channel_name' => $this->message->channel?->name,
            ] : null,
            'is_saved' => $isSaved,
            'download_count' => $this->download_count ?? 0,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
