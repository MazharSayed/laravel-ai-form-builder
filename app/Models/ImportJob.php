<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    protected $fillable = [
        'original_filename', 'disk_path', 'type', 'status', 'detected_schema',
        'mapping_overrides', 'unparseable_blocks', 'error', 'form_id', 'created_by',
    ];

    protected $casts = [
        'detected_schema' => 'array',
        'mapping_overrides' => 'array',
        'unparseable_blocks' => 'array',
    ];
}
