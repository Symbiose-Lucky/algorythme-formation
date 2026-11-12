<?php

namespace App\Service;

use App\Entity\Formation;
use App\Repository\FormationRepository;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Catalogue public des formations, construit à partir des formations
 * publiées dans l'admin (/admin/formations).
 *
 * Ce service fait la traduction entre la fiche saisie (textes à puces,
 * programme en lignes) et ce que les gabarits attendent (listes, déroulé
 * par journée). Une formation en brouillon ou archivée n'apparaît nulle
 * part sur le site, y compris par son adresse directe.
 */
class CatalogueFormations
{
    /** Durée d'une journée de formation, pour afficher les jours à côté des heures. */
    private const int HEURES_PAR_JOUR = 7;

    /** Formules de session : le gabarit s'en sert pour libeller chaque tarif. */
    private const string FORMULE_INTER = 'inter';
    private const string FORMULE_INTRA = 'intra';

    /**
     * Les formations publiées, lues une seule fois par requête : une page
     * demande souvent la liste et les domaines.
     *
     * @var list<Formation>|null
     */
    private ?array $publiees = null;

    public function __construct(
        private readonly FormationRepository $formations,
        private readonly SluggerInterface    $slugger,
    )
    {
    }

    /**
     * Les formations publiées, triées par adresse.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lister(): array
    {
        return array_map($this->resume(...), $this->publiees());
    }

    /**
     * Le catalogue tel qu'un conseiller a besoin de le lire pour rapprocher
     * une formation d'un besoin exprimé : ce que la formation apprend, à qui
     * elle s'adresse, ce qu'elle suppose déjà acquis.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pourOrientation(): array
    {
        $catalogue = [];

        foreach ($this->publiees() as $formation) {
            $resume = $this->resume($formation);

            $catalogue[] = [
                'slug' => $resume['slug'],
                'nom' => $resume['nom'],
                'domaine' => $resume['domaine']['nom'] ?? '',
                'sousDomaine' => $resume['sousDomaine'],
                'accroche' => $resume['accroche'],
                'duree' => $resume['dureeJours'] ?: $resume['duree'],
                'objectifs' => $this->puces($formation->getObjectifs()),
                'publicVise' => $this->puces($formation->getPublicVise()),
                'preRequis' => $this->puces($formation->getPrerequis()),
            ];
        }

        return $catalogue;
    }

    /**
     * Les domaines des formations publiées, dédoublonnés et triés par ordre
     * alphabétique. Le slug est ASCII et en minuscules : le tri ignore donc
     * les accents et la casse.
     *
     * @return array<string, array{slug: string, nom: string}>
     */
    public function domaines(): array
    {
        $domaines = [];

        foreach ($this->lister() as $formation) {
            if ($formation['domaine'] !== null) {
                $domaines[$formation['domaine']['slug']] = $formation['domaine'];
            }
        }

        ksort($domaines);

        return $domaines;
    }

    /**
     * La fiche complète d'une formation publiée, ou null si l'adresse ne
     * correspond à aucune.
     *
     * Les modalités laissées vides dans l'admin donnent des listes vides :
     * le gabarit affiche alors ses textes communs à toutes les formations.
     *
     * @return array<string, mixed>|null
     */
    public function fiche(string $slug): ?array
    {
        foreach ($this->publiees() as $formation) {
            if ($formation->getSlug() !== $slug) {
                continue;
            }

            return $this->resume($formation) + [
                    'objectifs' => $this->puces($formation->getObjectifs()),
                    'objectifsPedagogiques' => [],
                    'ceQuiChange' => $this->ceQuiChange($formation->getCeQuiChange()),
                    'deroule' => $this->deroule($formation->getProgramme()),
                    'publicVise' => $this->puces($formation->getPublicVise()),
                    'preRequis' => $this->puces($formation->getPrerequis()),
                    'methodes' => $this->puces($formation->getMethodes()),
                    'moyens' => $this->puces($formation->getMoyens()),
                    'evaluation' => $this->puces($formation->getEvaluation()),
                    'acces' => $this->puces($formation->getAcces()),
                    'accessibilite' => $this->puces($formation->getAccessibilite()),
                    'profilFormateurs' => $this->puces($formation->getProfilFormateurs()),
                    'lieu' => $formation->getLieu(),
                    // L'effectif des sessions est le même pour toutes les formations :
                    // il est écrit dans le gabarit.
                    'misAJourLe' => $formation->getModifieLe(),
                ];
        }

        return null;
    }

    /**
     * @return list<Formation>
     */
    private function publiees(): array
    {
        if ($this->publiees === null) {
            $this->publiees = $this->formations->publiees();

            // Tri sur l'adresse, octet par octet : le même ordre partout,
            // quelle que soit la collation de la base.
            usort($this->publiees, static fn(Formation $a, Formation $b) => strcmp($a->getSlug(), $b->getSlug()));
        }

        return $this->publiees;
    }

    /**
     * Ce que la carte du catalogue affiche, et que la fiche complète reprend.
     *
     * @return array<string, mixed>
     */
    private function resume(Formation $formation): array
    {
        $domaine = $formation->getDomaine();
        $tarifs = $this->tarifs($formation);

        return [
            'id' => $formation->getId(),
            'slug' => $formation->getSlug(),
            'nom' => $formation->getNom(),
            'domaine' => $domaine === '' ? null : [
                'slug' => $this->slugger->slug($domaine)->lower()->toString(),
                'nom' => $domaine,
            ],
            // Les sous-domaines n'existent plus ; la clé reste pour les
            // gabarits et l'orientation, qui la lisent encore.
            'sousDomaine' => '',
            'accroche' => $formation->getAccroche(),
            'duree' => $this->dureeAffichee($formation->getDureeHeures()),
            'dureeJours' => $this->dureeEnJours($formation->getDureeHeures()),
            // La modalité (présentiel / distanciel) est la même pour toutes
            // nos formations : elle est écrite dans le gabarit.
            'tarifs' => $tarifs,
            // Coût affiché sur la carte : la formule inter, sinon l'intra.
            'tarifSessionHT' => $tarifs[0]['montantHT'] ?? null,
            // 0 signifie « pas encore de note », pas une mauvaise note.
            'note' => $formation->getNote() > 0 ? $formation->getNote() : null,
            'nbFormes' => $formation->getNbFormes() > 0 ? $formation->getNbFormes() : null,
        ];
    }

    /**
     * Les tarifs de session, l'inter d'abord. Les montants sont en euros.
     *
     * @return array<int, array{formule: string, intitule: string, montantHT: float}>
     */
    private function tarifs(Formation $formation): array
    {
        $tarifs = [];

        foreach ([
                     self::FORMULE_INTER => $formation->getTarifInterHT(),
                     self::FORMULE_INTRA => $formation->getTarifIntraHT(),
                 ] as $formule => $centimes) {
            if ($centimes > 0) {
                $tarifs[] = [
                    'formule' => $formule,
                    'intitule' => mb_strtoupper($formule),
                    'montantHT' => $centimes / 100,
                ];
            }
        }

        return $tarifs;
    }

    /**
     * « 21 heures, 3 jours ». Les jours ne sont précisés que s'ils tombent
     * juste.
     */
    private function dureeAffichee(?float $heures): string
    {
        if ($heures === null || $heures <= 0) {
            return '';
        }

        $duree = rtrim(rtrim(number_format($heures, 1, ',', ' '), '0'), ',') . ' heures';

        $jours = $heures / self::HEURES_PAR_JOUR;

        if ($jours >= 1 && floor($jours) === $jours) {
            $duree .= sprintf(', %d jour%s', $jours, $jours > 1 ? 's' : '');
        }

        return $duree;
    }

    /**
     * Durée exprimée en journées : « 3 jours ». La fiche formation l'affiche
     * à la place des heures, plus parlant pour un commanditaire.
     *
     * Null quand le nombre d'heures ne tombe pas sur une journée ou une
     * demi-journée entière : mieux vaut alors s'en tenir aux heures.
     */
    private function dureeEnJours(?float $heures): ?string
    {
        if ($heures === null || $heures <= 0) {
            return null;
        }

        $jours = $heures / self::HEURES_PAR_JOUR;

        if (abs($jours * 2 - round($jours * 2)) > 0.001) {
            return null;
        }

        $jours = round($jours * 2) / 2;

        return rtrim(rtrim(number_format($jours, 1, ',', ' '), '0'), ',')
            . ' jour' . ($jours >= 2 ? 's' : '');
    }

    /**
     * Les champs longs sont des textes à puces saisis à la main.
     * Chaque ligne devient un élément de liste, la puce en moins.
     *
     * @return array<int, string>
     */
    public function puces(mixed $texte): array
    {
        $lignes = preg_split('/\R/', trim((string)$texte)) ?: [];

        $items = [];

        foreach ($lignes as $ligne) {
            $ligne = trim(preg_replace('/^\s*[•◦▪\-–*]\s*/u', '', $ligne) ?? '');

            if ($ligne !== '') {
                $items[] = $ligne;
            }
        }

        return $items;
    }

    /**
     * « Ce que ça change, concrètement » :
     *
     *     ◦ Ajouter un formulaire                      ← situation de travail
     *     Aujourd'hui : Validation dispersée…          ← avant la formation
     *     Après : Un FormType, des contraintes…        ← après la formation
     *
     * Une situation sans ses deux états n'est pas affichée : la comparaison
     * n'aurait pas de sens.
     *
     * @return array<int, array{tache: string, avant: string, apres: string}>
     */
    private function ceQuiChange(string $saisie): array
    {
        $paires = [];
        $paire = null;

        foreach (preg_split('/\R/', trim($saisie)) ?: [] as $ligne) {
            $ligne = trim($ligne);

            if (str_starts_with($ligne, '◦')) {
                $paires[] = ['tache' => trim(ltrim($ligne, "◦ \t")), 'avant' => '', 'apres' => ''];
                $paire = array_key_last($paires);

                continue;
            }

            if ($paire !== null && preg_match("/^(aujourd['’]hui|après|apres)\s*:\s*(.+)$/ui", $ligne, $etat)) {
                $cle = str_starts_with(mb_strtolower($etat[1]), 'ap') ? 'apres' : 'avant';
                $paires[$paire][$cle] = trim($etat[2]);
            }
        }

        return array_values(array_filter(
            $paires,
            static fn(array $p) => $p['tache'] !== '' && $p['avant'] !== '' && $p['apres'] !== '',
        ));
    }

    /**
     * Déroulé pédagogique, tel qu'il est saisi :
     *
     *     JOUR 1 – Découverte du framework   ← journée (facultatif)
     *     ◦ Introduction (1h30)              ← séquence
     *     • Présentation de l'écosystème     ← point abordé
     *
     * Les formations saisies sans ligne « JOUR » donnent une seule journée
     * sans titre, qui contient toutes les séquences.
     *
     * @return array<int, array{titre: ?string, duree: ?string, sequences: array<int, array{titre: string, duree: ?string, points: array<int, string>}>}>
     */
    public function deroule(string $contenu): array
    {
        $journees = [];

        // Indices de la journée et de la séquence en cours de remplissage :
        // chaque ligne vient s'ajouter à la dernière ouverte.
        $journee = null;
        $sequence = null;

        foreach (preg_split('/\R/', trim($contenu)) ?: [] as $ligne) {
            $ligne = trim($ligne);

            if ($ligne === '') {
                continue;
            }

            if (preg_match('/^(JOUR\s*\d+)\s*[–:.-]?\s*(.*)$/ui', $ligne, $titre)) {
                $journees[] = [
                    'titre' => rtrim($titre[1] . ' - ' . $titre[2], ' -'),
                    'duree' => null,
                    'sequences' => [],
                ];

                $journee = array_key_last($journees);
                $sequence = null;

                continue;
            }

            // Hors ligne « JOUR », tout est rattaché à une journée sans titre.
            if ($journee === null) {
                $journees[] = ['titre' => null, 'duree' => null, 'sequences' => []];
                $journee = array_key_last($journees);
            }

            if (str_starts_with($ligne, '◦')) {
                $journees[$journee]['sequences'][] = $this->sequence(ltrim($ligne, "◦ \t"));
                $sequence = array_key_last($journees[$journee]['sequences']);

                continue;
            }

            $point = trim(preg_replace('/^\s*[•▪\-–*]\s*/u', '', $ligne) ?? '');

            if ($point === '') {
                continue;
            }

            // Un point sans séquence ouverte (contenu saisi sans « ◦ ») forme
            // une séquence à lui seul : aucune ligne n'est perdue.
            if ($sequence === null) {
                $journees[$journee]['sequences'][] = $this->sequence($point);
                $sequence = array_key_last($journees[$journee]['sequences']);

                continue;
            }

            $journees[$journee]['sequences'][$sequence]['points'][] = $point;
        }

        foreach ($journees as $index => $journeeSaisie) {
            $journees[$index]['duree'] = $this->dureeTotale($journeeSaisie['sequences']);
        }

        // Une journée sans séquence (ligne « JOUR » orpheline) n'a rien à montrer.
        return array_values(array_filter($journees, static fn(array $j) => $j['sequences'] !== []));
    }

    /**
     * Une séquence du déroulé, dont la durée est notée entre parenthèses en
     * fin d'intitulé : « Introduction à Symfony (1h30) ».
     *
     * @return array{titre: string, duree: ?string, points: array<int, string>}
     */
    private function sequence(string $intitule): array
    {
        $intitule = trim($intitule);
        $duree = null;

        if (preg_match('/^(.*?)\s*\(([^()]*\d[^()]*)\)$/u', $intitule, $trouve)) {
            $intitule = trim($trouve[1]);
            $duree = $this->dureeLisible($trouve[2]);
        }

        return ['titre' => $intitule, 'duree' => $duree, 'points' => []];
    }

    /**
     * « 1h30 » saisi dans l'admin s'affiche « 1 h 30 », « 2h » devient « 2 h ».
     */
    private function dureeLisible(string $duree): ?string
    {
        $minutes = $this->dureeEnMinutes($duree);

        if ($minutes === null) {
            return trim($duree) ?: null;
        }

        $heures = intdiv($minutes, 60);
        $reste = $minutes % 60;

        if ($heures === 0) {
            return $reste . ' min';
        }

        return $reste === 0 ? $heures . ' h' : sprintf('%d h %02d', $heures, $reste);
    }

    /**
     * @param array<int, array{duree: ?string}> $sequences
     */
    private function dureeTotale(array $sequences): ?string
    {
        $minutes = 0;

        foreach ($sequences as $sequence) {
            $minutes += $this->dureeEnMinutes((string)$sequence['duree']) ?? 0;
        }

        return $minutes > 0 ? $this->dureeLisible(sprintf('%dh%02d', intdiv($minutes, 60), $minutes % 60)) : null;
    }

    /**
     * Accepte les notations rencontrées dans les programmes : « 1h30 », « 2 h »,
     * « 45 min », « 1 h 30 ».
     */
    private function dureeEnMinutes(string $duree): ?int
    {
        if (preg_match('/(\d+)\s*h\s*(\d+)?/ui', $duree, $trouve)) {
            return (int)$trouve[1] * 60 + (int)($trouve[2] ?? 0);
        }

        if (preg_match('/(\d+)\s*(?:min|mn)/ui', $duree, $trouve)) {
            return (int)$trouve[1];
        }

        return null;
    }
}
