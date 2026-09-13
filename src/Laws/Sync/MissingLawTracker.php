<?php

declare(strict_types=1);

namespace App\Laws\Sync;

use App\Core\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Counts how often a law was absent from a source (SPEC.md § 24.3).
 *
 * A law counts as repealed only after `sources.repeal_confirmations` consecutive successful runs
 * without it **and** a 404 on its URL. One bad response from the source must never be able to move
 * a law into `_repealed/`.
 */
final readonly class MissingLawTracker
{
    private const string PREFIX = 'sync.missing.';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Increases the counter and returns the new value.
     */
    public function recordMissing(string $sourceKey, string $slug): int
    {
        $setting = $this->setting($sourceKey, $slug);
        $count = (\is_int($setting->value()) ? $setting->value() : 0) + 1;

        $setting->setValue($count);
        $this->entityManager->flush();

        return $count;
    }

    public function count(string $sourceKey, string $slug): int
    {
        $value = $this->setting($sourceKey, $slug)->value();

        return \is_int($value) ? $value : 0;
    }

    /**
     * The law is back: the counter starts over.
     */
    public function reset(string $sourceKey, string $slug): void
    {
        $key = $this->key($sourceKey, $slug);
        $setting = $this->entityManager->find(Setting::class, $key);

        if ($setting instanceof Setting) {
            $this->entityManager->remove($setting);
            $this->entityManager->flush();
        }
    }

    private function setting(string $sourceKey, string $slug): Setting
    {
        $key = $this->key($sourceKey, $slug);
        $setting = $this->entityManager->find(Setting::class, $key);

        if (!$setting instanceof Setting) {
            $setting = new Setting($key, 0);
            $this->entityManager->persist($setting);
        }

        return $setting;
    }

    private function key(string $sourceKey, string $slug): string
    {
        return self::PREFIX.$sourceKey.'.'.$slug;
    }
}
