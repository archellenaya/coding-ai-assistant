<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_id', 'attempt', 'type', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
