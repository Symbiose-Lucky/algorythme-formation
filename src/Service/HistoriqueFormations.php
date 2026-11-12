<?php

namespace App\Service;

use App\Entity\Formation;
use App\Entity\FormationVersion;
use App\Entity\User;
use App\Repository\FormationVersionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seul point d'écriture des formations : tout enregistrement passe par
 * ici, et tout enregistrement qui change quelque chose laisse une
 * FormationVersion. Aucune modification n'échappe ainsi à l'historique.
 */
class HistoriqueFormations
{
    public function __construct(
        private readonly EntityManagerInterface     $em,
        private readonly FormationVersionRepository $versions,
    )
    {
    }

    /**
     * Enregistre la formation. Renvoie la version créée, ou null si rien
     * n'a changé depuis la dernière.
     */
    public function enregistrer(Formation $formation, ?User $auteur, ?string $motif = null): ?FormationVersion
    {
        $instantane = $formation->instantane();
        $precedente = $formation->getId() === null ? null : $this->versions->derniere($formation);

        $modifications = $precedente === null ? [] : $this->differences($precedente->getContenu(), $instantane);

        if ($precedente !== null && $modifications === []) {
            return null;
        }

        $date = new DateTimeImmutable();
        $motif = trim((string)$motif);

        $version = new FormationVersion(
            $formation,
            $formation->nouvelleVersion($date),
            $instantane,
            $modifications,
            $auteur,
            $motif === '' ? null : mb_substr($motif, 0, 255),
            $date,
        );

        $this->em->persist($formation);
        $this->em->persist($version);
        $this->em->flush();

        return $version;
    }

    /**
     * Les champs dont la valeur diffère entre deux instantanés. Un champ
     * absent de l'ancien (ajouté au modèle depuis) compte s'il est renseigné.
     *
     * @param array<string, mixed> $avant
     * @param array<string, mixed> $apres
     *
     * @return list<string>
     */
    private function differences(array $avant, array $apres): array
    {
        $champs = [];

        foreach ($apres as $champ => $valeur) {
            $ancienne = $avant[$champ] ?? null;

            // Un nombre relu depuis le JSON peut revenir entier (21) là où
            // la formation porte un flottant (21.0) : ce n'est pas un changement.
            $identique = $ancienne === $valeur
                || (is_numeric($ancienne) && is_numeric($valeur) && (float)$ancienne === (float)$valeur)
                || (!array_key_exists($champ, $avant) && in_array($valeur, [null, '', false], true));

            if (!$identique) {
                $champs[] = $champ;
            }
        }

        return $champs;
    }
}
