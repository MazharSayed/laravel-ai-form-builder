<?php

namespace App\Jobs;

use App\Models\Form;
use App\Services\AiFormGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Queued job that runs AI form generation (or AI editing) in the background.
 *
 * Why a job and not just inline code? A Gemini call takes several seconds. We
 * don't want the user's web request hanging that whole time, so we push the
 * work here. The controller dispatches this job, immediately hands the user a
 * tracking id, and the frontend polls for progress. (On the live free tier the
 * queue runs 'sync' so it executes inline, but writing it as a job means it
 * scales cleanly the moment a real queue worker is added.)
 *
 * One job handles BOTH cases:
 *   - existingFormId is null  → generate a brand-new form from the prompt
 *   - existingFormId is set   → edit that existing form with the prompt
 */
class GenerateFormFromPrompt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Don't let Laravel auto-retry on failure. The AI service already does its
    // own validate-and-repair retries internally, so a queue-level retry would
    // just duplicate work and waste API calls. One attempt here is enough.
    public int $tries = 1;

    /**
     * Everything the job needs is passed in and serialized with it:
     *  - trackingId: the cache key the frontend polls for status
     *  - prompt: what the user typed
     *  - existingFormId: set only when editing an existing form
     *  - userId: who triggered it (for versioning attribution)
     */
    public function __construct(
        public string $trackingId,
        public string $prompt,
        public ?int $existingFormId = null,
        public ?int $userId = null,
    ) {}

    public function handle(AiFormGeneratorService $generator): void
    {
        // Mark it as running so the polling frontend shows a "processing" state.
        $this->setStatus('processing');

        try {
            if ($this->existingFormId) {
                // --- EDIT an existing form ---
                $form = Form::findOrFail($this->existingFormId);
                $schema = $generator->edit($form, $this->prompt);
                // saveSchema records a version snapshot tagged 'ai_edit', with the
                // prompt stored as the change note — so the version history doubles
                // as an audit trail of what the AI changed and why.
                $form->saveSchema($schema, source: 'ai_edit', note: $this->prompt, userId: $this->userId);
                $formId = $form->id;
            } else {
                // --- GENERATE a new form ---
                $schema = $generator->generate($this->prompt);
                $form = Form::create([
                    // Use a trimmed version of the prompt as the working title.
                    'title' => Str::limit($this->prompt, 60),
                    'schema' => $schema,
                    'status' => 'draft',
                    'created_by' => $this->userId,
                ]);
                $form->saveSchema($schema, source: 'ai_generate', note: $this->prompt, userId: $this->userId);
                $formId = $form->id;
            }

            // Success — hand the new/edited form's id back to the frontend.
            $this->setStatus('completed', ['form_id' => $formId]);
        } catch (\Throwable $e) {
            // Any failure (AI unreachable, never-valid schema, rate limit) is
            // caught and reported via the status channel rather than crashing
            // the queue. The frontend shows the error message to the user.
            $this->setStatus('failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Writes the job's current status to the cache under its tracking id.
     * The frontend polls the matching status endpoint to read this back.
     * A 30-minute TTL is plenty — the frontend only polls for a few seconds.
     */
    private function setStatus(string $status, array $extra = []): void
    {
        Cache::put("ai_gen:{$this->trackingId}", array_merge(['status' => $status], $extra), now()->addMinutes(30));
    }
}
