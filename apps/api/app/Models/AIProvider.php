<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AIProvider extends Model
{
    use HasUuids;

    protected $table = 'ai_providers';

    protected $fillable = ['name', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
