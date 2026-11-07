<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Solution extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'user_id', 'error_signature', 'content', 'compatibility', 'compatibility_hash', 'evidence', 'state', 'revision'];

    protected function casts(): array
    {
        return ['compatibility' => 'array', 'evidence' => 'array', 'revision' => 'integer'];
    }
}
