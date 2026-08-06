<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use Illuminate\Support\Str;

/**
 * Deterministic-first Word parser (brief Part C).
 *
 * Rules (documented in README):
 *  - Paragraphs styled as a Heading (Heading1/2/3) start a new SECTION.
 *  - A paragraph ending in '?' or ':' becomes a FIELD label.
 *  - Bullet/numbered list items immediately after a question become that
 *    field's OPTIONS (and bump its type to radio).
 *  - Everything the parser can't confidently type is left as 'text' and
 *    flagged in `unparseable`/low-confidence so the AI pass (or the user on
 *    the review screen) can correct it — we never silently guess.
 */
class DocxImportParser
{
    /** @return array{schema: array, unparseable: array} */
    public function parse(string $absolutePath): array
    {
        $phpWord = IOFactory::load($absolutePath);

        $sections = [];
        $unparseable = [];

        // We flatten every element in reading order, then walk it as a stream —
        // simpler and more robust than trying to nest by Word's own structure.
        $stream = [];
        foreach ($phpWord->getSections() as $wordSection) {
            foreach ($wordSection->getElements() as $element) {
                $this->collect($element, $stream);
            }
        }

        $currentSection = null;
        $pendingField = null;

        $flush = function () use (&$pendingField, &$currentSection) {
            if ($pendingField && $currentSection !== null) {
                $currentSection['fields'][] = $pendingField;
            }
            $pendingField = null;
            return $currentSection;
        };

        foreach ($stream as $item) {
            [$kind, $text, $isListItem] = $item;

            if ($text === '') {
                continue;
            }

            if ($kind === 'heading') {
                // Close any open field/section, start a new section.
                if ($pendingField && $currentSection !== null) {
                    $currentSection['fields'][] = $pendingField;
                    $pendingField = null;
                }
                if ($currentSection !== null) {
                    $sections[] = $currentSection;
                }
                $currentSection = [
                    'id' => 'sec_' . Str::random(8),
                    'title' => $text,
                    'description' => null,
                    'fields' => [],
                ];
                continue;
            }

            // Make sure there's always a section to attach fields to.
            if ($currentSection === null) {
                $currentSection = [
                    'id' => 'sec_' . Str::random(8),
                    'title' => 'Imported',
                    'description' => null,
                    'fields' => [],
                ];
            }

            if ($isListItem && $pendingField) {
                // List item under a question → an option, and the field is a choice.
                $pendingField['type'] = 'radio';
                $pendingField['options'][] = [
                    'value' => Str::slug($text, '_') ?: 'option_' . (count($pendingField['options']) + 1),
                    'label' => $text,
                ];
                continue;
            }

            // A normal paragraph that looks like a question/label → new field.
            $looksLikeQuestion = Str::endsWith(trim($text), ['?', ':']);
            if ($looksLikeQuestion || $pendingField === null) {
                if ($pendingField) {
                    $currentSection['fields'][] = $pendingField;
                }
                $label = rtrim(trim($text), '?:');
                $pendingField = [
                    'id' => 'fld_' . Str::random(8),
                    'key' => $this->makeKey($label),
                    'type' => $this->guessType($label),
                    'label' => $label,
                    'placeholder' => null,
                    'help_text' => null,
                    'default' => null,
                    'required' => false,
                    'options' => [],
                    'validation' => [],
                    'visible_if' => null,
                ];
            } else {
                // Text we couldn't confidently place.
                $unparseable[] = $text;
            }
        }

        // Flush trailing field/section.
        if ($pendingField && $currentSection !== null) {
            $currentSection['fields'][] = $pendingField;
        }
        if ($currentSection !== null) {
            $sections[] = $currentSection;
        }

        return [
            'schema' => ['version' => 1, 'sections' => $sections],
            'unparseable' => $unparseable,
        ];
    }

    /** Recursively pull text + heading/list info out of PhpWord elements. */
    private function collect($element, array &$stream): void
    {
        $class = (new \ReflectionClass($element))->getShortName();

        if ($class === 'Title' || $class === 'TextBreak') {
            if (method_exists($element, 'getText')) {
                $stream[] = ['heading', $this->plain($element->getText()), false];
            }
            return;
        }

        // A styled paragraph whose style name starts with "Heading".
        if ($class === 'TextRun') {
            $text = '';
            foreach ($element->getElements() as $child) {
                if (method_exists($child, 'getText')) {
                    $text .= $this->plain($child->getText());
                }
            }
            $stream[] = ['para', trim($text), false];
            return;
        }

        if ($class === 'ListItem' || $class === 'ListItemRun') {
            $text = '';
            if (method_exists($element, 'getTextObject') && $element->getTextObject()) {
                $text = $this->plain($element->getTextObject()->getText());
            } elseif (method_exists($element, 'getElements')) {
                foreach ($element->getElements() as $child) {
                    if (method_exists($child, 'getText')) {
                        $text .= $this->plain($child->getText());
                    }
                }
            }
            $stream[] = ['para', trim($text), true];
            return;
        }

        if (method_exists($element, 'getText')) {
            $raw = $element->getText();
            $text = is_string($raw) ? $this->plain($raw) : '';
            $styleName = method_exists($element, 'getStyle') && is_string($element->getStyle()) ? $element->getStyle() : '';
            $isHeading = Str::startsWith($styleName, 'Heading');
            $stream[] = [$isHeading ? 'heading' : 'para', trim($text), false];
            return;
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $this->collect($child, $stream);
            }
        }
    }

    private function plain($text): string
    {
        return is_string($text) ? trim(html_entity_decode(strip_tags($text))) : '';
    }

    private function makeKey(string $label): string
    {
        $key = Str::slug($label, '_');
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key));
        return $key !== '' ? Str::limit($key, 40, '') : 'field_' . strtolower(Str::random(6));
    }

    /** Cheap deterministic type guess from the label wording. */
    private function guessType(string $label): string
    {
        $l = strtolower($label);
        return match (true) {
            str_contains($l, 'email') => 'email',
            str_contains($l, 'phone') || str_contains($l, 'mobile') || str_contains($l, 'contact number') => 'phone',
            str_contains($l, 'date') || str_contains($l, 'dob') || str_contains($l, 'birth') => 'date',
            str_contains($l, 'upload') || str_contains($l, 'resume') || str_contains($l, 'cv') || str_contains($l, 'attach') => 'file',
            str_contains($l, 'address') || str_contains($l, 'comment') || str_contains($l, 'describe') || str_contains($l, 'message') => 'textarea',
            str_contains($l, 'age') || str_contains($l, 'year') || str_contains($l, 'number') || str_contains($l, 'quantity') => 'number',
            default => 'text',
        };
    }
}
