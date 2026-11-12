---
name: creer-formation
description: Créer (ou réécrire) une fiche de formation du site en s'inspirant d'une formation en ligne (URL d'un concurrent, d'un catalogue…). À utiliser quand l'utilisateur donne une URL de formation, demande de créer / ajouter / réécrire une formation ou une fiche du catalogue.
---

# Créer une fiche de formation à partir d'une formation en ligne

Argument attendu : l'URL de la formation source, éventuellement suivie des tarifs INTER / INTRA
et de consignes (durée, public…). S'il manque l'URL, la demander.

Les échanges avec l'utilisateur se font en français.

Les formations sont gérées par le site lui-même (admin `/admin/formations`), plus par SmartOF.
Le skill écrit par l'API du site, qui crée une **version** à chaque enregistrement : rien n'est
perdu, tout se retrouve dans l'historique de la fiche.

## Outil

`node .claude/skills/creer-formation/formations.mjs [--prod] <action>` :

- `liste` : toutes les formations (code, nom, statut, domaine, durée), et `domaines` : les
  domaines qu'une formation peut prendre.
- `voir <CODE>` : une fiche complète.
- `enregistrer <fiche.json> [--simuler]` : crée la formation si le fichier n'a pas de code (ou
  un code inconnu), sinon la modifie. Seuls les champs présents dans le fichier sont touchés. Avec `--simuler`, rien n'est
  écrit : la réponse dit ce qui changerait.

Sortie : code HTTP en 1re ligne, puis le JSON. Écrire les fichiers de travail (fiches,
sauvegardes) dans le scratchpad de la session.

**Local ou production.** Sans option, l'outil écrit dans le site local (le serveur Symfony doit
tourner). Avec `--prod`, dans le site en ligne (`CREER_FORMATION_PROD_URL` et
`CREER_FORMATION_PROD_JETON` de `.env.local`). Les deux bases sont distinctes : **demander à
l'utilisateur où écrire** s'il ne l'a pas dit, et le rappeler dans le compte rendu. Une fiche créée
en local n'existe pas en production.

## Déroulé

1. **Lire la source** (WebFetch ; si le site bloque, passer par le navigateur) : titre, durée,
   public, prérequis, objectifs, programme, points forts.
2. **Chercher les doublons** : `liste`, comparer noms et programmes (`voir`). Si une formation
   proche existe, le signaler et proposer : réécrire l'existante, créer une formation
   complémentaire, ou changer de source. Ne pas créer de doublon sans accord.
3. **Rédiger une version originale.** S'inspirer, ne jamais recopier : autre découpage, autres
   formulations, ateliers qui nous ressemblent, contenu à jour. Un programme copié se repère
   (concurrence, SEO). Prendre une fiche publiée comme modèle de ton (`voir`).
4. **Montrer le brouillon complet** à l'utilisateur et **attendre sa validation** avant tout
   enregistrement. `--simuler` sert à contrôler la fiche avant de la montrer.
5. **Enregistrer.** Une nouvelle formation est créée en **brouillon** (invisible sur le site).
6. **Vérifier** en relisant la fiche (`voir`), traiter les `avertissements` de la réponse, puis
   faire le point en donnant le lien `admin` de la fiche.

## Format de la fiche (JSON)

```json
{
  "nom": "Python - les fondamentaux",
  "domaine": "Développement informatique",
  "accroche": "Une phrase pour la carte du catalogue.",
  "dureeHeures": 21,
  "tarifInterEuros": 2250,
  "tarifIntraEuros": 7300,
  "objectifs": "• …\n• …",
  "publicVise": "• …",
  "prerequis": "• …",
  "programme": "JOUR 1 – …",
  "ceQuiChange": "◦ …",
  "motif": "Création d'après la demande du 12/11"
}
```

- `code` : **à omettre pour une création** : le site le tire de l'intitulé (7 caractères au plus,
  unique, par ex. `PYT-FON`) et le renvoie dans la réponse (`fiche.code`). Pour une modification,
  donner le code de la formation : c'est lui qui la désigne.
- `nom` : titre public. Il donne l'adresse de la page à la création. L'API refuse de renommer une
  formation publiée (409) : cela se fait dans l'admin, en connaissance de cause.
- `domaine` : **un des domaines de la liste** (`domaines` dans la réponse de `liste`), par ex.
  `Intelligence Artificielle`, `Développement informatique`, `Big data et analytics` ; la casse
  ne compte pas. Un domaine inconnu est refusé (422, avec la liste) : la liste se gère dans
  l'admin (/admin/domaines), proposer à l'utilisateur d'y créer le domaine plutôt que d'en
  choisir un qui ne convient pas. `""` retire le domaine.
- `dureeHeures` : en heures, 7 h par jour.
- `tarifInterEuros`, `tarifIntraEuros` : en euros hors taxes, `null` si inconnu. À demander à
  l'utilisateur s'il ne les a pas donnés.
- `objectifs`, `publicVise`, `prerequis` : une ligne par point, préfixée `• `. Objectifs formulés
  avec un verbe d'action, évaluables : ils servent ensuite au positionnement, à l'évaluation des
  acquis et à l'attestation de fin de formation (Qualiopi). Quatre à huit objectifs.
- `programme` :
  ```
  JOUR 1 – Titre de la journée
  ◦ Séquence (2h)
  • Point abordé
  • Atelier : …

  ◦ Séquence suivante (1h30)
  …
  ```
  - Titre de journée **sur la même ligne** que `JOUR n`, séparé par ` – `.
  - Formation d'**un seul jour** : pas de ligne `JOUR`, directement les `◦`.
  - Chaque journée totalise 7 h. L'API avertit si la somme des séquences diffère de la durée.
- `ceQuiChange` : « Ce que ça change, concrètement », 3 blocs :
  ```
  ◦ Situation de travail
  Aujourd'hui : ce qui se passe sans la formation (concret, une phrase).
  Après : ce qui change après la formation (une phrase).
  ```
- `lieu`, `methodes`, `moyens`, `evaluation`, `acces`, `accessibilite`, `profilFormateurs` :
  vides sur la plupart des fiches, le site et les documents générés ont un texte par défaut. Ne
  les remplir que si la formation a une particularité.
- `motif` : la raison de l'enregistrement, conservée dans l'historique de la fiche.

Pour **réécrire** une fiche : partir d'un `voir` frais, n'envoyer que `code`, `motif` et les champs
à changer.

## Ce que l'API ne fait pas, à annoncer à l'utilisateur

- **Publier** : le statut se change dans l'admin, après relecture. Le catalogue lit la base
  directement : une formation publiée apparaît tout de suite.
- **Adresse de la page, mise en page d'accueil, note, nombre de personnes formées** : dans
  l'admin. L'API refuse ces champs (400).

L'API est dans `src/Controller/ApiFormationController.php` ; ce que le site affiche d'une fiche,
dans `src/Service/CatalogueFormations.php`.
