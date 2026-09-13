<?php

declare(strict_types=1);

namespace App\Ai\Client;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Any server that speaks the OpenAI API: Ollama, LM Studio, vLLM, llama.cpp, LocalAI
 * (SPEC.md § 8.1).
 *
 * These servers differ in how much of the API they implement, so the two things that actually vary
 * — whether the server can enforce a JSON schema, and how long it may take — come from
 * configuration. Nothing here assumes a particular one of them.
 */
final class OpenAiCompatibleClient extends OpenAiStyleClient
{
    public function __construct(
        string $providerName,
        HttpClientInterface $httpClient,
        string $baseUrl,
        ?string $apiKey,
        int $timeoutSeconds,
        LoggerInterface $logger,
        /**
         * LM Studio and vLLM enforce a JSON schema; Ollama through /v1 only guarantees "some JSON".
         * When false the gateway validates and repairs the answer itself.
         */
        private readonly bool $jsonSchemaSupported = false,
    ) {
        parent::__construct($providerName, $httpClient, $baseUrl, $apiKey, $timeoutSeconds, $logger);
    }

    public function supportsJsonSchema(): bool
    {
        return $this->jsonSchemaSupported;
    }
}
