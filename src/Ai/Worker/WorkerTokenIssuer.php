<?php

declare(strict_types=1);

namespace App\Ai\Worker;

use App\Ai\Entity\WorkerToken;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues and revokes worker credentials (SPEC.md § 8.3).
 *
 * The plaintext token exists exactly once, in the answer to this call: it is shown to the operator
 * and then only its hash remains. The admin UI of M8 wraps the same service.
 */
final readonly class WorkerTokenIssuer
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{WorkerToken, string} the token entity and the plaintext, which is never stored
     */
    public function issue(string $name): array
    {
        $plain = 'pnw_'.bin2hex(random_bytes(32));

        $token = new WorkerToken($name, WorkerTokenAuthenticator::hash($plain));
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return [$token, $plain];
    }

    public function revoke(WorkerToken $token): void
    {
        $token->revoke(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * @return list<WorkerToken>
     */
    public function all(): array
    {
        return $this->entityManager->getRepository(WorkerToken::class)->findBy([], ['createdAt' => 'DESC']);
    }
}
