<?php

namespace App\Livewire;

use App\Models\Form;
use App\Services\FormSchemaValidator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
/**
 * Part A — the visual form builder (Livewire full-page component).
 *
 * THE CORE IDEA: everything here manipulates one thing — the $schema array
 * (the form's JSON structure). The palette, the canvas, and the raw JSON
 * editor are three views of that same schema. Every mutation method changes
 * $schema and then calls syncJsonFromSchema() so the JSON panel stays in step.
 * The JSON is the single source of truth; the visual canvas is just a friendly
 * editor for it. Nothing is saved to the database until save() runs it through
 * the validator.
 */
class FormBuilder extends Component
{
    public Form $form;

    public array $schema = [];          // the live form structure being edited
    public string $jsonEditorText = ''; // the raw JSON shown in the editor panel (kept in sync with $schema)
    public array $jsonErrors = [];      // validation errors shown under the JSON panel

    public ?string $activeFieldId = null;    // which field is selected (for the properties panel)
    public string $aiInstruction = '';       // the "AI edit" text box
    public ?string $aiTrackingId = null;     // tracks an in-progress AI edit job

    public ?string $activeSectionId = null;  // which section new fields get added to

    /**
     * Load the form's schema into the editor. If it's a brand-new/empty form we
     * start with one empty section. We default the "active section" to the first
     * one so the Add-field palette has a target immediately.
     */
    public function mount(Form $form): void
    {
        $this->form = $form;
        $this->schema = $form->schema ?: ['version' => 1, 'sections' => []];
        $this->syncJsonFromSchema();
        $this->activeSectionId = $this->schema['sections'][0]['id'] ?? null;
    }

    /**
     * Add a new field to a section. Every field gets a stable internal id and a
     * unique snake_case key (lowercased — an earlier bug produced mixed-case
     * keys that failed the snake_case validation rule). Choice-type fields
     * (dropdown/radio/checkbox) start with one option so they're valid straight
     * away.
     */
    public function addField(string $sectionId, string $type): void
    {
        $section = $this->findSection($sectionId);
        if (!$section) return;

        $newField = [
            'id' => 'fld_' . Str::random(8),
            'key' => 'field_' . strtolower(Str::random(6)), // lowercased to satisfy snake_case validation
            'type' => $type,
            'label' => 'New ' . str_replace('_', ' ', $type) . ' field',
            'placeholder' => null,
            'help_text' => null,
            'default' => null,
            'required' => false,
            'options' => in_array($type, ['dropdown', 'radio', 'checkbox'])
                ? [['value' => 'option_1', 'label' => 'Option 1']]
                : [],
            'validation' => [],
            'visible_if' => null,
        ];

        $this->mutateSection($sectionId, function (&$section) use ($newField) {
            $section['fields'][] = $newField;
        });

        $this->activeFieldId = $newField['id']; // auto-select the new field for editing
        $this->syncJsonFromSchema();
    }

    /** Add a new empty section to the form. */
    public function addSection(): void
    {
        $this->schema['sections'][] = [
            'id' => 'sec_' . Str::random(8),
            'title' => 'New section',
            'description' => null,
            'fields' => [],
        ];
        $this->syncJsonFromSchema();
    }

    /**
     * Duplicate a field. The copy gets a fresh id (ids must be unique) and a
     * "_copy" suffix on its key (keys must be unique too, since the key is what
     * submitted answers are stored under).
     */
    public function duplicateField(string $sectionId, string $fieldId): void
    {
        $this->mutateSection($sectionId, function (&$section) use ($fieldId) {
            foreach ($section['fields'] as $field) {
                if ($field['id'] === $fieldId) {
                    $copy = $field;
                    $copy['id'] = 'fld_' . Str::random(8);
                    $copy['key'] = $field['key'] . '_copy';
                    $section['fields'][] = $copy;
                    break;
                }
            }
        });
        $this->syncJsonFromSchema();
    }

    /** Remove a field from a section. */
    public function deleteField(string $sectionId, string $fieldId): void
    {
        $this->mutateSection($sectionId, function (&$section) use ($fieldId) {
            $section['fields'] = array_values(array_filter(
                $section['fields'],
                fn ($f) => $f['id'] !== $fieldId
            ));
        });
        $this->syncJsonFromSchema();
    }

    /**
     * Reorder fields after a drag-and-drop. The JS (SortableJS) sends the new
     * order as a list of field ids; we rebuild the fields array to match that
     * order. Simplest possible contract between the JS and PHP sides.
     */
    public function reorderFields(string $sectionId, array $orderedIds): void
    {
        $this->mutateSection($sectionId, function (&$section) use ($orderedIds) {
            $byId = collect($section['fields'])->keyBy('id');
            $section['fields'] = collect($orderedIds)
                ->map(fn ($id) => $byId->get($id))
                ->filter()
                ->values()
                ->all();
        });
        $this->syncJsonFromSchema();
    }

    /**
     * Update a single property on a field (or a section, when fieldId is empty —
     * used for editing the section title). "required" comes from a checkbox as a
     * string, so we normalise it to a real boolean.
     */
    public function updateFieldProperty(string $sectionId, string $fieldId, string $property, $value): void
    {
        if ($fieldId === '') {
            // Empty fieldId = a section-level edit (e.g. the section title).
            $this->mutateSection($sectionId, function (&$section) use ($property, $value) {
                data_set($section, $property, $value);
            });
            $this->syncJsonFromSchema();
            return;
        }

        $this->mutateSection($sectionId, function (&$section) use ($fieldId, $property, $value) {
            foreach ($section['fields'] as &$field) {
                if ($field['id'] === $fieldId) {
                    if ($property === 'required') {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    }
                    data_set($field, $property, $value);
                    break;
                }
            }
        });
        $this->syncJsonFromSchema();
    }

    /**
     * Returns the currently-selected field's data (plus its section id) so the
     * properties panel in the view can render its editable inputs. Null if
     * nothing is selected.
     */
    public function getActiveFieldProperty(): ?array
    {
        if (!$this->activeFieldId) return null;

        foreach ($this->schema['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['id'] === $this->activeFieldId) {
                    return ['section_id' => $section['id']] + $field;
                }
            }
        }
        return null;
    }

    // --- Option management for choice fields (dropdown/radio/checkbox) ---

    /** Add a new option to a choice field. */
    public function addOption(string $sectionId, string $fieldId): void
    {
        $this->mutateSection($sectionId, function (&$section) use ($fieldId) {
            foreach ($section['fields'] as &$field) {
                if ($field['id'] === $fieldId) {
                    $n = count($field['options'] ?? []) + 1;
                    $field['options'][] = ['value' => "option_{$n}", 'label' => "Option {$n}"];
                    break;
                }
            }
        });
        $this->syncJsonFromSchema();
    }

    /** Edit an option's label or value. */
    public function updateOption(string $sectionId, string $fieldId, int $index, string $property, string $value): void
    {
        $this->mutateSection($sectionId, function (&$section) use ($fieldId, $index, $property, $value) {
            foreach ($section['fields'] as &$field) {
                if ($field['id'] === $fieldId) {
                    if (isset($field['options'][$index])) {
                        $field['options'][$index][$property] = $value;
                    }
                    break;
                }
            }
        });
        $this->syncJsonFromSchema();
    }

    /** Remove an option, re-indexing the array so there are no gaps. */
    public function removeOption(string $sectionId, string $fieldId, int $index): void
    {
        $this->mutateSection($sectionId, function (&$section) use ($fieldId, $index) {
            foreach ($section['fields'] as &$field) {
                if ($field['id'] === $fieldId) {
                    unset($field['options'][$index]);
                    $field['options'] = array_values($field['options']);
                    break;
                }
            }
        });
        $this->syncJsonFromSchema();
    }

    /**
     * Set or clear a validation rule on a field (min_length, max, etc.). An
     * empty value removes the rule; anything else sets it. This is how the
     * properties panel's validation inputs feed into the schema.
     */
    public function updateValidationRule(string $sectionId, string $fieldId, string $rule, $value): void
    {
        $this->mutateSection($sectionId, function (&$section) use ($fieldId, $rule, $value) {
            foreach ($section['fields'] as &$field) {
                if ($field['id'] === $fieldId) {
                    if ($value === '' || $value === null) {
                        unset($field['validation'][$rule]);
                    } else {
                        $field['validation'][$rule] = $value;
                    }
                    break;
                }
            }
        });
        $this->syncJsonFromSchema();
    }

    /**
     * The OTHER direction of the two-way sync: when the user edits the raw JSON
     * panel directly, we parse it. If it's invalid JSON, or fails schema
     * validation, we show the errors but DON'T touch the canvas — so a typo in
     * the JSON never corrupts the live form. The canvas only updates once the
     * JSON is both valid syntax and a valid schema.
     */
    public function updatedJsonEditorText(): void
    {
        $decoded = json_decode($this->jsonEditorText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->jsonErrors = ['Invalid JSON syntax: ' . json_last_error_msg()];
            return;
        }

        $this->jsonErrors = app(FormSchemaValidator::class)->validateSchema($decoded);

        if (empty($this->jsonErrors)) {
            $this->schema = $decoded;
        }
    }

    /**
     * Part B — AI editing of the current form. Fires a background job with the
     * user's instruction plus this form's id (so the AI edits THIS form rather
     * than generating a new one), then polls for the result via checkAiStatus().
     */
    public function requestAiEdit(): void
    {
        if (trim($this->aiInstruction) === '') return;

        $this->aiTrackingId = (string) Str::uuid();

        \App\Jobs\GenerateFormFromPrompt::dispatch(
            trackingId: $this->aiTrackingId,
            prompt: $this->aiInstruction,
            existingFormId: $this->form->id, // set → edit mode, not generate mode
            userId: auth()->id(),
        );

        $this->aiInstruction = '';
    }

    /**
     * Polled (wire:poll) while an AI edit runs. When the job finishes, we reload
     * the form (the job already saved the AI's changes) and refresh the canvas.
     * On failure we show the error. This is how the builder updates itself once
     * the background AI edit completes.
     */
    public function checkAiStatus(): void
    {
        if (!$this->aiTrackingId) return;

        $status = \Illuminate\Support\Facades\Cache::get("ai_gen:{$this->aiTrackingId}");

        if (($status['status'] ?? null) === 'completed') {
            $this->form->refresh();
            $this->schema = $this->form->schema;
            $this->syncJsonFromSchema();
            $this->aiTrackingId = null;
            $this->dispatch('ai-edit-applied');
        } elseif (($status['status'] ?? null) === 'failed') {
            $this->jsonErrors = ['AI edit failed: ' . ($status['error'] ?? 'unknown error')];
            $this->aiTrackingId = null;
        }
    }

    /**
     * Save the form. Runs the schema through the validator first — if it's
     * invalid, we show the errors and DON'T save. On success, Form::saveSchema()
     * persists it AND snapshots a version (source: manual), so every save is
     * automatically part of the form's version history.
     */
    public function save(): void
    {
        $errors = app(FormSchemaValidator::class)->validateSchema($this->schema);

        if (!empty($errors)) {
            $this->jsonErrors = $errors;
            $this->dispatch('save-failed');
            return;
        }

        $this->form->saveSchema($this->schema, source: 'manual', userId: auth()->id());
        $this->dispatch('saved');
    }

    // --- Small private helpers ---

    /** Find a section by id (read-only lookup). */
    private function findSection(string $sectionId): ?array
    {
        foreach ($this->schema['sections'] as $section) {
            if ($section['id'] === $sectionId) return $section;
        }
        return null;
    }

    /**
     * Run a callback against one section BY REFERENCE, so the callback can
     * mutate it in place. Every field/section change routes through here, which
     * keeps the "find the right section and modify it" logic in one place.
     */
    private function mutateSection(string $sectionId, callable $callback): void
    {
        foreach ($this->schema['sections'] as &$section) {
            if ($section['id'] === $sectionId) {
                $callback($section);
                break;
            }
        }
    }

    /** Re-serialise $schema into the JSON editor text, keeping the two in sync. */
    private function syncJsonFromSchema(): void
    {
        $this->jsonEditorText = json_encode($this->schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->jsonErrors = [];
    }

    public function render()
    {
        return view('livewire.form-builder');
    }

    /** Set which section the Add-field palette targets (clicking a section selects it). */
    public function setActiveSection(string $sectionId): void
    {
        $this->activeSectionId = $sectionId;
    }
}
