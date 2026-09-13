<?php

declare(strict_types=1);

namespace App\Ai\Worker;

use App\Ai\Entity\WorkerToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates a remote AI worker by its bearer token (SPEC.md § 8.3, § 15).
 *
 * Only the hash of a token is stored, so the database cannot be used to impersonate a worker. The
 * worker API is reachable from the open internet — it has to be, the worker sits behind someone's
 * home router — so an unauthenticated call must never reach the queue.
 */
final readonly class WorkerTokenAuthenticator
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function authenticate(Request $request): ?WorkerToken
    {
        $header = $request->headers->get('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return $this->byPlainToken(substr($header, 7));
    }

    public function byPlainToken(string $plainToken): ?WorkerToken
    {
        $plainToken = trim($plainToken);

        if ('' === $plainToken) {
            return null;
        }

        $token = $this->entityManager->getRepository(WorkerToken::class)->findOneBy([
            'tokenHash' => self::hash($plainToken),
        ]);

        return $token instanceof WorkerToken && $token->isActive() ? $token : null;
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
