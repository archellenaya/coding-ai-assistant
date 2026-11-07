<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Memory extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'user_id', 'conversation_id', 'source_message_id', 'summary_id', 'category', 'content', 'content_hash', 'source_kind', 'source_key', 'provenance', 'state', 'revision', 'index_status', 'index_token', 'index_error', 'error_signature', 'git_commit'];

    protected $attributes = ['state' => 'active', 'verification_status' => 'unverified', 'revision' => 1, 'index_status' => 'pending'];

    protected $hidden = ['search_vector', 'index_token'];

    protected function casts(): array
    {
        return ['provenance' => 'array', 'revision' => 'integer'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
