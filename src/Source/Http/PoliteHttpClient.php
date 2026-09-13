<?php

declare(strict_types=1);

namespace App\Source\Http;

use App\Core\Config\PatchnotesConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The only way the application talks to a source (SPEC.md § 6.1).
 *
 * Politeness is not optional here: these are public services of the Federation and the states, and
 * being a well-behaved client is what keeps the project welcome. Therefore: an identifying
 * User-Agent with a contact address, at most one request per second and host, robots.txt honoured
 * before the first request, and conditional GET so an unchanged document is never downloaded twice.
 */
final class PoliteHttpClient
{
    /** @var array<string, float> host => timestamp of the last request */
    private array $lastRequest = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.http_source')]
        private readonly CacheInterface $cache,
        private readonly PatchnotesConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws SourceUnavailable when the source cannot be reached or forbids the request
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResult
    {
        if (!$this->isAllowed($url)) {
            throw SourceUnavailable::disallowedByRobots($url);
        }

        $headers = [];
        if (null !== $etag && '' !== $etag) {
            $headers['If-None-Match'] = $etag;
        }
        if (null !== $lastModified && '' !== $lastModified) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        $this->throttle($url);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => $this->userAgent()] + $headers,
                'timeout' => $this->timeout(),
            ]);

            $status = $response->getStatusCode();

            if (304 === $status) {
                $this->logger->debug('Source document unchanged', ['url' => $url]);

                return FetchResult::notModified($etag, $lastModified);
            }

            if ($status >= 400) {
                throw SourceUnavailable::httpError($url, $status);
            }

            return new FetchResult(
                $status,
                $response->getContent(),
                $this->header($response->getHeaders(false), 'content-type') ?? 'application/octet-stream',
                $this->header($response->getHeaders(false), 'etag'),
                $this->header($response->getHeaders(false), 'last-modified'),
            );
        } catch (SourceUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw SourceUnavailable::transportError($url, $exception);
        }
    }

    /**
     * robots.txt is fetched once per host and day (SPEC.md § 6.1). Following RFC 9309: a 4xx answer
     * means "no restrictions", a 5xx answer means "assume everything is disallowed".
     */
    public function isAllowed(string $url): bool
    {
        $host = $this->host($url);
        $path = parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) && '' !== $path ? $path : '/';

        $rules = $this->cache->get('robots.'.md5($host), function (ItemInterface $item) use ($url, $host): array {
            $item->expiresAfter(86400);

            return $this->loadRobots($url, $host);
        });

        if (($rules['disallow_all'] ?? false) === true) {
            return false;
        }

        /** @var list<string> $disallowed */
        $disallowed = $rules['disallow'] ?? [];
        foreach ($disallowed as $prefix) {
            if ('' !== $prefix && str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{disallow: list<string>, disallow_all: bool, crawl_delay: float|null}
     */
    public static function parseRobots(string $robots, string $userAgent): array
    {
        /** @var array<string, array{disallow: list<string>, crawl_delay: float|null}> $groups */
        $groups = [];
        /** @var list<string> $current */
        $current = [];

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ('' === $line || !str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map(trim(...), explode(':', $line, 2));
            $field = strtolower($field);

            if ('user-agent' === $field) {
                $current = [];
                $groups[strtolower($value)] ??= ['disallow' => [], 'crawl_delay' => null];
                $current[] = strtolower($value);
                continue;
            }

            foreach ($current as $agent) {
                if ('disallow' === $field) {
                    $groups[$agent]['disallow'][] = $value;
                } elseif ('crawl-delay' === $field && is_numeric($value)) {
                    $groups[$agent]['crawl_delay'] = (float) $value;
                }
            }
        }

        $token = strtolower((string) preg_replace('#/.*$#', '', $userAgent));
        $group = $groups[$token] ?? $groups['*'] ?? ['disallow' => [], 'crawl_delay' => null];

        $disallow = array_values(array_filter($group['disallow'] ?? [], static fn (string $rule): bool => '' !== $rule));

        return [
            'disallow' => $disallow,
            // "Disallow: /" bans everything; an empty Disallow explicitly allows everything.
            'disallow_all' => \in_array('/', $disallow, true),
            'crawl_delay' => $group['crawl_delay'] ?? null,
        ];
    }

    /**
     * @return array{disallow: list<string>, disallow_all: bool, crawl_delay: float|null}
     */
    private function loadRobots(string $url, string $host): array
    {
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $robotsUrl = (\is_string($scheme) ? $scheme : 'https').'://'.$host.'/robots.txt';

        try {
            $this->throttle($robotsUrl);
            $response = $this->httpClient->request('GET', $robotsUrl, [
                'headers' => ['User-Agent' => $this->userAgent()],
                'timeout' => $this->timeout(),
            ]);
            $status = $response->getStatusCode();

            if ($status >= 500) {
                $this->logger->warning('robots.txt unreachable, treating the host as disallowed', [
                    'host' => $host,
                    'status' => $status,
                ]);

                return ['disallow' => [], 'disallow_all' => true, 'crawl_delay' => null];
            }

            if ($status >= 400) {
                return ['disallow' => [], 'disallow_all' => false, 'crawl_delay' => null];
            }

            return self::parseRobots($response->getContent(false), $this->userAgent());
        } catch (\Throwable $exception) {
            $this->logger->warning('robots.txt could not be read, treating the host as disallowed', [
                'host' => $host,
                'error' => $exception->getMessage(),
            ]);

            return ['disallow' => [], 'disallow_all' => true, 'crawl_delay' => null];
        }
    }

    /**
     * At most `sources.crawler.max_rps_per_host` requests per second and host.
     */
    private function throttle(string $url): void
    {
        $host = $this->host($url);
        $minimumInterval = 1.0 / max(0.1, $this->maxRequestsPerSecond());

        $last = $this->lastRequest[$host] ?? 0.0;
        $wait = $last + $minimumInterval - microtime(true);

        if ($wait > 0) {
            usleep((int) round($wait * 1_000_000));
        }

        $this->lastRequest[$host] = microtime(true);
    }

    private function host(string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) ? strtolower($host) : 'unknown';
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        $value = $headers[$name][0] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function userAgent(): string
    {
        /** @var array{crawler: array{user_agent: string}} $sources */
        $sources = $this->config->sources();

        return $sources['crawler']['user_agent'];
    }

    private function maxRequestsPerSecond(): float
    {
        /** @var array{crawler: array{max_rps_per_host: int|float|string}} $sources */
        $sources = $this->config->sources();

        return (float) $sources['crawler']['max_rps_per_host'];
    }

    private function timeout(): float
    {
        /** @var array{crawler: array{timeout_seconds: int|float|string}} $sources */
        $sources = $this->config->sources();

        return (float) $sources['crawler']['timeout_seconds'];
    }
}
