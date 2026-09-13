<?php

declare(strict_types=1);

namespace App\Tests\Unit\Git\Value;

use App\Git\Value\CommitRequest;
use App\Git\Value\CommitTrailers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommitTrailers::class)]
#[CoversClass(CommitRequest::class)]
final class CommitTrailersTest extends TestCase
{
    public function testTrailersAreAppendedInTheOrderOfTheSpecification(): void
    {
        $trailers = CommitTrailers::create(
            source: 'gesetze-im-internet',
            sourceUrl: 'https://www.gesetze-im-internet.de/aufenthg_2004/',
            amendingAct: 'BGBl. 2026 I Nr. 123',
            amendingActUrl: 'https://www.recht.bund.de/eli/bund/BGBl-1/2026/123/',
            changeId: '2026-bund-bgbl-i-123',
        );

        self::assertSame(
            <<<'MESSAGE'
                Aktualisierung: AufenthG

                Source: gesetze-im-internet
                Source-Url: https://www.gesetze-im-internet.de/aufenthg_2004/
                Amending-Act: BGBl. 2026 I Nr. 123
                Amending-Act-Url: https://www.recht.bund.de/eli/bund/BGBl-1/2026/123/
                Change-Id: 2026-bund-bgbl-i-123

                MESSAGE,
            $trailers->applyTo('Aktualisierung: AufenthG'),
        );
    }

    public function testAMessageWithoutTrailersKeepsASingleTrailingNewline(): void
    {
        self::assertSame("Initialise repository structure\n", CommitTrailers::none()->applyTo("Initialise repository structure\n\n"));
    }

    public function testEmptyValuesAreDropped(): void
    {
        $trailers = CommitTrailers::create(source: 'gesetze-im-internet', amendingAct: '');

        self::assertSame("Update\n\nSource: gesetze-im-internet\n", $trailers->applyTo('Update'));
    }

    public function testTrailersAreReadBackFromACommitMessage(): void
    {
        $message = CommitTrailers::create(
            source: 'bund.bgbl',
            changeId: '2026-bund-bgbl-i-123',
        )->applyTo("BGBl. 2026 I Nr. 123 — Fachkräfteeinwanderung\n\nSome body text.");

        $parsed = CommitTrailers::parse($message);

        self::assertSame('bund.bgbl', $parsed->get('Source'));
        self::assertSame('2026-bund-bgbl-i-123', $parsed->get('Change-Id'));
        self::assertNull($parsed->get('Amending-Act'));
    }

    public function testCoAuthorsAreAddedForEditsMadeOnBehalfOfAPerson(): void
    {
        $request = new CommitRequest(
            'Correct the amount in the summary',
            CommitTrailers::create(changeId: '2026-bund-bgbl-i-123'),
            coAuthors: ['Editor <editor@example.org>'],
        );

        $message = $request->fullMessage();

        self::assertStringContainsString('Change-Id: 2026-bund-bgbl-i-123', $message);
        self::assertStringContainsString('Co-authored-by: Editor <editor@example.org>', $message);
    }
}
