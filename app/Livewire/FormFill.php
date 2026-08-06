<?php

namespace App\Livewire;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Services\FormSchemaValidator;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
/**
 * Part A — the public form-fill page (Livewire, no login).
 *
 * This is the page an end-user actually fills out. It renders whatever the
 * form's schema defines, applies conditional show/hide logic live, and — most
 * importantly — validates EVERYTHING server-side on submit. Whatever the
 * browser did is only UX; this component is the real security boundary and
 * never trusts client input.
 */
class FormFill extends Component
{
    use WithFileUploads; // needed for file-type fields

    public Form $form;
    public array $values = [];      // the user's answers, keyed by field key
    public array $errors2 = [];     // validation errors (named errors2 to avoid clashing with Livewire's own $errors)
    public bool $submitted = false; // flips to true to show the "thank you" screen

    /**
     * Resolve the form by its public_key — NOT its numeric id. The key is a
     * random, unguessable string, so form URLs can't be enumerated (you can't
     * just visit /f/1, /f/2 to browse everyone's forms). We also require the
     * form to be 'published' — draft forms are 404, not fillable.
     *
     * Then we pre-seed the answers array with each field's default (empty array
     * for checkboxes since they hold multiple values, null otherwise).
     */
    public function mount(string $publicKey): void
    {
        $this->form = Form::where('public_key', $publicKey)
            ->where('status', 'published')
            ->firstOrFail();

        foreach ($this->form->flattenedFields() as $field) {
            $this->values[$field['key']] = $field['default'] ?? ($field['type'] === 'checkbox' ? [] : null);
        }
    }

    /**
     * Conditional logic (Part D): decides whether a field should be shown based
     * on another field's answer (the field's "visible_if" rule). This runs live
     * as the user types, so fields appear/disappear dynamically. The SAME logic
     * is mirrored server-side in the validator, so a hidden field is never
     * required — the two stay consistent.
     */
    public function isFieldVisible(array $field): bool
    {
        $cond = $field['visible_if'] ?? null;
        if (!$cond) return true;

        $actual = $this->values[$cond['field']] ?? null;

        return match ($cond['op']) {
            'equals'     => $actual == $cond['value'],
            'not_equals' => $actual != $cond['value'],
            'contains'   => is_array($actual) && in_array($cond['value'], $actual),
            'is_filled'  => !empty($actual),
            default      => true,
        };
    }

    public function submit(): void
    {
        // Re-fetch the form fresh in case its schema changed since the page
        // loaded — we validate against the CURRENT schema, never a stale copy.
        $this->form->refresh();

        // THE security boundary: rebuild Laravel validation rules from the
        // form's own schema and validate the submission server-side. If it
        // fails, we show the errors and stop — nothing is saved.
        try {
            $validated = app(FormSchemaValidator::class)->validateSubmission($this->form->schema, $this->values);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->errors2 = $e->errors();
            return;
        }

        // IMPORTANT ordering: only AFTER validation passes do we store the
        // uploaded files. Earlier this ran before validation, which meant file
        // fields were converted to a stored path string before the 'file'
        // validation rule ran — and a string isn't a file, so every upload
        // failed validation. Validating first, storing second, fixes that.
        // The storage disk is config-driven: local in dev, Supabase (S3) in prod.
        foreach ($this->form->flattenedFields() as $field) {
            if ($field['type'] === 'file' && !empty($validated[$field['key']]) && is_object($validated[$field['key']])) {
                $validated[$field['key']] = $validated[$field['key']]->store('submissions/' . $this->form->id, config('app.upload_disk', 'local'));
            }
        }

        // Save the submission. We record the schema VERSION it was submitted
        // against, so an old submission can always be re-rendered correctly even
        // if the form's schema changes later. Meta captures IP + user agent.
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
