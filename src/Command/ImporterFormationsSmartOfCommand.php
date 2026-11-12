<?php

namespace App\Command;

use App\Entity\Domaine;
use App\Entity\Formation;
use App\Enum\StatutFormation;
use App\Repository\DomaineRepository;
use App\Repository\FormationRepository;
use App\Service\HistoriqueFormations;
use App\Service\SmartOfApiService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Reprend dans la base du site les formations saisies dans SmartOF.
 *
 *     php bin/console formations:importer-smartof
 *     php bin/console formations:importer-smartof --simuler
 *
 * Une formation déjà importée (même identifiant SmartOF) n'est jamais
 * retouchée : les modifications faites depuis dans l'admin ne risquent
 * rien, et la commande peut être relancée sans danger.
 *
 * Champs personnalisés SmartOF : 1 = en page d'accueil, 2 = accroche,
 * 3 = personnes formées, 4 = domaine, 5 = note, 7 = « ce que ça change ».
 */
#[AsCommand(
    name: 'formations:importer-smartof',
    description: 'Importe les formations de SmartOF qui ne sont pas encore dans la base du site',
)]
class ImporterFormationsSmartOfCommand extends Command
{
    private const string MOTIF = 'Import depuis SmartOF';

    public function __construct(
        private readonly SmartOfApiService    $smartOf,
        private readonly FormationRepository  $formations,
        private readonly HistoriqueFormations $historique,
        private readonly SluggerInterface     $slugger,
        private readonly EntityManagerInterface $em,
        private readonly DomaineRepository    $domaines,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('simuler', null, InputOption::VALUE_NONE, "Liste ce qui serait importé, sans rien écrire");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simuler = (bool)$input->getOption('simuler');

        $produits = $this->smartOf->callSmartofApi('/api/produit/list')['produits'] ?? [];
        $lignes = [];
        $importees = 0;

        foreach ($produits as $produit) {
            $uid = (string)($produit['produitFormationUid'] ?? '');
            $formation = $this->formation($produit);

            if ($uid === '' || $formation->getNom() === '') {
                continue;
            }

            if ($this->formations->findOneBy(['smartofUid' => $uid]) !== null) {
                $lignes[] = [$formation->getCode(), $formation->getNom(), 'déjà importée'];
                continue;
            }

            // Un code ou une adresse déjà pris par une formation créée dans
            // l'admin : on ne l'écrase pas, on le signale.
            if ($this->formations->findOneBy(['code' => $formation->getCode()]) !== null
                || $this->formations->findOneBy(['slug' => $formation->getSlug()]) !== null) {
                $lignes[] = [$formation->getCode(), $formation->getNom(), '<error>ignorée : code ou adresse déjà utilisé</error>'];
                continue;
            }

            if (!$simuler) {
                // Un domaine de SmartOF absent de la liste y est ajouté.
                if ($formation->getDomaine() !== null && $formation->getDomaine()->getId() === null) {
                    $this->em->persist($formation->getDomaine());
                }

                $this->historique->enregistrer($formation, null, self::MOTIF);

                // La version 1 est datée de l'import ; la formation, elle,
                // garde les dates de SmartOF.
                $creeLe = $this->date($produit['createdAt'] ?? null);
                $formation->reprendreDates($creeLe, $this->date($produit['updatedAt'] ?? null, $creeLe));
                $this->em->flush();
            }

            $lignes[] = [$formation->getCode(), $formation->getNom(), $formation->getStatut()->libelle()];
            $importees++;
        }

        $io->table(['Code', 'Formation', 'Résultat'], $lignes);
        $io->success(sprintf(
            '%d formation(s) %s sur %d produit(s) SmartOF.',
            $importees,
            $simuler ? 'à importer' : 'importée(s)',
            count($produits),
        ));

        return Command::SUCCESS;
    }

    /** @var array<string, Domaine> Les domaines créés pendant cet import, par nom en minuscules. */
    private array $nouveauxDomaines = [];

    /** Le domaine de ce nom dans la liste de l'admin ; créé s'il n'y est pas encore. */
    private function domaine(string $nom): ?Domaine
    {
        $nom = trim(preg_replace('/\s+/u', ' ', $nom));

        if ($nom === '') {
            return null;
        }

        return $this->domaines->parNomSouple($nom)
            ?? ($this->nouveauxDomaines[mb_strtolower($nom)] ??= new Domaine($nom));
    }

    /**
     * @param array<string, mixed> $produit
     */
    private function formation(array $produit): Formation
    {
        $description = $produit['description'] ?? [];
        $champs = $produit['custom_fields'] ?? [];

        $nom = trim((string)($description['intituleDeLaFormation'] ?? ''))
            ?: trim((string)($produit['meta']['nom'] ?? ''));

        // Même calcul que l'ancien catalogue public : l'adresse de la page ne change pas.
        $slug = $this->slugger->slug($nom)->lower()->toString();

        $archive = ($produit['archived'] ?? false) === true;
        $actif = ($produit['meta']['status'] ?? '') === 'Actif';

        $heures = (float)($description['dureeDeLaFormation'] ?? 0);
        $note = (float)str_replace(',', '.', trim((string)($champs['custom_field_5'] ?? '')));
        $nbFormes = (int)preg_replace('/\D/', '', (string)($champs['custom_field_3'] ?? ''));
        $tarifs = $this->tarifs($produit);

        return (new Formation())
            ->setSmartofUid((string)($produit['produitFormationUid'] ?? ''))
            ->setNom($nom)
            ->setSlug($slug)
            ->setCode(trim((string)($produit['customId'] ?? '')) ?: mb_substr($slug, 0, 40))
            ->setStatut(match (true) {
                $archive => StatutFormation::Archivee,
                $actif => StatutFormation::Publiee,
                default => StatutFormation::Brouillon,
            })
            ->setDomaine($this->domaine((string)($champs['custom_field_4'] ?? '')))
            ->setAccroche($champs['custom_field_2'] ?? '')
            ->setDureeHeures($heures > 0 ? $heures : null)
            ->setLieu($description['lieuDeLaFormation'] ?? '')
            ->setTarifInterHT($tarifs['inter'])
            ->setTarifIntraHT($tarifs['intra'])
            ->setObjectifs(($description['objectifsDeLaFormation'] ?? '') ?: ($description['objectifsPedagogiques'] ?? ''))
            ->setPublicVise($description['publicVise'] ?? '')
            ->setPrerequis($description['preRequis'] ?? '')
            ->setProgramme($description['contenuDeLaFormation'] ?? '')
            ->setCeQuiChange($champs['custom_field_7'] ?? '')
            ->setMethodes($description['modalitesPedagogiques'] ?? '')
            ->setMoyens($description['moyensEtSupportsPedagogiques'] ?? '')
            ->setEvaluation($description['modalitesDEvaluationEtDeSuivi'] ?? '')
            ->setAcces($description['modalitesDAccesALaFormation'] ?? '')
            ->setProfilFormateurs($description['profilDuOuDesFormateurs'] ?? '')
            ->setNote($note > 0 ? min($note, 5.0) : null)
            ->setNbFormes($nbFormes > 0 ? $nbFormes : null);
    }

    /**
     * Une date ISO de SmartOF (UTC), ramenée au fuseau du serveur comme
     * les autres dates de la base. Illisible ou absente : la date de repli.
     */
    private function date(mixed $iso, ?DateTimeImmutable $repli = null): DateTimeImmutable
    {
        try {
            if (is_string($iso) && $iso !== '') {
                return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()));
            }
        } catch (\Exception) {
        }

        return $repli ?? new DateTimeImmutable();
    }

    /**
     * Les tarifs SmartOF sont des totaux de session (prix unitaire ×
     * quantité), reconnus à leur intitulé « INTER » / « INTRA ». Un tarif
     * sans formule précisée est pris pour l'inter, comme sur le site.
     *
     * @param array<string, mixed> $produit
     *
     * @return array{inter: ?int, intra: ?int} En centimes.
     */
    private function tarifs(array $produit): array
    {
        $tarifs = ['inter' => null, 'intra' => null];

        foreach ($produit['presetTarification']['tarifs'] ?? [] as $tarif) {
            $montant = 0.0;

            foreach ($tarif['budget'] ?? [] as $ligne) {
                $montant += (float)($ligne['prixUnitaireHT'] ?? 0) * (float)($ligne['quantite'] ?? 1);
            }

            if ($montant <= 0) {
                continue;
            }

            $intitule = mb_strtolower((string)($tarif['intitule'] ?? ''));
            $formule = str_contains($intitule, 'intra') ? 'intra' : 'inter';

            $tarifs[$formule] ??= (int)round($montant * 100);
        }

        return $tarifs;
    }
}
