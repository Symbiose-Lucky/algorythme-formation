#tiny_mce : clé à changer dans base_admin

# Mise en production

À faire au prochain déploiement, dans cet ordre. Cette liste couvre le passage de SmartOF à la
gestion interne (formations, sessions) ; la compléter à chaque nouveau module.

## Avant de déployer

1. **Sauvegarder la base de production.** Les migrations créent des tables et changent le moteur
   de la table `user` ; sous MySQL, ces opérations ne s'annulent pas en cas d'échec à mi-chemin.
2. Vérifier que `SMARTOF_EMAIL`, `SMARTOF_PASSWORD` et `SMARTOF_API_KEY` sont encore renseignées
   en production : l'import des formations (étape 5) s'en sert une dernière fois.

3 bis. **Dossier des documents.** Les fichiers déposés dans l'admin (conventions signées, pièces des
   formateurs…) sont rangés hors du dossier public, dans `APP_DOSSIER_DOCUMENTS` (par défaut
   `var/documents`). En production, renseigner dans `.env.local` un dossier **conservé entre deux
   déploiements, inscriptible par PHP et inclus dans les sauvegardes** : ce sont des preuves pour
   l'audit. Vérifier aussi `upload_max_filesize` et `post_max_size` (20 Mo au moins).
4 bis. Vérifier que `APP_URL` et `DEFAULT_URI` valent l'adresse publique du site : les liens d'émargement
   et de signature envoyés par email sont construits dessus.

4 ter. **Extensions PHP** : `gd` et `mbstring` doivent être actives (génération des PDF avec dompdf).

## Déploiement

3. Déployer le code, puis `composer install --no-dev --optimize-autoloader`.
4. Lancer les migrations :

       php bin/console doctrine:migrations:migrate

   Si une migration échoue sur une clé étrangère (erreur MySQL 1824), c'est qu'une ancienne table
   est en MyISAM : la passer en InnoDB (`ALTER TABLE … ENGINE = InnoDB`), supprimer les tables
   créées à moitié par la migration, puis relancer.
5. **Importer les formations de SmartOF avant de rouvrir le site** : tant que ce n'est pas fait,
   le catalogue public est vide.

       php bin/console formations:importer-smartof --simuler   # contrôle, n'écrit rien
       php bin/console formations:importer-smartof

   La commande peut être relancée sans risque : une formation déjà importée n'est jamais retouchée.
6. Vider le cache : `php bin/console cache:clear`.

## Après le déploiement

7. **Retirer du cron la ligne `catalogue:rafraichir`** : la commande n'existe plus (le catalogue
   se lit en base, sans cache) et échouerait toutes les 8 minutes.
8. Contrôler le site : `/nos-formations` (16 formations), une fiche, `/sitemap.xml`,
   puis dans l'admin : formations, sessions, formateurs, clients, participants.
   Déposer un PDF de test sur une session, placer une zone, l'envoyer à soi-même et le signer ;
   ouvrir un lien d'émargement depuis un téléphone.
8 bis. **Fiche de l'organisme** (`/admin/organisme`) : la base de production part d'une fiche vide. Y
   saisir l'adresse, le SIRET, le numéro de déclaration d'activité, le représentant légal, le taux de
   TVA, les conditions de règlement et d'annulation, puis tracer la signature du représentant. Sans
   cela, ni convention ni certificat de réalisation ne peuvent être générés.
8 ter. **Évaluations** : sur une session de test terminée, ouvrir le lien personnel d'un participant
   (questionnaires de positionnement et de satisfaction) et celui du formateur (évaluation des acquis),
   puis générer une attestation de fin de formation. Ouvrir aussi le lien d'avis d'un client et le
   bilan du formateur (écran « Évaluations » de la session, tableau « Clients et formateurs »). Les questionnaires s'ouvrent seuls selon les dates
   des séances ; il n'y a pas de cron : les relances par email partent du bouton de l'écran
   « Évaluations » de la session (le questionnaire à froid s'ouvre 60 jours après la dernière séance).
8 quater. **Envoi des documents** : sur la session de test, générer une convocation pour un participant
   dont l'adresse email est la vôtre, cliquer sur « Envoyer par email » et vérifier la pièce jointe reçue.
8 quinquies. **Indicateurs** : la note et le nombre de personnes formées saisis sur chaque fiche deviennent
   l'historique d'avant l'outil ; ce que l'outil mesure s'y ajoute sur le site. Ne plus augmenter ces deux
   champs à la main une fois les sessions gérées ici. Contrôler `/admin/indicateurs`.
9. Dans l'admin, relire les fiches importées et compléter ce que SmartOF ne portait pas
   (accessibilité, modalités propres à une formation).

## Une fois l'import fait en production

10. Le code d'accès à SmartOF ne sert plus : supprimer `SmartOfApiService`, l'entité `SmartOfAuth`
    (et sa table, par une migration), la commande `formations:importer-smartof`, les variables
    `SMARTOF_*` et le skill `.claude/skills/creer-formation` (ou le réécrire pour l'admin).
