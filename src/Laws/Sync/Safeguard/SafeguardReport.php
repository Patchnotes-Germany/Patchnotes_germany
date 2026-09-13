<?php

declare(strict_types=1);

namespace App\Laws\Sync\Safeguard;

/**
 * Result of the pre-merge checks (SPEC.md § 4.6). Also the body of the bot's pull request comment,
 * so that a human can see at a glance why a synchronisation was held back.
 */
final readonly class SafeguardReport
{
    /**
     * @param list<SafeguardViolation> $violations
     */
    public function __construct(public array $violations)
    {
    }

    public function passed(): bool
    {
        return [] === $this->violations;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(array_unique(array_map(
            static fn (SafeguardViolation $violation): string => $violation->code,
            $this->violations,
        )));
    }

    /**
     * @return array<string, mixed> stored on ChangeRequest.checks
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'violations' => array_map(
                static fn (SafeguardViolation $violation): array => [
                    'code' => $violation->code,
                    'message' => $violation->message,
                    'subject' => $violation->subject,
                ],
                $this->violations,
            ),
        ];
    }

    public function toMarkdown(): string
    {
        if ($this->passed()) {
            return "### Automatische Prüfungen\n\n✅ Alle Prüfungen bestanden — dieser Pull Request wird automatisch gemergt.\n";
        }

        $lines = [
            '### Automatische Prüfungen',
            '',
            '🚫 Dieser Pull Request wird **nicht** automatisch gemergt:',
            '',
        ];

        foreach ($this->violations as $violation) {
            $lines[] = '- **'.$violation->code.'**: '.$violation->message;
        }

        $lines[] = '';
        $lines[] = 'Bitte prüfen, ob die Quelle ihr Format geändert hat oder die Konvertierung fehlerhaft ist.';

        return implode("\n", $lines)."\n";
    }
}
