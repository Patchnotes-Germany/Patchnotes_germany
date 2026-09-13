<?php

declare(strict_types=1);

namespace App\Source\Registry;

use App\Source\Adapter\SourceAdapterInterface;
use App\Source\Adapter\SourceCapability;
use App\Source\Entity\Source;
use App\Source\Enum\SourceHealthState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Makes every configured adapter visible as a `Source` row (SPEC.md § 6.1).
 *
 * The rows are what the admin, the health monitoring and the public /status page work with, and
 * every raw document is attached to one of them — so they must exist before the first
 * synchronisation runs. Health that was recorded by a previous run is preserved: registering is
 * bookkeeping, not a reset.
 */
final readonly class SourceRegistrar
{
    /**
     * @param iterable<SourceAdapterInterface> $adapters
     */
    public function __construct(
        #[AutowireIterator('app.source_adapter')]
        private iterable $adapters,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return int the number of sources newly registered
     */
    public function register(): int
    {
        $created = 0;

        foreach ($this->adapters as $adapter) {
            $source = $this->entityManager->find(Source::class, $adapter->key());

            if (!$source instanceof Source) {
                $source = new Source($adapter->key(), $adapter->jurisdiction(), $adapter->title());
                $this->entityManager->persist($source);
                ++$created;
            }

            $source->setTitle($adapter->title());
            $source->setCapabilities(array_map(
                static fn (SourceCapability $capability): string => $capability->value,
                $adapter->capabilities(),
            ));

            $enabled = $adapter->isEnabled();
            $source->setEnabled($enabled);

            // Only the two states that follow from configuration are set here; a "down" or "blocked"
            // state recorded by a real run must survive a restart.
            if (!$enabled) {
                $source->setHealth(SourceHealthState::Disabled);
            } elseif (SourceHealthState::Disabled === $source->healthState()) {
                $source->setHealth(SourceHealthState::Healthy);
            }
        }

        $this->entityManager->flush();

        return $created;
    }
}
