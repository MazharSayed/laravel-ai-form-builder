<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The schema JSON is the single source of truth (brief §3). This class has
 * two jobs that must never be skipped:
 *
 *   1. validateSchema()      — is the schema itself well-formed? Run before
 *                               ANY schema save (manual edit, AI generate/edit,
 *                               import commit). A broken schema is never persisted.
 *   2. rulesForSubmission()  — derive Laravel validation rules from the schema
 *                               so a public form submission is validated
 *                               server-side, never trusting the browser.
 */
class FormSchemaValidator
{
    private const VALID_TYPES = [
        'text', 'textarea', 'number', 'email', 'phone', 'date', 'dropdown',
        'radio', 'checkbox', 'file', 'section_heading', 'rating',
    ];

    private const TYPES_REQUIRING_OPTIONS = ['dropdown', 'radio', 'checkbox'];

    /** @return array<string> list of human-readable errors; empty = valid. */
    public function validateSchema(array $schema): array
    {
        $errors = [];

        if (!isset($schema['sections']) || !is_array($schema['sections']) || count($schema['sections']) === 0) {
            return ['Schema must contain at least one section.'];
        }

        $seenKeys = [];

        foreach ($schema['sections'] as $sIndex => $section) {
            if (empty($section['id']) || empty($section['title'])) {
                $errors[] = "Section #{$sIndex} is missing an id or title.";
            }

            foreach (($section['fields'] ?? []) as $fIndex => $field) {
                $where = "Section #{$sIndex}, field #{$fIndex}";

                if (empty($field['id'])) {
                    $errors[] = "$where: missing id.";
                }

                $type = $field['type'] ?? null;
                if (!in_array($type, self::VALID_TYPES, true)) {
                    $errors[] = "$where: invalid or hallucinated field type '{$type}'.";
                    continue;
                }

                if ($type === 'section_heading') {
                    continue;
                }

                $key = $field['key'] ?? null;
                if (empty($key) || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                    $errors[] = "$where: key must be snake_case and non-empty, got '{$key}'.";
                } elseif (isset($seenKeys[$key])) {
                    $errors[] = "$where: duplicate key '{$key}' (already used in section #{$seenKeys[$key]}).";
                } else {
                    $seenKeys[$key] = $sIndex;
                }

                if (empty($field['label'])) {
                    $errors[] = "$where: missing label.";
                }

                if (in_array($type, self::TYPES_REQUIRING_OPTIONS, true) && empty($field['options'])) {
                    $errors[] = "$where: type '{$type}' requires at least one option.";
                }

                if (!empty($field['visible_if'])) {
                    $cond = $field['visible_if'];
                    if (empty($cond['field']) || empty($cond['op'])) {
                        $errors[] = "$where: visible_if condition is malformed.";
                    }
                }
            }
        }

        return $errors;
    }

    /** @return array{rules: array, prunedInput: array} */
    public function rulesForSubmission(array $schema, array $input): array
    {
        $rules = [];
        $prunedInput = $input;

        foreach ($this->flatten($schema) as $field) {
            $key = $field['key'];
            $visible = $this->isVisible($field, $input);

            if (!$visible) {
                unset($prunedInput[$key]);
                continue;
            }

            $fieldRules = [];
            $fieldRules[] = ($field['required'] ?? false) ? 'required' : 'nullable';

            $v = $field['validation'] ?? [];

            switch ($field['type']) {
                case 'email':
                    $fieldRules[] = 'email:rfc';
                    break;
                case 'number':
                case 'rating':
                    $fieldRules[] = 'numeric';
                    if (isset($v['min'])) $fieldRules[] = 'min:' . $v['min'];
                    if (isset($v['max'])) $fieldRules[] = 'max:' . $v['max'];
                    break;
                case 'date':
                    $fieldRules[] = 'date';
                    break;
                case 'phone':
                    $fieldRules[] = 'regex:/^[0-9+\-\s()]{6,20}$/';
                    break;
                case 'file':
                    $fieldRules[] = 'file';
                    if (!empty($v['allowed_types'])) {
                        $fieldRules[] = 'mimes:' . implode(',', $v['allowed_types']);
                    }
                    if (!empty($v['max_size_kb'])) {
                        $fieldRules[] = 'max:' . $v['max_size_kb'];
                    }
                    break;
                case 'dropdown':
                case 'radio':
                    $allowed = array_column($field['options'] ?? [], 'value');
                    $fieldRules[] = Rule::in($allowed);
                    break;
                case 'checkbox':
                    $fieldRules[] = 'array';
                    break;
                case 'text':
                case 'textarea':
                default:
                    $fieldRules[] = 'string';
                    if (isset($v['min_length'])) $fieldRules[] = 'min:' . $v['min_length'];
                    if (isset($v['max_length'])) $fieldRules[] = 'max:' . $v['max_length'];
                    if (!empty($v['regex'])) $fieldRules[] = 'regex:' . $v['regex'];
                    break;
            }

            $rules[$key] = $fieldRules;
        }

        return ['rules' => $rules, 'prunedInput' => $prunedInput];
    }

    public function validateSubmission(array $schema, array $input): array
    {
        ['rules' => $rules, 'prunedInput' => $prunedInput] = $this->rulesForSubmission($schema, $input);

        $validator = Validator::make($prunedInput, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function flatten(array $schema): array
    {
        $out = [];
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (($field['type'] ?? null) !== 'section_heading') {
                    $out[] = $field;
                }
            }
        }
        return $out;
    }

    private function isVisible(array $field, array $input): bool
    {
        $cond = $field['visible_if'] ?? null;
        if (!$cond) {
            return true;
        }

        $actual = $input[$cond['field']] ?? null;

        return match ($cond['op']) {
            'equals' => $actual == $cond['value'],
            'not_equals' => $actual != $cond['value'],
            'contains' => is_array($actual) && in_array($cond['value'], $actual),
            'is_filled' => !empty($actual),
            default => true,
        };
    }
}
