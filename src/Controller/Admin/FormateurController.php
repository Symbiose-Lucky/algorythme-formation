<?php

namespace App\Controller\Admin;

use App\Entity\Formateur;
use App\Entity\Journalisable;
use App\Entity\SessionFormation;
use App\Form\FormateurType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/formateurs', name: 'app_admin_formateur')]
class FormateurController extends AbstractFicheController
{
    protected function classe(): string
    {
        return Formateur::class;
    }

    protected function formulaire(): string
    {
        return FormateurType::class;
    }

    protected function route(): string
    {
        return 'app_admin_formateur';
    }

    protected function libelles(): array
    {
        return [
            'pluriel' => 'Formateurs',
            'sousTitre' => 'Salariés et sous-traitants, avec leur parcours et les formations qu\'ils animent.',
            'nouveau' => 'Nouveau formateur',
            'modifier' => 'Modifier le formateur',
            'cree' => 'Formateur créé.',
            'vide' => 'Aucun formateur ici pour l\'instant.',
            'recherche' => 'Nom, email ou société',
        ];
    }

    protected function champsRecherche(): array
    {
        return ['f.nom', 'f.prenom', 'f.email', 'f.societe'];
    }

    protected function tri(): array
    {
        return ['f.nom' => 'ASC', 'f.prenom' => 'ASC'];
    }

    protected function colonnes(): array
    {
        return [
            'Statut' => static fn(Formateur $f) => $f->getStatut()->libelle(),
            'Email' => static fn(Formateur $f) => $f->getEmail(),
            'Téléphone' => static fn(Formateur $f) => $f->getTelephone(),
            'Formations animées' => static fn(Formateur $f) => (string)$f->getFormations()->count(),
        ];
    }

    protected function listes(Journalisable $fiche): array
    {
        $sessions = $this->em->createQueryBuilder()
            ->select('s')
            ->from(SessionFormation::class, 's')
            ->where(':formateur MEMBER OF s.formateurs')
            ->setParameter('formateur', $fiche)
            ->orderBy('s.dateDebut', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();

        return [[
            'titre' => 'Sessions animées',
            'vide' => 'Aucune session pour ce formateur.',
            'elements' => array_map(fn(SessionFormation $s) => [
                'texte' => $s->getReference() . ' · ' . $s->getFormation()->getNom(),
                'url' => $this->generateUrl('app_admin_session_voir', ['id' => $s->getId()]),
                'detail' => SessionController::resume($s),
            ], $sessions),
        ]];
    }
}
