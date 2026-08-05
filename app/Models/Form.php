<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Form extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'title', 'description', 'schema', 'schema_version',
        'status', 'public_key', 'settings', 'created_by',
    ];

    protected $casts = [
        'schema' => 'array',
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Form $form) {
            $form->public_key ??= Str::random(24);
            $form->schema_version ??= 1;
        });
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderByDesc('version_number');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function publicUrl(): string
    {
        return url("/f/{$this->public_key}");
    }

    public function flattenedFields(): array
    {
        $fields = [];
        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (($field['type'] ?? null) !== 'section_heading') {
                    $fields[] = $field;
                }
            }
        }
        return $fields;
    }

    public function saveSchema(array $schema, string $source, ?string $note = null, ?int $userId = null): void
    {
        $nextVersion = ($this->versions()->max('version_number') ?? 0) + 1;

        $this->versions()->create([
            'version_number' => $nextVersion,
            'schema' => $schema,
            'change_note' => $note,
            'source' => $source,
            'created_by' => $userId,
        ]);

        $this->update([
            'schema' => $schema,
            'schema_version' => $nextVersion,
        ]);
    }
}
