# Spec #110 — Ordre stable des étapes découpées

## Contexte

À la génération des étapes, `IndustryProjectFactory::sortAndCreateSteps()` trie les étapes par profondeur (la plus profonde d'abord), activité (réaction, copie, fabrication), identifiant de groupe découpé, index dans le groupe, puis nom du produit, et fixe `sortOrder` dans cet ordre. L'identifiant de groupe découpé est un UUID v4 aléatoire (`splitLongJobs()`) : à profondeur et activité égales, les étapes non découpées passent d'abord (identifiant vide), puis les groupes découpés dans un ordre qui change à chaque génération ou régénération. L'onglet Étapes retrie déjà par nom de produit ; l'instabilité touche `sortOrder` renvoyé par l'API et les autres vues qui suivent l'ordre de l'API.

## Règles métier

- **R1 — Ordre à profondeur et activité égales.** Les étapes sont triées par nom de produit (insensible à la casse), puis par index dans le groupe découpé (`splitIndex`). Étapes découpées et non découpées sont mêlées selon le nom de produit ; l'identifiant de groupe découpé n'intervient plus dans le tri.
- **R2 — Ordre entre niveaux inchangé.** Profondeur la plus profonde d'abord, puis réaction, copie, fabrication.
- **R3 — Stabilité.** Deux générations du même projet (création, ou création puis régénération) donnent la même suite d'étapes, `sortOrder` compris (produit, activité, profondeur, `splitIndex`, runs, quantité).

## Exemples chiffrés

Projet de référence : Nomad, 1 run, ME 10 / TE 20, sans structure, durée maximale par défaut (7 niveaux portent au moins un groupe découpé, cf. #111).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Deux créations successives du Nomad | suites d'étapes identiques au nom près, mais l'ordre relatif des groupes découpés d'un même niveau varie | même suite `[sortOrder, product, activityType, depth, splitIndex, runs, quantity]` |
| E2 | Création puis régénération du Nomad | idem E1 | même suite qu'à la création |
| E3 | Au sein de chaque niveau (profondeur, activité) du Nomad | étapes non découpées en tête, puis groupes par UUID | noms de produit en ordre croissant insensible à la casse ; au sein d'un nom, `splitIndex` 0, 1, 2… ; au moins un niveau où une étape non découpée suit un groupe découpé (le test le vérifie sur les données) |
| E4 | Niveaux du Nomad | `5 manufacturing, 4 reaction, 3 reaction, 3 manufacturing, 2 reaction, 2 manufacturing, 1 manufacturing, 0 manufacturing` | inchangé (R2) |

## Cas limites

- Deux étapes de même nom de produit au même niveau : impossible, les étapes sont consolidées par blueprint et activité ; l'étape de copie porte le suffixe « (BPC) » et une autre activité.
- Découpage manuel (`POST …/split`) : les nouveaux jobs reprennent le `sortOrder` de l'étape découpée (inchangé, hors périmètre).

## Hors périmètre

- Tri côté frontend (`StepTree.vue` trie déjà par nom de produit).
- Ordre de la collection `IndustryProject::$steps` lue en base (pas d'`OrderBy`).
- Quantité d'une étape découpée : #112.
- Projets déjà en base : ordre corrigé à la prochaine régénération, pas de migration.

## Tests existants appelés à changer

- `IndustryProjectFactoryGoldenStepsCharacterizationTest::testSplitGroupsAreCurrentlyOrderedByTheirRandomIdentifier` fige l'ordre par UUID : remplacé par E3.
- Le docblock de `canonicalStepsOf()` renvoie à ce test ; le helper reste valable (il retrie déjà par produit et index).
- `testLongJobIsSplitIntoJobsOfEvenRunsUnderTheMaximumDuration` (`sortOrder` des jobs racine en fin de liste) : doit rester vert.

## Glossaire

Aucun terme ajouté.
