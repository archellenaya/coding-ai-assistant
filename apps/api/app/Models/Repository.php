<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Repository extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'name', 'path', 'revision', 'parser_version', 'profile', 'languages', 'frameworks'];

    protected $hidden = ['values', 'embedding', 'search_vector'];

    protected function casts(): array
    {
        return ['languages' => 'array', 'frameworks' => 'array'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
