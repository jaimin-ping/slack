<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class UserGroup extends Model
{
    protected $fillable = ['workspace_id', 'handle'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_group_user');
    }
}
