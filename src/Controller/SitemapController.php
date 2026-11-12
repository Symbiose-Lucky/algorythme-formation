<?php

namespace App\Controller;

use App\Service\CatalogueFormations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Plan du site pour les moteurs de recherche : les pages fixes et une
 * entrée par formation publiée. Déclaré dans robots.txt.
 *
 * Les adresses sont absolues et construites sur APP_URL, pas sur la
 * requête : derrière un reverse proxy, l'hôte vu par PHP n'est pas
 * toujours le domaine public.
 */
class SitemapController extends AbstractController
{
    /** Pages fixes : nom de route et importance relative pour les moteurs. */
    private const array PAGES = [
        'home' => '1.0',
        'app_formation' => '0.9',
        'app_domaines' => '0.8',
        'app_qui_sommes_nous' => '0.6',
        'app_contact' => '0.6',
        'app_mentions_legales' => '0.2',
        'app_donnees_personnelles' => '0.2',
    ];

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function index(CatalogueFormations $catalogue): Response
    {
        $base = rtrim((string)$this->getParameter('app_url'), '/');
        $entrees = [];

        foreach (self::PAGES as $route => $priorite) {
            $entrees[] = [
                'loc' => $base . $this->generateUrl($route),
                'priorite' => $priorite,
                'majLe' => null,
            ];
        }

        try {
            foreach ($catalogue->lister() as $formation) {
                $fiche = $catalogue->fiche($formation['slug']);

                $entrees[] = [
                    'loc' => $base . $this->generateUrl('app_formation_voir', ['slug' => $formation['slug']]),
                    'priorite' => '0.8',
                    'majLe' => $fiche['misAJourLe'] ?? null,
                ];
            }
        } catch (Throwable) {
            // Catalogue illisible (base injoignable) : les pages fixes suffisent, le plan reste valide.
        }

        $response = $this->render('sitemap.xml.twig', ['entrees' => $entrees]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
