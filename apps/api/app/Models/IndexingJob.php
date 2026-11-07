<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IndexingJob extends Model
{
    use HasUuids;

    protected $fillable = ['repository_id', 'user_id', 'status', 'error_code', 'stats'];

    protected $hidden = ['values', 'embedding', 'search_vector'];

    protected function casts(): array
    {
        return ['stats' => 'array'];
    }
}
