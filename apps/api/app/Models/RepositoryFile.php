<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RepositoryFile extends Model
{
    use HasUuids;

    protected $fillable = ['repository_id', 'path', 'language', 'parser', 'content_hash'];

    protected $hidden = ['values', 'embedding', 'search_vector'];

    protected function casts(): array
    {
        return [];
    }
}
