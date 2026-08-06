<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\DocxImportParser;
use App\Services\XlsxImportParser;
use App\Services\FormSchemaValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Parses an uploaded .docx/.xlsx off the request cycle (brief: "queue large
 * files"). Deterministic parse first; the result lands in ready_for_review so
 * the user can fix wrongly-detected types before committing to a real form.
 */
class ProcessImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $importJobId) {}

    public function handle(DocxImportParser $docx, XlsxImportParser $xlsx, FormSchemaValidator $validator): void
    {
        $job = ImportJob::findOrFail($this->importJobId);
        $job->update(['status' => 'processing']);

        try {
            $absolutePath = Storage::disk('local')->path($job->disk_path);

            $result = $job->type === 'docx'
                ? $docx->parse($absolutePath)
                : $xlsx->parse($absolutePath);

            // Guarantee we never move a broken schema forward.
            $errors = $validator->validateSchema($result['schema']);
            $unparseable = $result['unparseable'];
            if (!empty($errors)) {
                $unparseable = array_merge($unparseable, array_map(fn ($e) => "Schema issue: $e", $errors));
            }

            $job->update([
                'status' => 'ready_for_review',
                'detected_schema' => $result['schema'],
                'unparseable_blocks' => $unparseable,
            ]);
        } catch (\Throwable $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }
}
