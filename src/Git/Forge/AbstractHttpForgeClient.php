<?php

declare(strict_types=1);

namespace App\Git\Forge;

use App\Git\RepositoryConfig;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Shared plumbing for the HTTP-based forges: URL building, authentication, error handling.
 */
abstract class AbstractHttpForgeClient implements ForgeClientInterface
{
    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
    ) {
    }

    abstract protected function defaultApiUrl(): string;

    /**
     * @return array<string, string>
     */
    abstract protected function authHeaders(RepositoryConfig $repository): array;

    protected function project(RepositoryConfig $repository): string
    {
        return $repository->forgeProject
            ?? throw ForgeException::misconfigured($this->type()->value, 'no project (org/repo or id) configured');
    }

    protected function baseUrl(RepositoryConfig $repository): string
    {
        return rtrim($repository->forgeApiUrl ?? $this->defaultApiUrl(), '/');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function request(RepositoryConfig $repository, string $method, string $path, array $payload = [], string $operation = 'request'): array
    {
        $options = [
            'headers' => $this->authHeaders($repository) + ['Accept' => 'application/json'],
        ];
        if ([] !== $payload) {
            $options['json'] = $payload;
        }

        $response = $this->httpClient->request($method, $this->baseUrl($repository).$path, $options);
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400) {
            throw ForgeException::request($this->type()->value, $operation, $statusCode, $response->getContent(false));
        }

        if (204 === $statusCode) {
            return [];
        }

        $content = $response->getContent(false);
        if ('' === trim($content)) {
            return [];
        }

        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            return [];
        }

        // Collection endpoints answer with a JSON array; it is exposed under "items".
        if (array_is_list($decoded)) {
            return ['items' => $decoded];
        }

        $normalised = [];
        foreach ($decoded as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    /**
     * Constant-time comparison of an HMAC-SHA256 signature over the raw request body.
     */
    protected function verifyHmacSignature(?string $secret, string $rawBody, ?string $signature, string $prefix = ''): bool
    {
        if (null === $secret || '' === $secret || null === $signature || '' === $signature) {
            return false;
        }

        $expected = $prefix.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    protected static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) || \is_int($value) ? (string) $value : null;
    }
}
