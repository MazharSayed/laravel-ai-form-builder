<?php

namespace App\Http\Controllers;

use App\Models\Form;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\FormSubmission;
use Illuminate\Support\Facades\Storage;

class SubmissionController extends Controller
{
    public function exportCsv(Form $form): StreamedResponse
    {
        $fieldKeys = collect($form->flattenedFields())->pluck('key')->all();
        $filename = 'submissions-' . $form->public_key . '.csv';

        $callback = function () use ($form, $fieldKeys) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['submitted_at'], $fieldKeys));

            $form->submissions()->latest()->chunk(500, function ($chunk) use ($out, $fieldKeys) {
                foreach ($chunk as $submission) {
                    $row = [$submission->created_at->toDateTimeString()];
                    foreach ($fieldKeys as $key) {
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

    public function downloadFile(Form $form, FormSubmission $submission, string $fieldKey)
    {
        abort_unless($submission->form_id === $form->id, 404);

        $disk = config('app.upload_disk', 'local');
        $path = $submission->data[$fieldKey] ?? null;
        abort_unless($path && Storage::disk($disk)->exists($path), 404);

        return \Storage::disk($disk)->download($path);
    }
}
