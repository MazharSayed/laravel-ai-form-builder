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

class GenerateFormFromPrompt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $trackingId,
        public string $prompt,
        public ?int $existingFormId = null,
        public ?int $userId = null,
    ) {}

    public function handle(AiFormGeneratorService $generator): void
    {
        $this->setStatus('processing');

        try {
            if ($this->existingFormId) {
                $form = Form::findOrFail($this->existingFormId);
                $schema = $generator->edit($form, $this->prompt);
                $form->saveSchema($schema, source: 'ai_edit', note: $this->prompt, userId: $this->userId);
                $formId = $form->id;
            } else {
                $schema = $generator->generate($this->prompt);
                $form = Form::create([
                    'title' => Str::limit($this->prompt, 60),
                    'schema' => $schema,
                    'status' => 'draft',
                    'created_by' => $this->userId,
                ]);
                $form->saveSchema($schema, source: 'ai_generate', note: $this->prompt, userId: $this->userId);
                $formId = $form->id;
            }

            $this->setStatus('completed', ['form_id' => $formId]);
        } catch (\Throwable $e) {
            $this->setStatus('failed', ['error' => $e->getMessage()]);
        }
    }

    private function setStatus(string $status, array $extra = []): void
    {
        Cache::put("ai_gen:{$this->trackingId}", array_merge(['status' => $status], $extra), now()->addMinutes(30));
    }
}
