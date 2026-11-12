<?php

namespace App\Entity;

/**
 * Une fiche de gestion (formateur, client, participant) dont chaque
 * modification est consignée dans le journal (EntreeJournal), et qui
 * s'archive au lieu de se supprimer.
 */
interface Journalisable
{
    public function getId(): ?int;

    /** Type sous lequel la fiche est consignée dans le journal : « formateur ». */
    public static function typeJournal(): string;

    /**
     * Les champs suivis par le journal, avec leur libellé.
     *
     * @return array<string, string>
     */
    public static function champs(): array;

    /**
     * L'état de la fiche tel que le journal le compare et l'affiche : une
     * valeur lisible par champ de champs().
     *
     * @return array<string, string|int|null>
     */
    public function instantane(): array;

    /** Le nom sous lequel la fiche apparaît dans les listes. */
    public function libelle(): string;

    public function isArchive(): bool;
}
