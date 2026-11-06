<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SidebarSection extends Model
{
    protected $fillable = ['user_id', 'workspace_id', 'name'];
}
