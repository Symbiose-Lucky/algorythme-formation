<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Transforme un gabarit Twig (templates/pdf) en PDF A4, avec dompdf.
 *
 * dompdf ne comprend que du HTML et du CSS simples (pas de flexbox ni de
 * grille) : les gabarits sont mis en page avec des tableaux. La police
 * DejaVu Sans est embarquée, elle porte les accents et les puces.
 */
class GenerateurPdf
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.cache_dir%')]
        private readonly string      $cache,
        #[Autowire('%kernel.project_dir%')]
        private readonly string      $projet,
    )
    {
    }

    /**
     * @param array<string, mixed> $contexte
     *
     * @return array{contenu: string, pages: int}
     */
    public function generer(string $gabarit, array $contexte): array
    {
        // dompdf écrit ses métriques de police : dans le cache de l'application,
        // jamais dans vendor/, qui peut être en lecture seule en production.
        $dossier = $this->cache . '/dompdf';

        if (!is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache($dossier);
        $options->setTempDir($dossier);
        // Aucune ressource distante, aucun PHP dans les gabarits : les images sont embarquées.
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setChroot($this->projet . '/assets/images');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render($gabarit, $contexte + ['logo' => $this->logo()]), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return [
            'contenu' => (string)$dompdf->output(),
            'pages' => $dompdf->getCanvas()->get_page_count(),
        ];
    }

    /** Le logo, embarqué dans le document (data URL). */
    private function logo(): ?string
    {
        $fichier = $this->projet . '/assets/images/logo_algorythme_formation.png';

        return is_file($fichier) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($fichier)) : null;
    }
}
