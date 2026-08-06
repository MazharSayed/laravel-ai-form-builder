<?php

namespace App\Livewire;

use App\Jobs\ProcessImport;
use App\Models\Form;
use App\Models\ImportJob;
use App\Services\FormSchemaValidator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class ImportForm extends Component
{
    use WithFileUploads; // enables Livewire's file-upload handling for $file

    // --- Component state (each drives what the view shows) ---
    public $file;                     // the uploaded .docx/.xlsx (temp file)
    public ?int $importJobId = null;  // the ImportJob we're tracking
    public array $schema = [];        // the detected schema, editable in the review UI
    public array $unparseable = [];   // parser notes / errors shown to the user
    public string $status = 'idle';   // drives the UI: idle → processing → ready → committed

    // The only field types allowed — used to validate the dropdown on the
    // review screen so a user can't set a field to an invalid type.
    private const FIELD_TYPES = [
        'text','textarea','number','email','phone','date','dropdown','radio','checkbox','file','section_heading','rating',
    ];

    public ?int $committedFormId = null; // set after commit, so the success view can link to the new form

    public function fieldTypes(): array
    {
        return self::FIELD_TYPES;
    }

    /**
     * Livewire lifecycle hook — fires automatically the moment a file finishes
     * uploading (rather than needing a separate "upload" button click, which
     * caused a race condition in Livewire 4).
     *
     * It validates the file type/size, stores it, creates an ImportJob record,
     * flips the UI to "processing", and dispatches the queued parser. Parsing
     * itself happens in the background (ProcessImport job) so a big document
     * doesn't freeze the page.
     */
    public function updatedFile(): void
    {
        $this->validate([
            'file' => 'required|file|mimes:docx,xlsx|max:' . (config('app.max_import_kb', 10240)),
        ]);

        $ext = strtolower($this->file->getClientOriginalExtension());
        $path = $this->file->store('imports', 'local');

        $job = ImportJob::create([
            'original_filename' => $this->file->getClientOriginalName(),
            'disk_path' => $path,
            'type' => $ext === 'docx' ? 'docx' : 'xlsx',
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);

        $this->importJobId = $job->id;
        $this->status = 'processing';

        ProcessImport::dispatch($job->id);
    }

    /**
     * Polled by the view (wire:poll) while parsing runs in the background.
     * When the job reports it's ready, we pull the detected schema + parser
     * notes into the component and switch to the review screen. If parsing
     * failed, we surface the error and reset to the upload state.
     */
    public function checkStatus(): void
    {
        if (!$this->importJobId) return;

        $job = ImportJob::find($this->importJobId);
        if (!$job) return;

        if ($job->status === 'ready_for_review') {
            $this->schema = $job->detected_schema ?? ['version' => 1, 'sections' => []];
            $this->unparseable = $job->unparseable_blocks ?? [];
            $this->status = 'ready';
        } elseif ($job->status === 'failed') {
            $this->unparseable = ['Import failed: ' . ($job->error ?? 'unknown error')];
            $this->status = 'idle';
        }
    }

    /**
     * Review-screen action: the user corrects a wrongly-detected field type via
     * a dropdown. We guard against invalid types, and if they switch a field to
     * a choice type (dropdown/radio/checkbox) that has no options yet, we seed a
     * placeholder option so the schema stays valid and doesn't fail on commit.
     */
    public function setFieldType(int $sectionIdx, int $fieldIdx, string $type): void
    {
        if (in_array($type, self::FIELD_TYPES, true)) {
            $this->schema['sections'][$sectionIdx]['fields'][$fieldIdx]['type'] = $type;
            if (in_array($type, ['dropdown','radio','checkbox'], true)
                && empty($this->schema['sections'][$sectionIdx]['fields'][$fieldIdx]['options'])) {
                $this->schema['sections'][$sectionIdx]['fields'][$fieldIdx]['options'] = [['value' => 'option_1', 'label' => 'Option 1']];
            }
        }
    }

    /**
     * Final step: turn the reviewed schema into a real form.
     *
     * Re-validates the (user-corrected) schema first — we never save a broken
     * one. Creates the form as a draft and records a version snapshot tagged
     * 'import'. On success it flips to the 'committed' state, which shows a
     * success card linking into the builder. Any failure is caught and shown
     * to the user rather than crashing.
     *
     * Note: this Livewire commit path is kept for in-component use; the primary
     * commit flow uses a plain POST controller, which proved more reliable for
     * the redirect across Livewire 4's re-render behaviour.
     */
    public function commit()
    {
        $errors = app(FormSchemaValidator::class)->validateSchema($this->schema);
        if (!empty($errors)) {
            $this->unparseable = array_merge(['Cannot commit — fix these first:'], $errors);
            return;
        }

        try {
            $form = Form::create([
                'title' => 'Imported form ' . now()->format('M j, H:i'),
                'schema' => $this->schema,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);
            $form->saveSchema($this->schema, source: 'import', userId: auth()->id());

            if ($this->importJobId) {
                ImportJob::where('id', $this->importJobId)->update(['status' => 'committed', 'form_id' => $form->id]);
            }
        } catch (\Throwable $e) {
            $this->unparseable = ['Commit failed: ' . $e->getMessage()];
            return;
        }

        $this->committedFormId = $form->id;
        $this->status = 'committed';
    }

    public function render()
    {
        return view('livewire.import-form');
    }
}
