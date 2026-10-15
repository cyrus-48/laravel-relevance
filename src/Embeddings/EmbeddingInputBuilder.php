<?php

declare(strict_types=1);

namespace Relevance\Embeddings;

use Relevance\Schema\IndexDefinition;

class EmbeddingInputBuilder
{
    /**
     * @param array<string, mixed> $fields
     */
    public function build(IndexDefinition $def, array $fields, bool $allowSensitive = false): string
    {
        $schema = $def->compiledSchema();
        $sources = [];
        $sensitiveFields = [];

        foreach ($schema->fields as $name => $fieldData) {
            if (is_array($fieldData)) {
                if (($fieldData['type'] ?? '') === 'vector') {
                    $sources = $fieldData['sources'] ?? [];
                }
                if (!empty($fieldData['sensitive'])) {
                    $sensitiveFields[$name] = true;
                }
            }
        }

        if (empty($sources)) {
            // Default to title and description if not explicitly set
            $sources = ['title', 'name', 'description', 'content'];
        }

        $parts = [];
        foreach ($sources as $source) {
            if (isset($sensitiveFields[$source]) && !$allowSensitive) {
                continue;
            }

            if (isset($fields[$source]) && (is_string($fields[$source]) || is_numeric($fields[$source]))) {
                $val = trim((string) $fields[$source]);
                if ($val !== '') {
                    $parts[] = "{$source}: {$val}";
                }
            }
        }

        $combined = implode("\n", $parts);

        // Truncate to ~8000 characters to protect token budget
        return mb_substr($combined, 0, 8000);
    }
}
