<?php

declare(strict_types=1);

namespace App\Content\Card;

/**
 * One `{{ … }}` placeholder in a card (SPEC.md § 5.4, § 24.13).
 *
 * Every number, date, norm reference and glossary term in a card is a placeholder rather than
 * literal text. That is what makes a translation unable to change a figure: the translator — human
 * or model — moves the placeholder around, and the value comes from facts.yml at render time.
 */
final readonly class Placeholder implements \Stringable
{
    public const string TYPE_AMOUNT = 'amount';
    public const string TYPE_DATE = 'date';
    public const string TYPE_NORM = 'norm';
    public const string TYPE_TERM = 'term';

    public const array TYPES = [self::TYPE_AMOUNT, self::TYPE_DATE, self::TYPE_NORM, self::TYPE_TERM];

    public function __construct(
        /** amount | date | norm | term */
        public string $type,
        /** Everything after the colon: "blue_card_salary_threshold.new", "effective.0", "bund/estg/p32". */
        public string $argument,
        /** The placeholder exactly as written, so it can be replaced verbatim. */
        public string $raw,
    ) {
    }

    public function __toString(): string
    {
        return $this->canonical();
    }

    /**
     * The key part of an amount reference: "blue_card_salary_threshold" of
     * "blue_card_salary_threshold.new".
     */
    public function key(): string
    {
        $dot = strpos($this->argument, '.');

        return false === $dot ? $this->argument : substr($this->argument, 0, $dot);
    }

    /**
     * The field part: "new", "old", or for dates the index — "0" of "effective.0".
     */
    public function field(): ?string
    {
        $dot = strpos($this->argument, '.');

        return false === $dot ? null : substr($this->argument, $dot + 1);
    }

    public function isKnownType(): bool
    {
        return \in_array($this->type, self::TYPES, true);
    }

    /**
     * The canonical spelling, used when comparing a translation against its master: whitespace
     * inside the braces must not count as a difference.
     */
    public function canonical(): string
    {
        return \sprintf('{{ %s:%s }}', $this->type, $this->argument);
    }
}
