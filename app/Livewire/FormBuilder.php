<?php

namespace App\Livewire;

use App\Models\Form;
use App\Services\FormSchemaValidator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class FormBuilder extends Component
{
    public Form $form;

    public array $schema = [];
    public string $jsonEditorText = '';
    public array $jsonErrors = [];

    public ?string $activeFieldId = null;
    public string $aiInstruction = '';
    public ?string $aiTrackingId = null;

    public ?string $activeSectionId = null;

    public function mount(Form $form): void
    {
        $this->form = $form;
        $this->schema = $form->schema ?: ['version' => 1, 'sections' => []];
        $this->syncJsonFromSchema();
        $this->activeSectionId = $this->schema['sections'][0]['id'] ?? null;
    }

    public function addField(string $sectionId, string $type): void
    {
        $section = $this->findSection($sectionId);
        if (!$section) return;

        $newField = [
            'id' => 'fld_' . Str::random(8),
            'key' => 'field_' . strtolower(Str::random(6)),
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

        $this->activeFieldId = $newField['id'];
        $this->syncJsonFromSchema();
    }

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

    public function updateFieldProperty(string $sectionId, string $fieldId, string $property, $value): void
    {
        if ($fieldId === '') {
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

    public function requestAiEdit(): void
    {
        if (trim($this->aiInstruction) === '') return;

        $this->aiTrackingId = (string) Str::uuid();

        \App\Jobs\GenerateFormFromPrompt::dispatch(
            trackingId: $this->aiTrackingId,
            prompt: $this->aiInstruction,
            existingFormId: $this->form->id,
            userId: auth()->id(),
        );

        $this->aiInstruction = '';
    }

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

    private function findSection(string $sectionId): ?array
    {
        foreach ($this->schema['sections'] as $section) {
            if ($section['id'] === $sectionId) return $section;
        }
        return null;
    }

    private function mutateSection(string $sectionId, callable $callback): void
    {
        foreach ($this->schema['sections'] as &$section) {
            if ($section['id'] === $sectionId) {
                $callback($section);
                break;
            }
        }
    }

    private function syncJsonFromSchema(): void
    {
        $this->jsonEditorText = json_encode($this->schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->jsonErrors = [];
    }

    public function render()
    {
        return view('livewire.form-builder');
    }

    public function setActiveSection(string $sectionId): void
    {
        $this->activeSectionId = $sectionId;
    }
}
