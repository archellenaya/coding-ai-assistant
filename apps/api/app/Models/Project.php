<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasUuids;

    protected $attributes = ['cloud_processing_allowed' => false];

    protected $fillable = ['organization_id', 'name', 'description', 'cloud_processing_allowed', 'archived_at'];

    protected function casts(): array
    {
        return ['cloud_processing_allowed' => 'boolean', 'archived_at' => 'datetime'];
    }
}
