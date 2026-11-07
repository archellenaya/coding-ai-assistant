<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConversationSummary extends Model
{
    use HasUuids;

    protected $fillable = ['conversation_id', 'through_sequence', 'status', 'content', 'model', 'attempt', 'usage', 'error_code'];

    protected $attributes = ['attempt' => 1];

    protected $hidden = ['search_vector'];

    protected function casts(): array
    {
        return ['usage' => 'array', 'attempt' => 'integer', 'through_sequence' => 'integer'];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
