<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Str;

/**
 * Deterministic Excel parser (brief Part C). Supports TWO documented layouts:
 *
 *  LAYOUT A — "structured" (preferred): columns are
 *      label | type | required | options   (options pipe-separated: "Yes|No")
 *    One row per field. Type column is honored if valid, else guessed.
 *
 *  LAYOUT B — "plain header row": the first row is column headers, and each
 *    header simply becomes a text-ish field (type guessed from the header).
 *    This is the fallback when the sheet doesn't have a 'type' column.
 *
 * The parser auto-detects which layout it's looking at by checking whether the
 * header row contains a 'type' column.
 */
class XlsxImportParser
{
    private const VALID_TYPES = [
        'text', 'textarea', 'number', 'email', 'phone', 'date', 'dropdown',
        'radio', 'checkbox', 'file', 'section_heading', 'rating',
    ];

    /** @return array{schema: array, unparseable: array} */
    public function parse(string $absolutePath): array
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false); // 0-indexed rows/cols

        $unparseable = [];

        if (empty($rows) || empty($rows[0])) {
            return ['schema' => ['version' => 1, 'sections' => []], 'unparseable' => ['Sheet is empty.']];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[0]);
        $isStructured = in_array('type', $header, true) && in_array('label', $header, true);

        $fields = $isStructured
            ? $this->parseStructured($rows, $header, $unparseable)
            : $this->parsePlainHeaderRow($header, $unparseable);

        return [
            'schema' => [
                'version' => 1,
                'sections' => [[
                    'id' => 'sec_' . Str::random(8),
                    'title' => 'Imported from Excel',
                    'description' => null,
                    'fields' => $fields,
                ]],
            ],
            'unparseable' => $unparseable,
        ];
    }

    private function parseStructured(array $rows, array $header, array &$unparseable): array
    {
        $col = array_flip($header); // 'label' => index, 'type' => index, ...
        $fields = [];

        foreach (array_slice($rows, 1) as $i => $row) {
            $label = trim((string) ($row[$col['label']] ?? ''));
            if ($label === '') {
                continue;
            }

            $rawType = strtolower(trim((string) ($row[$col['type']] ?? '')));
            $type = in_array($rawType, self::VALID_TYPES, true) ? $rawType : $this->guessType($label);
            if ($rawType !== '' && !in_array($rawType, self::VALID_TYPES, true)) {
                $unparseable[] = "Row " . ($i + 2) . ": unknown type '{$rawType}', defaulted to '{$type}'.";
            }

            $required = false;
            if (isset($col['required'])) {
                $r = strtolower(trim((string) ($row[$col['required']] ?? '')));
                $required = in_array($r, ['1', 'yes', 'true', 'y'], true);
            }

            $options = [];
            if (isset($col['options']) && !empty($row[$col['options']])) {
                foreach (explode('|', (string) $row[$col['options']]) as $opt) {
                    $opt = trim($opt);
                    if ($opt !== '') {
                        $options[] = ['value' => Str::slug($opt, '_') ?: 'opt', 'label' => $opt];
                    }
                }
            }

            // A field declared as a choice type but with no options can't be valid — flag it.
            if (in_array($type, ['dropdown', 'radio', 'checkbox'], true) && empty($options)) {
                $options[] = ['value' => 'option_1', 'label' => 'Option 1'];
                $unparseable[] = "Row " . ($i + 2) . ": '{$label}' is a {$type} but had no options; added a placeholder.";
            }

            $fields[] = [
                'id' => 'fld_' . Str::random(8),
                'key' => $this->makeKey($label),
                'type' => $type,
                'label' => $label,
                'placeholder' => null,
                'help_text' => null,
                'default' => null,
                'required' => $required,
                'options' => $options,
                'validation' => [],
                'visible_if' => null,
            ];
        }

        return $fields;
    }

    private function parsePlainHeaderRow(array $header, array &$unparseable): array
    {
        $fields = [];
        foreach ($header as $h) {
            $label = trim((string) $h);
            if ($label === '') {
                continue;
            }
            $fields[] = [
                'id' => 'fld_' . Str::random(8),
                'key' => $this->makeKey($label),
                'type' => $this->guessType($label),
                'label' => ucwords($label),
                'placeholder' => null,
                'help_text' => null,
                'default' => null,
                'required' => false,
                'options' => [],
                'validation' => [],
                'visible_if' => null,
            ];
        }
        if (empty($fields)) {
            $unparseable[] = 'No usable header columns found.';
        }
        return $fields;
    }

    private function makeKey(string $label): string
    {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(Str::slug($label, '_')));
        return $key !== '' ? Str::limit($key, 40, '') : 'field_' . strtolower(Str::random(6));
    }

    private function guessType(string $label): string
    {
        $l = strtolower($label);
        return match (true) {
            str_contains($l, 'email') => 'email',
            str_contains($l, 'phone') || str_contains($l, 'mobile') => 'phone',
            str_contains($l, 'date') || str_contains($l, 'dob') || str_contains($l, 'birth') => 'date',
            str_contains($l, 'upload') || str_contains($l, 'resume') || str_contains($l, 'cv') || str_contains($l, 'attach') => 'file',
            str_contains($l, 'address') || str_contains($l, 'comment') || str_contains($l, 'message') => 'textarea',
            str_contains($l, 'age') || str_contains($l, 'year') || str_contains($l, 'number') => 'number',
            default => 'text',
        };
    }
}
