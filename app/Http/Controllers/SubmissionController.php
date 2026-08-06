<?php

namespace App\Http\Controllers;

use App\Models\Form;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\FormSubmission;
use Illuminate\Support\Facades\Storage;

/**
 * Handles reading form submissions back out: exporting them all as a CSV,
 * and securely serving any files that were uploaded through a form.
 */
class SubmissionController extends Controller
{
    /**
     * Export all of a form's submissions as a downloadable CSV.
     *
     * Two things worth noting here:
     *
     * 1. Columns are built dynamically from the FORM'S OWN schema
     *    (flattenedFields → keys), so the CSV always matches whatever fields
     *    that specific form has — no hardcoded columns.
     *
     * 2. It STREAMS the file instead of building it in memory. We chunk the
     *    submissions 500 at a time and write each row straight to the output
     *    stream. This means a form with tens of thousands of submissions won't
     *    blow up the server's memory — we never hold them all at once.
     */
    public function exportCsv(Form $form): StreamedResponse
    {
        // Column headers = every field key in this form's schema.
        $fieldKeys = collect($form->flattenedFields())->pluck('key')->all();
        $filename = 'submissions-' . $form->public_key . '.csv';

        // This closure runs AS the file downloads, writing rows on the fly.
        $callback = function () use ($form, $fieldKeys) {
            $out = fopen('php://output', 'w');
            // Header row: submitted_at + one column per field.
            fputcsv($out, array_merge(['submitted_at'], $fieldKeys));

            // Pull submissions in batches of 500 to keep memory flat.
            $form->submissions()->latest()->chunk(500, function ($chunk) use ($out, $fieldKeys) {
                foreach ($chunk as $submission) {
                    $row = [$submission->created_at->toDateTimeString()];
                    foreach ($fieldKeys as $key) {
                        // Submission data is JSON keyed by field key. A missing
                        // answer becomes ''. Multi-value answers (checkboxes are
                        // arrays) get flattened to a comma-joined string.
                        $value = $submission->data[$key] ?? '';
                        $row[] = is_array($value) ? implode(',', $value) : $value;
                    }
                    fputcsv($out, $row);
                }
            });

            fclose($out);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Securely serve a file that was uploaded through a form submission
     * (e.g. a resume). This is deliberately NOT a public URL to the storage
     * folder — files are served through this authorised route instead.
     *
     * Security checks:
     *  - The submission must actually belong to the form in the URL, so nobody
     *    can fetch another form's files by guessing IDs (the abort_unless).
     *  - The file must actually exist on the configured disk before we try to
     *    serve it, otherwise we 404 cleanly.
     *
     * The disk is configurable (config('app.upload_disk')): 'local' in
     * development, 'supabase' (S3-compatible) in production — so the same code
     * works whether files live on the server or in cloud storage.
     */
    public function downloadFile(Form $form, FormSubmission $submission, string $fieldKey)
    {
        // Guard: this submission must belong to this form (prevents ID-guessing).
        abort_unless($submission->form_id === $form->id, 404);

        $disk = config('app.upload_disk', 'local');
        $path = $submission->data[$fieldKey] ?? null;

        // Only serve it if the path exists and the file is actually there.
        abort_unless($path && \Storage::disk($disk)->exists($path), 404);

        return \Storage::disk($disk)->download($path);
    }
}
