<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draft extends Model
{
    protected $fillable = ['user_id', 'channel_id', 'body'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
