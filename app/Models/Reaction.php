<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reaction extends Model
{
    /** Starts with an emoji, a flag, or a keycap such as 1️⃣. */
    public const EMOJI_PATTERN = '/^(?:\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}]|[0-9#*]\x{FE0F}?\x{20E3})/u';

    protected $fillable = ['message_id', 'user_id', 'emoji'];
}
