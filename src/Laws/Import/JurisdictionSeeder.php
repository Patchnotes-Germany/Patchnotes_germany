<?php

declare(strict_types=1);

namespace App\Laws\Import;

use App\Laws\Entity\Jurisdiction;
use App\Laws\Enum\JurisdictionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Makes sure the federation and the 16 states exist as rows (SPEC.md § 4.4).
 *
 * Jurisdictions are a fixed, small vocabulary: the codes appear in every norm reference, in URLs
 * and in the repository layout, so they are seeded rather than discovered.
 */
final readonly class JurisdictionSeeder
{
    /** ISO 3166-2:DE codes in lower case, with the official German names. */
    public const array JURISDICTIONS = [
        'bund' => 'Bund',
        'bw' => 'Baden-Württemberg',
        'by' => 'Bayern',
        'be' => 'Berlin',
        'bb' => 'Brandenburg',
        'hb' => 'Bremen',
        'hh' => 'Hamburg',
        'he' => 'Hessen',
        'mv' => 'Mecklenburg-Vorpommern',
        'ni' => 'Niedersachsen',
        'nw' => 'Nordrhein-Westfalen',
        'rp' => 'Rheinland-Pfalz',
        'sl' => 'Saarland',
        'sn' => 'Sachsen',
        'st' => 'Sachsen-Anhalt',
        'sh' => 'Schleswig-Holstein',
        'th' => 'Thüringen',
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return int the number of jurisdictions created
     */
    public function seed(): int
    {
        $created = 0;

        foreach (self::JURISDICTIONS as $code => $name) {
            if ($this->entityManager->find(Jurisdiction::class, $code) instanceof Jurisdiction) {
                continue;
            }

            $this->entityManager->persist(new Jurisdiction(
                $code,
                'bund' === $code ? JurisdictionType::Bund : JurisdictionType::Land,
                $name,
            ));
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return $created;
    }

    public function get(string $code): Jurisdiction
    {
        $jurisdiction = $this->entityManager->find(Jurisdiction::class, $code);

        if (!$jurisdiction instanceof Jurisdiction) {
            $this->seed();
            $jurisdiction = $this->entityManager->find(Jurisdiction::class, $code);
        }

        return $jurisdiction ?? throw new \InvalidArgumentException(\sprintf('Unknown jurisdiction "%s".', $code));
    }
}
