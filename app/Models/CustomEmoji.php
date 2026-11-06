<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomEmoji extends Model
{
    protected $table = 'custom_emojis';

    protected $fillable = ['workspace_id', 'created_by', 'name', 'path'];
}
