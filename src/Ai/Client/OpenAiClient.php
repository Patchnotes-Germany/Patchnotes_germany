<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\Enum\BatchState;
use App\Ai\Exception\LlmException;
use App\Ai\Value\BatchHandle;
use App\Ai\Value\BatchStatus;
use App\Ai\Value\LlmResponse;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * OpenAI (SPEC.md § 8.1): structured outputs and the Batch API on top of the chat dialect.
 *
 * The Batch API is half the price and may take up to 24 hours, so it is reserved for bulk work —
 * pre-translation and bootstrap tagging — never for the live pipeline (SPEC.md § 24.14).
 */
final class OpenAiClient extends OpenAiStyleClient
{
    public function supportsJsonSchema(): bool
    {
        return true;
    }

    public function supportsBatch(): bool
    {
        return true;
    }

    public function submitBatch(array $requests): BatchHandle
    {
        if ([] === $requests) {
            throw LlmException::misconfigured($this->providerName, 'a batch needs at least one request');
        }

        $lines = [];
        foreach ($requests as $index => $request) {
            $id = '' !== $request->id ? $request->id : 'request-'.$index;
            $lines[] = json_encode([
                'custom_id' => $id,
                'method' => 'POST',
                'url' => '/v1/chat/completions',
                'body' => $this->completionPayload($request, $request->boundModel()->model),
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        $fileId = $this->uploadBatchInput(implode("\n", $lines)."\n");

        $batch = $this->post('/batches', [
            'input_file_id' => $fileId,
            'endpoint' => '/v1/chat/completions',
            'completion_window' => '24h',
        ]);

        return new BatchHandle(
            $this->providerName,
            (string) ($batch['id'] ?? ''),
            new \DateTimeImmutable(),
            \count($requests),
            ['input_file_id' => $fileId],
        );
    }

    public function pollBatch(BatchHandle $handle): BatchStatus
    {
        $batch = $this->get('/batches/'.rawurlencode($handle->id));

        /** @var array<string, mixed> $counts */
        $counts = \is_array($batch['request_counts'] ?? null) ? $batch['request_counts'] : [];

        return new BatchStatus(
            $this->state((string) ($batch['status'] ?? '')),
            (int) ($counts['completed'] ?? 0),
            (int) ($counts['failed'] ?? 0),
            (int) ($counts['total'] ?? $handle->requestCount),
        );
    }

    public function fetchBatchResults(BatchHandle $handle): array
    {
        $batch = $this->get('/batches/'.rawurlencode($handle->id));
        $outputFile = $batch['output_file_id'] ?? null;

        if (!\is_string($outputFile) || '' === $outputFile) {
            return [];
        }

        $results = [];

        foreach (explode("\n", $this->downloadFile($outputFile)) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            try {
                /** @var array<string, mixed> $entry */
                $entry = (array) json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            $customId = (string) ($entry['custom_id'] ?? '');
            /** @var array<string, mixed> $response */
            $response = \is_array($entry['response'] ?? null) ? $entry['response'] : [];
            /** @var array<string, mixed> $body */
            $body = \is_array($response['body'] ?? null) ? $response['body'] : [];
            /** @var array<int, array<string, mixed>> $choices */
            $choices = \is_array($body['choices'] ?? null) ? $body['choices'] : [];

            if ('' === $customId || [] === $choices) {
                continue;
            }

            /** @var array<string, mixed> $message */
            $message = \is_array($choices[0]['message'] ?? null) ? $choices[0]['message'] : [];

            $results[$customId] = new LlmResponse(
                \is_string($message['content'] ?? null) ? $message['content'] : '',
                $this->providerName.':'.($body['model'] ?? ''),
                $this->usage(\is_array($body['usage'] ?? null) ? $body['usage'] : []),
                0,
                false,
                \is_string($choices[0]['finish_reason'] ?? null) ? $choices[0]['finish_reason'] : null,
            );
        }

        return $results;
    }

    /**
     * The reasoning models rejected "max_tokens"; this is the field they accept.
     */
    protected function maxTokensField(): string
    {
        return 'max_completion_tokens';
    }

    /**
     * Batch input is a JSONL file, so it goes through the files endpoint as multipart — the only
     * call in this client that is not plain JSON.
     */
    private function uploadBatchInput(string $jsonl): string
    {
        $boundary = 'patchnotes'.bin2hex(random_bytes(16));
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"purpose\"\r\n\r\nbatch\r\n"
            ."--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"file\"; filename=\"batch.jsonl\"\r\n"
            ."Content-Type: application/jsonl\r\n\r\n"
            .$jsonl."\r\n"
            ."--{$boundary}--\r\n";

        $headers = $this->headers();
        $headers['Content-Type'] = 'multipart/form-data; boundary='.$boundary;

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/files', [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $this->timeoutSeconds,
            ]);

            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $exception) {
            throw LlmException::transport($this->providerName, $exception);
        }

        if ($status >= 400) {
            throw LlmException::http($this->providerName, $status, $content);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = (array) json_decode($content, true);
        $id = $decoded['id'] ?? null;

        return \is_string($id) && '' !== $id
            ? $id
            : throw LlmException::http($this->providerName, $status, 'the file upload returned no id');
    }

    private function downloadFile(string $fileId): string
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/').'/files/'.rawurlencode($fileId).'/content', [
                'headers' => $this->headers(),
                'timeout' => $this->timeoutSeconds,
            ]);

            if ($response->getStatusCode() >= 400) {
                throw LlmException::http($this->providerName, $response->getStatusCode(), $response->getContent(false));
            }

            return $response->getContent(false);
        } catch (HttpExceptionInterface $exception) {
            throw LlmException::transport($this->providerName, $exception);
        }
    }

    private function state(string $status): BatchState
    {
        return match ($status) {
            'validating', 'in_progress', 'finalizing', 'cancelling' => BatchState::InProgress,
            'completed' => BatchState::Completed,
            'failed' => BatchState::Failed,
            'cancelled' => BatchState::Cancelled,
            'expired' => BatchState::Expired,
            default => BatchState::Pending,
        };
    }
}
