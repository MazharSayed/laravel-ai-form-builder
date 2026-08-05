<?php

namespace App\Livewire;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Services\FormSchemaValidator;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class FormFill extends Component
{
    use WithFileUploads;

    public Form $form;
    public array $values = [];
    public array $errors2 = [];
    public bool $submitted = false;

    public function mount(string $publicKey): void
    {
        $this->form = Form::where('public_key', $publicKey)
            ->where('status', 'published')
            ->firstOrFail();

        foreach ($this->form->flattenedFields() as $field) {
            $this->values[$field['key']] = $field['default'] ?? ($field['type'] === 'checkbox' ? [] : null);
        }
    }

    public function isFieldVisible(array $field): bool
    {
        $cond = $field['visible_if'] ?? null;
        if (!$cond) return true;

        $actual = $this->values[$cond['field']] ?? null;

        return match ($cond['op']) {
            'equals' => $actual == $cond['value'],
            'not_equals' => $actual != $cond['value'],
            'contains' => is_array($actual) && in_array($cond['value'], $actual),
            'is_filled' => !empty($actual),
            default => true,
        };
    }

    public function submit(): void
    {
        $this->form->refresh();
        
        try {
            $validated = app(FormSchemaValidator::class)->validateSubmission($this->form->schema, $this->values);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->errors2 = $e->errors();
            return;
        }

        // Only after validation passes, swap file objects for their stored paths.
        foreach ($this->form->flattenedFields() as $field) {
            if ($field['type'] === 'file' && !empty($validated[$field['key']]) && is_object($validated[$field['key']])) {
                $validated[$field['key']] = $validated[$field['key']]->store('submissions/' . $this->form->id, 'local');
            }
        }

        FormSubmission::create([
            'form_id' => $this->form->id,
            'form_schema_version' => $this->form->schema_version,
            'data' => $validated,
            'meta' => [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
        ]);

        $this->submitted = true;
        $this->errors2 = [];
    }

    public function render()
    {
        return view('livewire.form-fill');
    }
}
