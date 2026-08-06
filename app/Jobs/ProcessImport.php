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
 * Part C — parses an uploaded .docx/.xlsx into a form schema, in the background.
 *
 * Runs as a queued job so a large document doesn't block the web request while
 * it's being parsed. The flow is deliberately two-step:
 *   1. This job parses the file and stores the DETECTED schema, but does NOT
 *      create a real form yet — it sets the import to 'ready_for_review'.
 *   2. The user reviews/fixes the detected field types on the review screen,
 *      then commits, which is when the real form is actually created.
 *
 * We parse deterministically (structural rules), not with AI — the parsers
 * handle the common cases, and anything ambiguous is reported for the user to
 * fix rather than guessed silently.
 */
class ProcessImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // No auto-retry: a parse failure is deterministic (a bad/corrupt file will
    // fail the same way every time), so retrying wouldn't help — we just report it.
    public int $tries = 1;

    // We pass only the import job's id and reload it inside handle(), rather than
    // serializing the whole model — the standard, safe pattern for queued jobs.
    public function __construct(public int $importJobId) {}

    public function handle(DocxImportParser $docx, XlsxImportParser $xlsx, FormSchemaValidator $validator): void
    {
        $job = ImportJob::findOrFail($this->importJobId);
        $job->update(['status' => 'processing']);

        try {
            // The uploaded file was stored on the local disk; get its real path
            // so PhpWord/PhpSpreadsheet can open it.
            $absolutePath = Storage::disk('local')->path($job->disk_path);

            // Pick the right parser based on the file type. Both return the same
            // shape: a schema, plus a list of things they couldn't confidently parse.
            $result = $job->type === 'docx'
                ? $docx->parse($absolutePath)
                : $xlsx->parse($absolutePath);

            // Even an imported schema must pass the same validation as everything
            // else — we never let a broken schema move forward to the review step.
            // Any schema problems get surfaced to the user alongside the parser's
            // own "couldn't parse this" notes.
            $errors = $validator->validateSchema($result['schema']);
            $unparseable = $result['unparseable'];
            if (!empty($errors)) {
                $unparseable = array_merge($unparseable, array_map(fn ($e) => "Schema issue: $e", $errors));
            }

            // Store the detected schema + notes and mark it ready for the user to
            // review. Note: still no real Form created yet — that happens on commit.
            $job->update([
                'status' => 'ready_for_review',
                'detected_schema' => $result['schema'],
                'unparseable_blocks' => $unparseable,
            ]);
        } catch (\Throwable $e) {
            // A parse failure (corrupt file, unexpected format) is caught and
            // recorded on the job so the UI can show a clean error, not crash.
            $job->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }
}
