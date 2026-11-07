<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ModelCall extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'project_id', 'user_id', 'source_type', 'source_id', 'attempt', 'provider', 'model', 'status', 'ai_model_id', 'input_tokens', 'output_tokens', 'cached_tokens', 'duration_ms', 'error_code', 'pricing', 'context_metrics', 'cache_hit'];

    protected $appends = ['reserved_micro_usd', 'estimated_micro_usd'];

    public function ledger()
    {
        return $this->hasOne(UsageEntry::class);
    }

    public function getReservedMicroUsdAttribute()
    {
        return $this->ledger?->reserved_micro_usd;
    }

    public function getEstimatedMicroUsdAttribute()
    {
        return $this->ledger?->estimated_micro_usd;
    }

    protected function casts(): array
    {
        return ['pricing' => 'array', 'context_metrics' => 'array', 'cache_hit' => 'boolean', 'attempt' => 'integer'];
    }
}
