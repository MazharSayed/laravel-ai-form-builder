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
    use WithFileUploads;

    public $file;
    public ?int $importJobId = null;
    public array $schema = [];        // editable preview
    public array $unparseable = [];
    public string $status = 'idle';   // idle | processing | ready | committed

    private const FIELD_TYPES = [
        'text','textarea','number','email','phone','date','dropdown','radio','checkbox','file','section_heading','rating',
    ];

    public ?int $committedFormId = null;

    public function fieldTypes(): array
    {
        return self::FIELD_TYPES;
    }

    // public function upload(): void
    // {
    //     $this->validate([
    //         'file' => 'required|file|mimes:docx,xlsx|max:' . (config('app.max_import_kb', 10240)),
    //     ]);

    //     $ext = strtolower($this->file->getClientOriginalExtension());
    //     $path = $this->file->store('imports', 'local');

    //     $job = ImportJob::create([
    //         'original_filename' => $this->file->getClientOriginalName(),
    //         'disk_path' => $path,
    //         'type' => $ext === 'docx' ? 'docx' : 'xlsx',
    //         'status' => 'pending',
    //         'created_by' => auth()->id(),
    //     ]);

    //     $this->importJobId = $job->id;
    //     $this->status = 'processing';

    //     ProcessImport::dispatch($job->id);
    // }

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

    /** Polled while parsing runs in the queue. */
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

    /** Review-screen edit: fix a wrongly-detected field type before committing. */
    public function setFieldType(int $sectionIdx, int $fieldIdx, string $type): void
    {
        if (in_array($type, self::FIELD_TYPES, true)) {
            $this->schema['sections'][$sectionIdx]['fields'][$fieldIdx]['type'] = $type;
            // If switching to a choice type with no options, seed one so it stays valid.
            if (in_array($type, ['dropdown','radio','checkbox'], true)
                && empty($this->schema['sections'][$sectionIdx]['fields'][$fieldIdx]['options'])) {
                $this->schema['sections'][$sectionIdx]['fields'][$fieldIdx]['options'] = [['value' => 'option_1', 'label' => 'Option 1']];
            }
        }
    }

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
