<?php

namespace App\Controller\Admin;

use App\Controller\EspaceFormateurController;
use App\Entity\Formateur;
use App\Entity\Journalisable;
use App\Entity\SessionFormation;
use App\Form\FormateurType;
use App\Service\Jetons;
use App\Service\MailService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/formateurs', name: 'app_admin_formateur')]
class FormateurController extends AbstractFicheController
{
    private Jetons $jetons;

    #[Required]
    public function setJetons(Jetons $jetons): void
    {
        $this->jetons = $jetons;
    }

    /** Envoie au formateur le lien de son espace personnel. */
    #[Route('/{id}/espace/envoyer', name: '_espace_envoyer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function envoyerEspace(int $id, Request $request, MailService $mails): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $formateur = $this->fiche($id);
        assert($formateur instanceof Formateur);

        if ($formateur->isArchive() || $formateur->getEmail() === '') {
            $this->addFlash('erreur', "Ce formateur est archivé ou n'a pas d'adresse email : rien n'a été envoyé.");
        } else {
            try {
                $mails->sendLienEspaceFormateur($formateur->getEmail(), $this->lienEspace($formateur));
                $this->addFlash('succes', 'Lien de son espace envoyé à ' . $formateur->getEmail() . '.');
            } catch (Throwable) {
                $this->addFlash('erreur', "L'envoi de l'email a échoué.");
            }
        }

        return $this->redirectToRoute('app_admin_formateur_voir', ['id' => $id]);
    }

    protected function complement(Journalisable $fiche): ?string
    {
        assert($fiche instanceof Formateur);

        // Un formateur archivé n'a plus d'espace.
        return $fiche->isArchive() ? null : $this->renderView('admin/formateur/_espace.html.twig', [
            'formateur' => $fiche,
            'lien' => $this->lienEspace($fiche),
        ]);
    }

    private function lienEspace(Formateur $formateur): string
    {
        return $this->generateUrl('app_espace_formateur', [
            'id' => $formateur->getId(),
            'jeton' => $this->jetons->pour(EspaceFormateurController::sujet($formateur)),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

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
