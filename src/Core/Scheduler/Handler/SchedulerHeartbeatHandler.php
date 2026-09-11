<?php

declare(strict_types=1);

namespace App\Core\Scheduler\Handler;

use App\Core\Scheduler\Message\SchedulerHeartbeat;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
final readonly class SchedulerHeartbeatHandler
{
    public const string CACHE_KEY = 'scheduler.last_tick';

    public function __construct(
        #[Autowire(service: 'cache.scheduler')]
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SchedulerHeartbeat $message): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->cache->delete(self::CACHE_KEY);
        $this->cache->get(self::CACHE_KEY, static function (ItemInterface $item) use ($now): string {
            $item->expiresAfter(3600);

            return $now->format(\DATE_ATOM);
        });

        $this->logger->debug('Scheduler heartbeat', ['at' => $now->format(\DATE_ATOM)]);
    }
}
