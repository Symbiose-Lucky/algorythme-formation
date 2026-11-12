<?php

namespace App\Controller\Admin;

use App\Repository\FormationRepository;
use App\Service\Indicateurs;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Indicateurs de résultats : ce que l'outil a mesuré, par formation et au
 * total, et ce que le site en affiche.
 */
#[IsGranted('ROLE_ADMIN')]
class IndicateurController extends AbstractController
{
    #[Route('/admin/indicateurs', name: 'app_admin_indicateur', methods: ['GET'])]
    public function index(Request $request, Indicateurs $indicateurs, FormationRepository $formations): Response
    {
        $courante = (int)date('Y');
        $annee = $request->query->getInt('annee');
        $annee = $annee >= 2000 && $annee <= $courante + 1 ? $annee : null;

        $parFormation = $indicateurs->parFormation($annee);
        $lignes = [];

        foreach ($formations->findBy([], ['nom' => 'ASC']) as $formation) {
            $brut = $parFormation[$formation->getId()] ?? null;
            $publics = $indicateurs->publics($formation);

            // Sans activité ni chiffre affiché, la formation n'a rien à montrer ici.
            if ($brut === null && $publics['note'] === null && $publics['nbFormes'] === null) {
                continue;
            }

            $lignes[] = [
                'formation' => $formation,
                'mesure' => $indicateurs->lisible($brut ?? []),
                'publics' => $publics,
            ];
        }

        return $this->render('admin/indicateur/index.html.twig', [
            'annee' => $annee,
            'annees' => range($courante, $courante - 4),
            'lignes' => $lignes,
            'total' => $indicateurs->lisible($indicateurs->total($parFormation)),
            'seuil' => Indicateurs::SEUIL_PUBLICATION,
        ]);
    }
}
