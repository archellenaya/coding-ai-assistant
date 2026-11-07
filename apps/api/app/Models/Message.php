<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasUuids;

    protected $attributes = ['attempt' => 1];

    protected $fillable = ['conversation_id', 'sequence', 'role', 'status', 'content', 'idempotency_key', 'reply_to_id', 'provider', 'model', 'attempt', 'usage', 'provider_response_id', 'error_code', 'started_at', 'memory_sources', 'code_sources', 'routing', 'context_metrics', 'request_hash'];

    protected function casts(): array
    {
        return ['routing' => 'array', 'context_metrics' => 'array', 'usage' => 'array', 'started_at' => 'datetime', 'attempt' => 'integer', 'memory_sources' => 'array', 'code_sources' => 'array'];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function events()
    {
        return $this->hasMany(MessageEvent::class)->orderBy('id');
    }
}
