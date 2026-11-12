<?php

namespace App\Controller\Admin;

use App\Entity\Formation;
use App\Entity\User;
use App\Enum\StatutFormation;
use App\Form\FormationType;
use App\Repository\FormationRepository;
use App\Repository\FormationVersionRepository;
use App\Repository\SessionFormationRepository;
use App\Service\HistoriqueFormations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des formations. Il n'y a pas de suppression : une formation
 * retirée du catalogue passe au statut « Archivée » et garde son
 * historique, que l'audit Qualiopi peut demander.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/formations', name: 'app_admin_formation')]
class FormationController extends AbstractController
{
    public function __construct(
        private readonly FormationRepository        $formations,
        private readonly FormationVersionRepository $versions,
        private readonly HistoriqueFormations       $historique,
    )
    {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Un statut inconnu dans l'URL est ignoré.
        $statut = StatutFormation::tryFrom($request->query->getString('statut'));
        $recherche = trim($request->query->getString('q'));

        return $this->render('admin/formation/index.html.twig', [
            'formations' => $this->formations->rechercher($statut, $recherche),
            'nombres' => $this->formations->compterParStatut(),
            'statut' => $statut,
            'recherche' => $recherche,
        ]);
    }

    #[Route('/nouvelle', name: '_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request): Response
    {
        $formation = new Formation();
        $form = $this->createForm(FormationType::class, $formation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->historique->enregistrer($formation, $this->auteur(), 'Création');
            $this->addFlash('succes', 'Formation créée.');

            return $this->redirectToRoute('app_admin_formation_voir', ['id' => $formation->getId()]);
        }

        return $this->render('admin/formation/form.html.twig', [
            'formation' => $formation,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: '_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Formation $formation, SessionFormationRepository $sessions): Response
    {
        return $this->render('admin/formation/voir.html.twig', [
            'formation' => $formation,
            'versions' => $this->versions->historique($formation),
            'sessions' => $sessions->deLaFormation($formation),
        ]);
    }

    #[Route('/{id}/modifier', name: '_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Formation $formation, Request $request): Response
    {
        $form = $this->createForm(FormationType::class, $formation, ['avec_motif' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $version = $this->historique->enregistrer($formation, $this->auteur(), $form->get('motif')->getData());

            $this->addFlash('succes', $version === null
                ? 'Aucune modification à enregistrer.'
                : sprintf('Modifications enregistrées (version %d).', $version->getNumero()));

            return $this->redirectToRoute('app_admin_formation_voir', ['id' => $formation->getId()]);
        }

        return $this->render('admin/formation/form.html.twig', [
            'formation' => $formation,
            'form' => $form,
        ]);
    }

    /** La formation telle qu'elle était à une version donnée. */
    #[Route('/{id}/versions/{numero}', name: '_version', requirements: ['id' => '\d+', 'numero' => '\d+'], methods: ['GET'])]
    public function version(Formation $formation, int $numero): Response
    {
        $version = $this->versions->findOneBy(['formation' => $formation, 'numero' => $numero]);

        if ($version === null) {
            throw $this->createNotFoundException(sprintf('Version %d introuvable.', $numero));
        }

        return $this->render('admin/formation/version.html.twig', [
            'formation' => $formation,
            'version' => $version,
            'champs' => Formation::CHAMPS,
            'statuts' => array_column(
                array_map(static fn(StatutFormation $s) => [$s->value, $s->libelle()], StatutFormation::cases()),
                1,
                0,
            ),
        ]);
    }

    private function auteur(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
