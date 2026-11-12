<?php

namespace App\Service;

use App\Entity\EntreeJournal;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écrit le journal des modifications (EntreeJournal) à partir de deux
 * instantanés d'un objet : son état avant et après l'enregistrement.
 */
class Journal
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    )
    {
    }

    /**
     * Consigne la création d'un objet : ses champs renseignés.
     *
     * @param array<string, mixed> $etat
     */
    public function creation(string $type, int $objetId, array $etat, ?User $auteur, ?string $motif = null): EntreeJournal
    {
        $changements = [];

        foreach ($etat as $champ => $valeur) {
            if (!in_array($valeur, [null, '', false], true)) {
                $changements[$champ] = ['avant' => null, 'apres' => $valeur];
            }
        }

        return $this->ecrire($type, $objetId, EntreeJournal::CREATION, $changements, $auteur, $motif);
    }

    /**
     * Consigne une modification. Renvoie null, sans rien écrire, si les
     * deux états sont identiques.
     *
     * @param array<string, mixed> $avant
     * @param array<string, mixed> $apres
     */
    public function modification(string $type, int $objetId, array $avant, array $apres, ?User $auteur, ?string $motif = null): ?EntreeJournal
    {
        $changements = [];

        foreach ($apres as $champ => $valeur) {
            $ancienne = $avant[$champ] ?? null;

            if ($ancienne !== $valeur) {
                $changements[$champ] = ['avant' => $ancienne, 'apres' => $valeur];
            }
        }

        if ($changements === []) {
            return null;
        }

        return $this->ecrire($type, $objetId, EntreeJournal::MODIFICATION, $changements, $auteur, $motif);
    }

    /**
     * @param array<string, array{avant: mixed, apres: mixed}> $changements
     */
    private function ecrire(string $type, int $objetId, string $action, array $changements, ?User $auteur, ?string $motif): EntreeJournal
    {
        $motif = trim((string)$motif);

        $entree = new EntreeJournal(
            $type,
            $objetId,
            $action,
            $changements,
            $auteur,
            $motif === '' ? null : mb_substr($motif, 0, 255),
        );

        $this->em->persist($entree);
        $this->em->flush();

        return $entree;
    }
}
