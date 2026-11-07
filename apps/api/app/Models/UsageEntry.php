<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UsageEntry extends Model
{
    use HasUuids;

    protected $table = 'usage_ledgers';

    protected $fillable = ['model_call_id', 'reserved_micro_usd', 'estimated_micro_usd'];

    protected function casts(): array
    {
        return ['reserved_micro_usd' => 'integer', 'estimated_micro_usd' => 'integer'];
    }
}
