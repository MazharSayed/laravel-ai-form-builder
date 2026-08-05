<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiGenerationLog extends Model
{
    protected $fillable = [
        'form_id', 'action', 'prompt', 'model', 'prompt_tokens',
        'completion_tokens', 'latency_ms', 'status', 'attempt', 'error',
    ];
}
