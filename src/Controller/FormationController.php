<?php

namespace App\Controller;

use App\Service\CatalogueFormations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/nos-formations', name: 'app_formation')]
class FormationController extends AbstractController
{
    public function __construct(
        private readonly CatalogueFormations $catalogue,
    )
    {
    }

    /**
     * Catalogue : les formations publiées dans l'admin, avec un filtre par
     * domaine (domaines triés par ordre alphabétique).
     */
    #[Route('', name: '')]
    public function index(): Response
    {
        return $this->render('front/formation/index.html.twig', [
            'domaines' => $this->catalogue->domaines(),
            'formations' => $this->catalogue->lister(),
        ]);
    }

    /**
     * Fiche d'une formation. Une formation en brouillon ou archivée n'a pas
     * de page : son adresse renvoie une 404.
     */
    #[Route('/{slug}', name: '_voir')]
    public function voir(string $slug): Response
    {
        $formation = $this->catalogue->fiche($slug);

        if ($formation === null) {
            throw $this->createNotFoundException(sprintf('Aucune formation publiée pour le slug « %s ».', $slug));
        }

        return $this->render('front/formation/voir.html.twig', [
            'formation' => $formation,
        ]);
    }
}
