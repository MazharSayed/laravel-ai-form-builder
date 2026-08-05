<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Webhook extends Model
{
    protected $fillable = ['form_id', 'url', 'secret', 'events', 'is_active'];

    protected $casts = ['events' => 'array', 'is_active' => 'boolean'];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
