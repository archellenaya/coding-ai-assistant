<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CodeChunk extends Model
{
    use HasUuids;

    protected $fillable = ['repository_file_id', 'ordinal', 'symbol', 'start_line', 'end_line', 'content', 'profile', 'model', 'model_version', 'dimensions', 'artifact_fingerprint', 'pooling', 'values'];

    protected $hidden = ['values', 'embedding', 'search_vector'];

    protected function casts(): array
    {
        return ['values' => 'array', 'start_line' => 'integer', 'end_line' => 'integer'];
    }
}
