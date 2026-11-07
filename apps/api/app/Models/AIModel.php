<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AIModel extends Model
{
    use HasUuids;

    protected $table = 'ai_models';

    protected $fillable = ['ai_provider_id', 'identifier', 'active', 'configuration'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'configuration' => 'array'];
    }
}
