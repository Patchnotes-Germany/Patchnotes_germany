<?php

declare(strict_types=1);

namespace App\Content\Glossary;

/**
 * One glossary entry (SPEC.md § 5.5).
 */
final readonly class GlossaryEntry
{
    public function __construct(
        public string $germanTerm,
        /** How the term appears in running text, e.g. "Aufenthaltstitel (вид на жительство)". */
        public string $render,
        public ?string $explanation = null,
        /** Whether the German word itself must survive the translation. */
        public bool $keepGerman = true,
    ) {
    }

    /**
     * The part of the rendering a check can look for in a translated text.
     *
     * Declensions make an exact match unreliable in Russian, Ukrainian and Turkish, so the check
     * looks for the German term inside the rendering — which is the part that must not change.
     */
    public function expectedInTranslation(): string
    {
        return $this->keepGerman ? $this->germanTerm : $this->render;
    }
}
