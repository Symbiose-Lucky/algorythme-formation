<?php

namespace App\Controller\Admin;

use App\Entity\Domaine;
use App\Entity\User;
use App\Form\DomaineType;
use App\Repository\DomaineRepository;
use App\Repository\FormationRepository;
use App\Service\HistoriqueFormations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La liste des domaines du catalogue, que le champ « Domaine » d'une
 * formation propose. On y ajoute, renomme et supprime les domaines.
 *
 * Renommer un domaine crée une nouvelle version de chacune de ses
 * formations : le nom affiché sur leur page change, l'historique le dit.
 * Un domaine porté par une formation (même archivée) ne se supprime pas.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/domaines', name: 'app_admin_domaine')]
class DomaineController extends AbstractController
{
    public function __construct(
        private readonly DomaineRepository      $domaines,
        private readonly FormationRepository    $formations,
        private readonly HistoriqueFormations   $historique,
        private readonly EntityManagerInterface $em,
    )
    {
    }

    /** La liste, avec en tête le formulaire d'ajout. */
    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $domaine = new Domaine();
        $form = $this->createForm(DomaineType::class, $domaine, [
            'action' => $this->generateUrl('app_admin_domaine'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($domaine);
            $this->em->flush();
            $this->addFlash('succes', sprintf('Domaine « %s » ajouté.', $domaine->getNom()));

            return $this->redirectToRoute('app_admin_domaine');
        }

        return $this->render('admin/domaine/index.html.twig', [
            'form' => $form,
            'lignes' => $this->domaines->avecNombreDeFormations(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/modifier', name: '_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Domaine $domaine, Request $request): Response
    {
        $ancien = $domaine->getNom();
        $form = $this->createForm(DomaineType::class, $domaine);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($domaine->getNom() === $ancien) {
                $this->addFlash('succes', 'Aucune modification à enregistrer.');

                return $this->redirectToRoute('app_admin_domaine');
            }

            $this->em->flush();

            // Une version par formation : le nom du domaine fait partie de
            // ce qui est publié, son changement doit figurer à l'historique.
            $formations = $this->formations->findBy(['domaine' => $domaine]);
            $motif = sprintf('Domaine renommé : « %s » devient « %s »', $ancien, $domaine->getNom());

            foreach ($formations as $formation) {
                $this->historique->enregistrer($formation, $this->auteur(), $motif);
            }

            $this->addFlash('succes', match (count($formations)) {
                0 => 'Domaine renommé.',
                1 => 'Domaine renommé, ainsi que celui de sa formation.',
                default => sprintf('Domaine renommé, ainsi que celui de ses %d formations.', count($formations)),
            });

            return $this->redirectToRoute('app_admin_domaine');
        }

        return $this->render('admin/domaine/modifier.html.twig', [
            'domaine' => $domaine,
            'ancien' => $ancien,
            'form' => $form,
            'formations' => $this->formations->count(['domaine' => $domaine]),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/supprimer', name: '_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Domaine $domaine, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_csrf_token'))) {
            $this->addFlash('erreur', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_admin_domaine');
        }

        $nombre = $this->formations->count(['domaine' => $domaine]);

        if ($nombre > 0) {
            $this->addFlash('erreur', sprintf(
                '« %s » est le domaine de %d formation%s : changez-le d\'abord sur %s.',
                $domaine->getNom(),
                $nombre,
                $nombre > 1 ? 's' : '',
                $nombre > 1 ? 'chacune' : 'elle',
            ));

            return $this->redirectToRoute('app_admin_domaine');
        }

        $this->em->remove($domaine);
        $this->em->flush();
        $this->addFlash('succes', sprintf('Domaine « %s » supprimé.', $domaine->getNom()));

        return $this->redirectToRoute('app_admin_domaine');
    }

    private function auteur(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
