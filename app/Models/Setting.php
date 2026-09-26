<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'group', 'value', 'is_public'];

    protected function casts(): array
    {
        return ['value' => 'array', 'is_public' => 'boolean'];
    }
}
