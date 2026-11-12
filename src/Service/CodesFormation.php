<?php

namespace App\Service;

use App\Entity\Formation;
use App\Repository\FormationRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Tire de l'intitulé d'une formation un code court et libre, de
 * Formation::CODE_MAX caractères au plus :
 *
 *   « Python - les fondamentaux »             → PYT-FON
 *   « LLM : déployer en local »               → LLM-DEP
 *   « Analyser ses données avec Power BI »    → ANA-DON
 *   « Kubernetes »                            → KUBERNE
 *
 * Les petits mots (de, la, avec…) sont ignorés ; un sigle de l'intitulé
 * (LLM, IA, GCP) est gardé entier. Si le code est pris, la fin cède la
 * place à un numéro : PYT-FO2, PYT-FO3…
 */
class CodesFormation
{
    private const array MOTS_VIDES = [
        'a', 'au', 'aux', 'avec', 'ce', 'ces', 'd', 'dans', 'de', 'des', 'du', 'en', 'et', 'l', 'la', 'le',
        'les', 'leur', 'leurs', 'ou', 'par', 'pour', 'sa', 'se', 'ses', 'son', 'sur', 'un', 'une', 'vos', 'votre',
    ];

    public function __construct(
        private readonly FormationRepository $formations,
    )
    {
    }

    /** Un code libre pour une formation de cet intitulé. */
    public function generer(string $intitule): string
    {
        $base = $this->proposer($intitule);

        if (!$this->pris($base)) {
            return $base;
        }

        for ($numero = 2; ; $numero++) {
            $code = rtrim(mb_substr($base, 0, Formation::CODE_MAX - strlen((string)$numero)), '-') . $numero;

            if (!$this->pris($code)) {
                return $code;
            }
        }
    }

    /** Le code tiré de l'intitulé, sans regarder s'il est libre. */
    public function proposer(string $intitule): string
    {
        $mots = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', (new AsciiSlugger())->slug($intitule, ' ')->toString(), -1, PREG_SPLIT_NO_EMPTY) as $mot) {
            if (!in_array(mb_strtolower($mot), self::MOTS_VIDES, true)) {
                $mots[] = $mot;
            }
        }

        if ($mots === []) {
            return 'FORM';
        }

        $mots = array_map('mb_strtoupper', $mots);

        if (count($mots) === 1) {
            return mb_substr($mots[0], 0, Formation::CODE_MAX);
        }

        // Un sigle (LLM, IA, M365) se garde entier, un mot se réduit à trois lettres.
        $premier = $this->estSigle($mots[0], $intitule) ? mb_substr($mots[0], 0, 4) : mb_substr($mots[0], 0, 3);

        return $premier . '-' . mb_substr($mots[1], 0, Formation::CODE_MAX - mb_strlen($premier) - 1);
    }

    private function estSigle(string $mot, string $intitule): bool
    {
        return mb_strlen($mot) <= 4 && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($mot, '/') . '(?![\p{L}\p{N}])/u', $intitule) === 1;
    }

    private function pris(string $code): bool
    {
        return $this->formations->findOneBy(['code' => $code]) !== null;
    }
}
