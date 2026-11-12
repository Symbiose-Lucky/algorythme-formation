<?php

namespace App\Entity;

use App\Repository\DomaineRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Stringable;

/**
 * Un domaine du catalogue (« Intelligence artificielle », « Data »…) : il
 * regroupe les formations sur le site public. La liste se gère dans l'admin
 * (/admin/domaines) ; une formation en choisit un.
 *
 * Renommer un domaine renomme celui de toutes ses formations. Un domaine
 * encore porté par une formation ne se supprime pas. L'historique des
 * formations garde le nom en texte, tel qu'il était à chaque version.
 */
#[ORM\Entity(repositoryClass: DomaineRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_DOMAINE_NOM', fields: ['nom'])]
#[UniqueEntity(fields: ['nom'], message: 'Ce domaine existe déjà.')]
class Domaine implements Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 120)]
    private string $nom = '';

    public function __construct(string $nom = '')
    {
        $this->setNom($nom);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        // Espaces en trop retirés, comme à la saisie d'une formation.
        $this->nom = trim(preg_replace('/\s+/u', ' ', (string)$nom));

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
