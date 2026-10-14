<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Content;

use Illuminate\Support\Facades\Log;
use JsonException;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use UnexpectedValueException;

/**
 * Extracts checkable text units from publish-form values. Text, textarea, markdown and Bard
 * prose yield one unit at their complete publish path, such as `title`, `blocks.0.text`, or
 * `rows.1.body`; each Bard field is a single prose unit with blocks joined by two newlines and
 * hard breaks by one, excluding set text, which recurses as its own container. Bard
 * `save_html`/inline string storage is unsupported, and disabled replicator and Bard sets are
 * skipped.
 */
final class ContentWalker
{
    private array $orphanSets = [];

    /**
     * @param  callable(Field): bool|null  $include
     * @return list<TextUnit>
     */
    public function walk(Fields $fields, array $values, ?callable $include = null): array
    {
        $this->orphanSets = [];
        $units = $this->walkFields($fields, $values, $include, '');
        if ($this->orphanSets !== []) {
            Log::warning('greenwashing-checker: orphan sets skipped', ['sets' => $this->orphanSets]);
        }

        return $units;
    }

    /** @return list<TextUnit> */
    private function walkFields(Fields $fields, array $values, ?callable $include, string $prefix): array
    {
        $units = [];
        foreach ($fields->all() as $field) {
            if ($include !== null && ! $include($field)) {
                continue;
            }

            $handle = $field->handle();
            $path = $prefix === '' ? $handle : $prefix.'.'.$handle;
            $type = $field->type();
            $value = $values[$handle] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($type, ['text', 'textarea', 'markdown'], true)) {
                if (! is_string($value)) {
                    throw new UnexpectedValueException("Field [{$path}] must contain a string, got ".get_debug_type($value).'.');
                }
                $units[] = new TextUnit($path, $type, $value, $field->display());
            } elseif ($type === 'bard') {
                try {
                    $nodes = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;
                } catch (JsonException $exception) {
                    throw new UnexpectedValueException("Bard field [{$path}] must contain valid JSON.", previous: $exception);
                }
                if (! is_array($nodes)) {
                    throw new UnexpectedValueException("Bard field [{$path}] must contain an array or JSON array.");
                }
                $text = implode("\n\n", $this->bardBlocks($nodes));
                if ($text !== '') {
                    $units[] = new TextUnit($path, 'bard', $text, $field->display());
                }
                // Bard's publish paths use the node index, not the ordinal among sets.
                foreach ($nodes as $index => $node) {
                    if (($node['type'] ?? '') !== 'set') {
                        continue;
                    }
                    $setValues = $node['attrs']['values'] ?? null;
                    if (! is_array($setValues)) {
                        throw new UnexpectedValueException("Bard set [{$path}.{$index}] must contain values with a set type.");
                    }
                    if (($setValues['enabled'] ?? true) === false) {
                        continue;
                    }
                    if (! is_string($setValues['type'] ?? null)) {
                        throw new UnexpectedValueException("Bard set [{$path}.{$index}] must contain values with a set type.");
                    }
                    if (! $this->knownSet($field, $setValues['type'], "{$path}.{$index}")) {
                        continue;
                    }
                    $children = $field->fieldtype()->fields($setValues['type'], $index);
                    $units = array_merge($units, $this->walkFields($children, $setValues, $include, "{$path}.{$index}.attrs.values"));
                }
            } elseif (in_array($type, ['replicator', 'grid', 'group'], true)) {
                if (! is_array($value)) {
                    throw new UnexpectedValueException("Container field [{$path}] must contain an array.");
                }
                $fieldtype = $field->fieldtype();
                if ($type === 'group') {
                    $units = array_merge($units, $this->walkFields($fieldtype->fields(), $value, $include, $path));

                    continue;
                }
                foreach ($value as $index => $row) {
                    if (! is_array($row)) {
                        throw new UnexpectedValueException("Row [{$path}.{$index}] must contain an array.");
                    }
                    // Toggled-off sets never render, so findings there would be noise.
                    if ($type === 'replicator' && ($row['enabled'] ?? true) === false) {
                        continue;
                    }
                    if ($type === 'replicator') {
                        if (! is_string($row['type'] ?? null)) {
                            throw new UnexpectedValueException("Replicator set [{$path}.{$index}] must contain values with a set type.");
                        }
                        if (! $this->knownSet($field, $row['type'], "{$path}.{$index}")) {
                            continue;
                        }
                    }
                    // Statamic resolves both legacy sets and grouped sets, including fieldset imports.
                    $children = $type === 'replicator'
                        ? $fieldtype->fields($row['type'], $index)
                        : $fieldtype->fields($index);
                    $units = array_merge($units, $this->walkFields($children, $row, $include, "{$path}.{$index}"));
                }
            }
        }

        return $units;
    }

    private function knownSet(Field $field, string $type, string $path): bool
    {
        // Editors cannot repair invisible, removed set types here. Skip them, but report their paths once.
        if ($field->fieldtype()->flattenedSetsConfig()->has($type)) {
            return true;
        }
        $this->orphanSets[] = ['path' => $path, 'type' => $type];

        return false;
    }

    /** @return list<string> */
    private function bardBlocks(array $nodes): array
    {
        $blocks = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                throw new UnexpectedValueException('Bard nodes must be arrays.');
            }
            if (($node['type'] ?? '') === 'set') {
                continue;
            }
            $children = $node['content'] ?? [];
            $hasInline = false;
            foreach ($children as $child) {
                if (in_array($child['type'] ?? '', ['text', 'hardBreak', 'hard_break'], true)) {
                    $hasInline = true;
                    break;
                }
            }
            if (! $hasInline) {
                $blocks = array_merge($blocks, $this->bardBlocks($children));

                continue;
            }
            $text = '';
            foreach ($children as $child) {
                if (($child['type'] ?? '') === 'text') {
                    if (! is_string($child['text'] ?? null)) {
                        throw new UnexpectedValueException('Bard text must be a string.');
                    }
                    $text .= $child['text'];
                } elseif (in_array($child['type'] ?? '', ['hardBreak', 'hard_break'], true)) {
                    $text .= "\n";
                }
            }
            if ($text !== '') {
                $blocks[] = $text;
            }
        }

        return $blocks;
    }
}
