<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'user_id', 'title', 'provider_mode', 'preferred_model', 'allow_fallback'];

    protected function casts(): array
    {
        return ['allow_fallback' => 'boolean'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('sequence');
    }
}
