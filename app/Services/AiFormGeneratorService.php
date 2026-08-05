<?php

namespace App\Services;

use App\Models\AiGenerationLog;
use App\Models\Form;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AiFormGeneratorService
{
    public function __construct(private FormSchemaValidator $validator) {}

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a form-schema generator. You output ONLY a single JSON object matching
this exact contract (no markdown fences, no commentary, no trailing text):

{
  "version": 1,
  "sections": [
    {
      "id": "sec_<slug>",
      "title": "string",
      "description": "string|null",
      "fields": [
        {
          "id": "fld_<slug>",
          "key": "snake_case_unique_key",
          "type": "text|textarea|number|email|phone|date|dropdown|radio|checkbox|file|section_heading|rating",
          "label": "string",
          "placeholder": "string|null",
          "help_text": "string|null",
          "default": null,
          "required": true|false,
          "options": [{"value": "x", "label": "X"}],
          "validation": { "...type-specific rules..." },
          "visible_if": null
        }
      ]
    }
  ]
}

Rules:
- Use ONLY the listed field types. Never invent a type.
- Every non-section_heading field needs a unique snake_case "key".
- dropdown/radio/checkbox fields MUST include non-empty "options".
- Infer sensible validation (email format, phone pattern, file types/size,
  min/max for numbers, min/max length for text) from context.
- Return the JSON object and nothing else.
PROMPT;

    public function generate(string $prompt): array
    {
        return $this->runWithRepair(
            action: 'generate',
            formId: null,
            turns: [
                ['role' => 'user', 'text' => "Build a form for: {$prompt}"],
            ],
        );
    }

    public function edit(Form $form, string $instruction): array
    {
        return $this->runWithRepair(
            action: 'edit',
            formId: $form->id,
            turns: [
                ['role' => 'user', 'text' => "Here is the current form schema:\n" . json_encode($form->schema)
                    . "\n\nApply this change and return the FULL updated schema (not a diff): {$instruction}"],
            ],
        );
    }

    private function runWithRepair(string $action, ?int $formId, array $turns): array
    {
        $maxRetries = (int) config('services.ai.max_retries', 2);
        $model = config('services.ai.model', 'gemini-2.5-flash');
        $apiKey = config('services.ai.api_key');
        $timeout = (int) config('services.ai.timeout', 60);

        $lastErrors = [];

        for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
            $start = microtime(true);

            $log = AiGenerationLog::create([
                'form_id' => $formId,
                'action' => $action,
                'prompt' => end($turns)['text'],
                'model' => $model,
                'status' => 'queued',
                'attempt' => $attempt,
            ]);

            try {
                $response = Http::timeout($timeout)
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                        'systemInstruction' => [
                            'parts' => [['text' => self::SYSTEM_PROMPT]],
                        ],
                        'contents' => array_map(
                            fn ($t) => ['role' => $t['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $t['text']]]],
                            $turns
                        ),
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'temperature' => 0.3,
                        ],
                    ])
                    ->throw();

                $body = $response->json();
                $raw = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $usage = $body['usageMetadata'] ?? [];

                $schema = $this->safeJsonDecode($raw);

                $errors = $schema === null
                    ? ['Response was not valid JSON.']
                    : $this->validator->validateSchema($schema);

                $log->update([
                    'status' => empty($errors) ? 'succeeded' : 'retried',
                    'prompt_tokens' => $usage['promptTokenCount'] ?? null,
                    'completion_tokens' => $usage['candidatesTokenCount'] ?? null,
                    'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                    'error' => empty($errors) ? null : implode(' | ', $errors),
                ]);

                if (empty($errors)) {
                    return $schema;
                }

                $lastErrors = $errors;
                $turns[] = ['role' => 'assistant', 'text' => $raw];
                $turns[] = ['role' => 'user', 'text' =>
                    'That JSON had these problems: ' . implode('; ', $errors)
                    . '. Return corrected JSON only, following the contract exactly.'];
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $status = $e->response?->status();
                $message = $status === 429
                    ? 'Gemini free-tier rate limit hit (429) — see README limitations.'
                    : $e->getMessage();

                Log::warning('AI form generation attempt failed', ['error' => $message, 'attempt' => $attempt]);
                $log->update([
                    'status' => 'failed',
                    'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                    'error' => $message,
                ]);
                $lastErrors = [$message];
            } catch (\Throwable $e) {
                Log::warning('AI form generation attempt failed', ['error' => $e->getMessage(), 'attempt' => $attempt]);
                $log->update([
                    'status' => 'failed',
                    'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                    'error' => $e->getMessage(),
                ]);
                $lastErrors = [$e->getMessage()];
            }
        }

        throw new RuntimeException(
            'AI form generation failed after ' . ($maxRetries + 1) . " attempts: " . implode('; ', $lastErrors)
        );
    }

    private function safeJsonDecode(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```json\s*|\s*```$/m', '', $raw);

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }
}
