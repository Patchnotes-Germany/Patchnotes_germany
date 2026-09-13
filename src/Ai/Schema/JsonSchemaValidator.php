<?php

declare(strict_types=1);

namespace App\Ai\Schema;

/**
 * Checks a model answer against the task schema (SPEC.md § 8.4).
 *
 * A deliberately small validator for the subset of JSON Schema the project's own schemas use:
 * type, properties, required, additionalProperties, items, enum, const, the numeric and string
 * bounds, and the combinators. There is no $ref and no remote resolution — the schemas live in
 * config/ai/schemas and are written for this validator, so a full implementation would be a
 * dependency bought for nothing.
 *
 * The result is a list of human-readable problems, because they are fed straight back to the model
 * in the repair attempt (SPEC.md § 8.2).
 */
final class JsonSchemaValidator
{
    private const int MAX_ERRORS = 20;

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> empty when the value satisfies the schema
     */
    public function validate(array $schema, mixed $value): array
    {
        $errors = [];
        $this->check($schema, $value, '$', $errors);

        return \array_slice($errors, 0, self::MAX_ERRORS);
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private function check(array $schema, mixed $value, string $path, array &$errors): void
    {
        if (\count($errors) >= self::MAX_ERRORS) {
            return;
        }

        if (isset($schema['const']) && $value !== $schema['const']) {
            $errors[] = \sprintf('%s must be %s.', $path, $this->describe($schema['const']));
        }

        if (isset($schema['enum']) && \is_array($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            $errors[] = \sprintf('%s must be one of %s.', $path, implode(', ', array_map($this->describe(...), $schema['enum'])));
        }

        if (isset($schema['type']) && !$this->matchesType($schema['type'], $value)) {
            $errors[] = \sprintf(
                '%s must be of type %s, %s given.',
                $path,
                \is_array($schema['type']) ? implode('|', array_map(strval(...), $schema['type'])) : (string) $schema['type'],
                $this->typeOf($value),
            );

            // Everything below depends on the type, so there is nothing more to say about it.
            return;
        }

        $this->checkCombinators($schema, $value, $path, $errors);

        if (\is_array($value)) {
            // An empty JSON object and an empty JSON array are one and the same value in PHP, so the
            // schema has to decide which of the two it is — otherwise "{}" would slip past every
            // "required" rule by being mistaken for a list.
            if ([] === $value ? $this->isObjectSchema($schema) : !array_is_list($value)) {
                $this->checkObject($schema, $value, $path, $errors);
            } else {
                $this->checkArray($schema, $value, $path, $errors);
            }
        }

        if (\is_string($value)) {
            $this->checkString($schema, $value, $path, $errors);
        }

        if (\is_int($value) || \is_float($value)) {
            $this->checkNumber($schema, $value, $path, $errors);
        }
    }

    /**
     * @param array<string, mixed>    $schema
     * @param array<array-key, mixed> $value
     * @param list<string>            $errors
     */
    private function checkObject(array $schema, array $value, string $path, array &$errors): void
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        if (\is_array($schema['required'] ?? null)) {
            foreach ($schema['required'] as $required) {
                if (\is_string($required) && !\array_key_exists($required, $value)) {
                    $errors[] = \sprintf('%s is missing the required property "%s".', $path, $required);
                }
            }
        }

        if (false === ($schema['additionalProperties'] ?? null) && [] !== $properties) {
            foreach (array_keys($value) as $key) {
                if (!\array_key_exists($key, $properties)) {
                    $errors[] = \sprintf('%s has the unexpected property "%s".', $path, $key);
                }
            }
        }

        foreach ($properties as $name => $subSchema) {
            if (\array_key_exists($name, $value)) {
                $this->check($subSchema, $value[$name], $path.'.'.$name, $errors);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed>          $value
     * @param list<string>         $errors
     */
    private function checkArray(array $schema, array $value, string $path, array &$errors): void
    {
        if (isset($schema['minItems']) && is_numeric($schema['minItems']) && \count($value) < (int) $schema['minItems']) {
            $errors[] = \sprintf('%s must have at least %d items.', $path, (int) $schema['minItems']);
        }

        if (isset($schema['maxItems']) && is_numeric($schema['maxItems']) && \count($value) > (int) $schema['maxItems']) {
            $errors[] = \sprintf('%s must have at most %d items.', $path, (int) $schema['maxItems']);
        }

        if (!\is_array($schema['items'] ?? null)) {
            return;
        }

        /** @var array<string, mixed> $items */
        $items = $schema['items'];
        foreach ($value as $index => $item) {
            $this->check($items, $item, $path.'['.$index.']', $errors);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private function checkString(array $schema, string $value, string $path, array &$errors): void
    {
        if (isset($schema['minLength']) && is_numeric($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
            $errors[] = \sprintf('%s must be at least %d characters long.', $path, (int) $schema['minLength']);
        }

        if (isset($schema['maxLength']) && is_numeric($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
            $errors[] = \sprintf('%s must be at most %d characters long.', $path, (int) $schema['maxLength']);
        }

        if (\is_string($schema['pattern'] ?? null) && 1 !== preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $value)) {
            $errors[] = \sprintf('%s must match the pattern %s.', $path, $schema['pattern']);
        }

        if ('date' === ($schema['format'] ?? null) && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $errors[] = \sprintf('%s must be a date in YYYY-MM-DD form.', $path);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private function checkNumber(array $schema, int|float $value, string $path, array &$errors): void
    {
        if (isset($schema['minimum']) && is_numeric($schema['minimum']) && $value < (float) $schema['minimum']) {
            $errors[] = \sprintf('%s must be at least %s.', $path, (string) $schema['minimum']);
        }

        if (isset($schema['maximum']) && is_numeric($schema['maximum']) && $value > (float) $schema['maximum']) {
            $errors[] = \sprintf('%s must be at most %s.', $path, (string) $schema['maximum']);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private function checkCombinators(array $schema, mixed $value, string $path, array &$errors): void
    {
        if (\is_array($schema['allOf'] ?? null)) {
            /** @var list<array<string, mixed>> $all */
            $all = $schema['allOf'];
            foreach ($all as $subSchema) {
                $this->check($subSchema, $value, $path, $errors);
            }
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (!\is_array($schema[$keyword] ?? null)) {
                continue;
            }

            /** @var list<array<string, mixed>> $options */
            $options = $schema[$keyword];
            $matches = 0;

            foreach ($options as $subSchema) {
                $subErrors = [];
                $this->check($subSchema, $value, $path, $subErrors);
                if ([] === $subErrors) {
                    ++$matches;
                }
            }

            if (0 === $matches) {
                $errors[] = \sprintf('%s does not match any of the allowed shapes (%s).', $path, $keyword);
            }
        }
    }

    /**
     * Whether the schema describes an object rather than a list.
     *
     * @param array<string, mixed> $schema
     */
    private function isObjectSchema(array $schema): bool
    {
        return 'object' === ($schema['type'] ?? null)
            || isset($schema['properties'])
            || isset($schema['required']);
    }

    private function matchesType(mixed $type, mixed $value): bool
    {
        if (\is_array($type)) {
            return array_any($type, fn ($candidate): bool => $this->matchesType($candidate, $value));
        }

        return match ($type) {
            // An empty JSON object and an empty JSON array both decode to an empty PHP array, so an
            // empty value satisfies either type; what it is missing is then reported by "required".
            'object' => \is_array($value) && ([] === $value || !array_is_list($value)),
            'array' => \is_array($value) && array_is_list($value),
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'null' => null === $value,
            default => true,
        };
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            null === $value => 'null',
            \is_bool($value) => 'boolean',
            \is_int($value) => 'integer',
            \is_float($value) => 'number',
            \is_string($value) => 'string',
            \is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => get_debug_type($value),
        };
    }

    private function describe(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_UNICODE) ?: '?';
    }
}
