<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Channel extends Model
{
    public const PUBLIC = 'public';

    public const PRIVATE = 'private';

    public const DM = 'dm';

    protected $fillable = ['workspace_id', 'name', 'type', 'dm_key', 'created_by', 'topic', 'description', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('last_read_message_id', 'starred_at', 'notify_all', 'muted', 'section_id')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(ChannelBookmark::class)->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    /** The workspace's first public channel. Everyone lands here, so it can't be deleted. */
    public function isDefault(): bool
    {
        return $this->type === self::PUBLIC
            && $this->id === self::where('workspace_id', $this->workspace_id)->where('type', self::PUBLIC)->min('id');
    }

    public function canManage(User $user, Workspace $workspace): bool
    {
        return $this->type !== self::DM && ($this->created_by === $user->id || $workspace->isAdmin($user));
    }

    public function deleteFiles(): void
    {
        Storage::disk('local')->deleteDirectory('attachments/'.$this->id);
    }

    /** A person's own space, where reminders arrive. */
    public static function selfDm(Workspace $workspace, User $user): self
    {
        $channel = $workspace->channels()->createOrFirst(
            ['dm_key' => self::dmKey($user->id, $user->id)],
            ['type' => self::DM, 'created_by' => $user->id],
        );
        $channel->members()->syncWithoutDetaching([$user->id]);

        return $channel;
    }

    public static function dmKey(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    /** Key for a group DM (3+ people). Group DMs are DMs too, so every DM rule still applies. */
    public static function groupDmKey(array $userIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        sort($ids);

        return 'g:'.implode('-', $ids);
    }

    public function isGroupDm(): bool
    {
        return $this->type === self::DM && str_starts_with((string) $this->dm_key, 'g:');
    }

    /**
     * Everyone in a group DM except $userId; null for other conversations.
     *
     * @return array<int, int>|null
     */
    public function groupDmOthers(int $userId): ?array
    {
        if (! $this->isGroupDm()) {
            return null;
        }

        $ids = array_map('intval', explode('-', substr($this->dm_key, 2)));

        return array_values(array_filter($ids, fn (int $id) => $id !== $userId));
    }

    /** The other participant of a DM, from the point of view of $userId. Null for group DMs. */
    public function dmPartnerId(int $userId): ?int
    {
        if ($this->type !== self::DM || $this->isGroupDm()) {
            return null;
        }

        [$low, $high] = array_map('intval', explode('-', $this->dm_key));

        return $low === $userId ? $high : $low;
    }
}
