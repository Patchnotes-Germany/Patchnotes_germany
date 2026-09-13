<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source\Http;

use App\Source\Http\PoliteHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * robots.txt decides whether we may read a source at all (SPEC.md § 0.3.4, § 6.1): a protection is
 * never worked around, so the parser errs on the side of not crawling.
 */
#[CoversClass(PoliteHttpClient::class)]
final class RobotsTxtTest extends TestCase
{
    private const string USER_AGENT = 'PatchnotesBot/1.0 (+https://example.org/bot; bot@example.org)';

    public function testAnEmptyDisallowAllowsEverything(): void
    {
        // This is what gesetze-im-internet.de actually serves (checked 2026-09-13).
        $rules = PoliteHttpClient::parseRobots("User-agent: *\nDisallow:\n", self::USER_AGENT);

        self::assertSame([], $rules['disallow']);
        self::assertFalse($rules['disallow_all']);
    }

    public function testASlashDisallowsTheWholeHost(): void
    {
        $rules = PoliteHttpClient::parseRobots("User-agent: *\nDisallow: /\n", self::USER_AGENT);

        self::assertTrue($rules['disallow_all']);
    }

    public function testPathsAreCollected(): void
    {
        $robots = <<<'TXT'
            # comment
            User-agent: *
            Disallow: /suche
            Disallow: /admin/
            Allow: /
            TXT;

        $rules = PoliteHttpClient::parseRobots($robots, self::USER_AGENT);

        self::assertSame(['/suche', '/admin/'], $rules['disallow']);
        self::assertFalse($rules['disallow_all']);
    }

    public function testOurOwnUserAgentGroupWinsOverTheWildcard(): void
    {
        $robots = <<<'TXT'
            User-agent: *
            Disallow:

            User-agent: patchnotesbot
            Disallow: /xml
            Crawl-delay: 5
            TXT;

        $rules = PoliteHttpClient::parseRobots($robots, self::USER_AGENT);

        self::assertSame(['/xml'], $rules['disallow']);
        self::assertSame(5.0, $rules['crawl_delay']);
    }

    public function testAGroupWithSeveralAgentsApplies(): void
    {
        $robots = <<<'TXT'
            User-agent: googlebot
            User-agent: patchnotesbot
            Disallow: /private
            TXT;

        $rules = PoliteHttpClient::parseRobots($robots, self::USER_AGENT);

        self::assertSame(['/private'], $rules['disallow']);
    }

    public function testAnUnknownAgentFallsBackToTheWildcardGroup(): void
    {
        $robots = <<<'TXT'
            User-agent: googlebot
            Disallow: /nogoogle

            User-agent: *
            Disallow: /nobody
            TXT;

        $rules = PoliteHttpClient::parseRobots($robots, 'OtherBot/1.0');

        self::assertSame(['/nobody'], $rules['disallow']);
    }

    public function testAnEmptyFileAllowsEverything(): void
    {
        $rules = PoliteHttpClient::parseRobots('', self::USER_AGENT);

        self::assertSame([], $rules['disallow']);
        self::assertFalse($rules['disallow_all']);
        self::assertNull($rules['crawl_delay']);
    }
}
