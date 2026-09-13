<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Git\Enum\RepositoryName;
use App\Git\Forge\ForgeClientLocator;
use App\Git\Message\RefreshChangeRequestStatus;
use App\Git\Message\SynchroniseRepository;
use App\Git\RepositoryRegistry;
use App\Git\Webhook\WebhookEventType;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Forge webhooks (SPEC.md § 3.2).
 *
 * Every payload is authenticated before it is looked at: GitHub and Gitea sign the body, GitLab
 * echoes a secret token. An unsigned or wrongly signed call is rejected with 401 and nothing is
 * dispatched — a webhook endpoint is an unauthenticated door into the system otherwise.
 */
final readonly class ForgeWebhookController
{
    public function __construct(
        private RepositoryRegistry $repositories,
        private ForgeClientLocator $forges,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/webhooks/forge/{repo}',
        name: 'app_webhook_forge',
        requirements: ['repo' => 'laws|content'],
        methods: ['POST'],
    )]
    public function __invoke(string $repo, Request $request): JsonResponse
    {
        $repository = RepositoryName::from($repo);
        $config = $this->repositories->configFor($repository);
        $forge = $this->forges->for($config);

        $rawBody = $request->getContent();
        $headers = $this->headers($request);

        if (!$forge->verifyWebhook($config, $rawBody, $headers)) {
            $this->logger->warning('Rejected forge webhook with an invalid signature', [
                'repository' => $repository->value,
                'forge' => $config->forge->value,
            ]);

            return new JsonResponse(['status' => 'unauthorised'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = [] === $rawBody ? [] : (array) json_decode($rawBody, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['status' => 'invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $event = $forge->parseWebhook($payload, $headers);
        if (!$event instanceof \App\Git\Webhook\WebhookEvent) {
            return new JsonResponse(['status' => 'ignored'], Response::HTTP_OK);
        }

        if (WebhookEventType::Push === $event->type) {
            if (!$event->isPushTo($config->defaultBranch)) {
                return new JsonResponse(['status' => 'ignored'], Response::HTTP_OK);
            }

            $this->bus->dispatch(new SynchroniseRepository($repository, $event->commit));

            return new JsonResponse(['status' => 'accepted'], Response::HTTP_ACCEPTED);
        }

        if (null !== $event->changeRequestId) {
            $this->bus->dispatch(new RefreshChangeRequestStatus(
                $repository,
                $event->changeRequestId,
                $event->status,
                $event->mergeCommit,
            ));

            // A merged pull request also moves the default branch.
            if (null !== $event->mergeCommit) {
                $this->bus->dispatch(new SynchroniseRepository($repository, $event->mergeCommit));
            }
        }

        return new JsonResponse(['status' => 'accepted'], Response::HTTP_ACCEPTED);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $value = $values[0] ?? null;
            if (\is_string($value)) {
                $headers[strtolower($name)] = $value;
            }
        }

        return $headers;
    }
}
