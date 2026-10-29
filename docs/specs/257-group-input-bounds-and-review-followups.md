# Spec #257 : bornes des saisies d'un projet de groupe et suites de la revue du lot 5

- **Statut** : brouillon, rédigé sans Sylvain. Les décisions D1 à D3 ci-dessous ont été **tranchées par Sylvain le 2026-10-29** ; les points Q1 à Q4 (dernière section) ont été **décidés en autonomie le 2026-10-29** sur consigne du coordinateur, et ne valent qu'après relecture de Sylvain.
- **Date** : 2026-10-29
- **Issue** : #257. Son corps n'a pas pu être relu ici : la spec part des décisions de Sylvain, de la [spec #164](164-group-sale-input-validation.md) (R1, R10, Q5) et du code.
- **Lot** : une seule spec, un seul lot (un rouge puis un vert). Elle **remplace le seuil de 1000 lignes de la spec #164 (R10)** et lève son absence de borne haute sur `runs` (R1) ; ME 0 à 10 et TE 0 à 20 sont déjà livrés par #164 (`CreateGroupProjectInput.php:12-13`, `:28-29`).

Les termes en gras sont ceux du [glossaire](../glossary.md).

## Contexte

Code lu sur `fix/group-group-industry`, 2026-10-29.

- **Le plafond de lignes existe déjà, à 1000.** `GroupIndustryProjectService::MAX_BOM_LINES = 1000` (`GroupIndustryProjectService.php:44`) ; `buildBom()` compte `count($materialAggregation) + count($jobAggregation)` après `replaceT2BlueprintCopiesByInventions()` et refuse au-delà par une `\RuntimeException` (`:115-120`), avant la lecture des prix (`:122-125`) et avant `reconcileBomLines()` (`:156`). Le refus devient un 400 (`CreateGroupProjectProcessor.php:85`, `catch (\InvalidArgumentException|\RuntimeException)`).
- **1000 est trop bas pour un cas réel.** Selon la décision de Sylvain, l'issue #257 annonce « accepté » pour 35 000 runs de Hail L : plafond de blueprint 10 (`tests/Fixtures/Golden/sde-blueprint-recipes.json:291-295`), donc 3 500 lignes de job final (constante `HAIL_L_MANUFACTURING_LINES_OF_THIRTY_FIVE_THOUSAND_RUNS = 3_500` du test d'origine, `git show a5f53ad3^:tests/Unit/Service/GroupIndustry/GroupIndustryBomGoldenCharacterizationTest.php`, ligne 115). Avec 1000, ce cas est refusé. Le lot 5 a réécrit le test à 9 000 runs (958 lignes, `GroupIndustryBomGoldenCharacterizationTest.php:667-683`) pour passer sous la limite : c'est le test qui a plié, pas la règle.
- **Les runs d'un item n'ont aucune borne haute** (`CreateGroupProjectInput.php:30`, `GreaterThanOrEqual(1)` seul). Un plafond de blueprint à 1 (Fenrir : `maxProductionLimit` 1, `sde-blueprint-recipes.json:723-727`) donne un `Job` par run, construit en mémoire par le planificateur avant que `buildBom()` compte les lignes (`ProductionPlanning.php:29` puis `:38-41` ; `ProductionPlanner::splitIntoJobs()`, `ProductionPlanner.php:187`). Le refus de #164 protège la base, pas la mémoire (spec #164, « Cas limites »).
- **Les listes de blacklist ne sont pas bornées** : `blacklistGroupIds`, `blacklistTypeIds` et `blacklistCategoryKeys` n'ont aucune contrainte (`CreateGroupProjectInput.php:36-43`).
- **Suggestions de la revue du lot 5** :
  - `SubmitContributionInput::$quantity` n'a que `NotNull` et `Positive` (`SubmitContributionInput.php:99-101`) alors que la colonne est `integer` (`GroupIndustryContribution.php:42-43`) ;
  - les frais `brokerFeePercent` et `salesTaxPercent` n'ont que `PositiveOrZero`, à la création (`CreateGroupProjectInput.php:51-55`) comme à la mise à jour (`UpdateGroupProjectInput.php:76-80`) ; ce sont des pourcents, divisés par 100 dans la distribution (`GroupIndustryDistributionService.php:28-29`) ;
  - `ContributeModal.vue` garde deux libellés anglais en dur, « Auto-calculated » (`:304`) et « Unit price: » (`:308`) ;
  - `GroupIndustryContributionService::submitAutoDetected()` (`:63-93`) n'a aucun appelant dans `src/` (recherche sur `src/`, `frontend/src/`, `tests/`, `docs/` : seuls les trois tests `GroupIndustryContributionServiceTest.php:382`, `:409`, `:424` et des mentions de specs) ;
  - `reconcileBomLines()` décide la suppression d'une ligne d'après `$project->getContributions()` en mémoire (`:164-170`, puis `:175` et `:196`). Cette collection est chargée une fois et peut être périmée : une contribution ajoutée depuis (autre requête, autre worker) n'y figure pas. Supprimer sa ligne (`removeBomItem()`, `:197`) supprime alors la contribution, par `onDelete: 'CASCADE'` (`GroupIndustryContribution.php:35-37`) et `orphanRemoval` sur `bomItems` (`GroupIndustryProject.php:82`). C'est ce que la règle R5 de la [spec #253](253-group-bom-rebuild-in-place.md) interdit.

## Décisions de Sylvain (2026-10-29)

- **D1 (#257).**
  - `MAX_BOM_LINES` passe de 1000 à **5000**.
  - Contrainte **`runs` ≤ 100 000 par item** dans `CreateGroupProjectInput` : 422 sur `items[i][runs]`, avant le plan, ce qui protège la mémoire quand le plafond du blueprint vaut 1.
  - Chaque liste de blacklist (`blacklistGroupIds`, `blacklistTypeIds`, `blacklistCategoryKeys`) ≤ **200 entrées** (422).
  - ME 0 à 10 et TE 0 à 20 : déjà livrés (spec #164).
- **D2 (suggestions de revue du lot 5, corrigées dans ce lot).** (a) borne haute de `SubmitContributionInput::$quantity` ; (b) frais à `Range(0, 100)` à la création et à la mise à jour ; (c) deux libellés de `ContributeModal.vue` en i18n ; (d) suppression de `submitAutoDetected()` ; (e) `reconcileBomLines()` lit en base les lignes qui portent une contribution.
- **D3.** Ajouter au glossaire **Répartition impossible** et **Avancement de la BOM** (fait avec cette spec, voir « Termes du glossaire »).

## Règles métier

- **R1 : plafond de 5000 lignes de BOM.** `MAX_BOM_LINES = 5000` ; le reste de la règle R10 de la spec #164 est inchangé (mesure, endroit du contrôle, refus strict : 5000 lignes passent, 5001 non, `\RuntimeException` qui nomme le nombre produit et la limite, 400, rien de persisté). Le message actuel est `The production plan has %d BOM lines, above the limit of %d BOM lines per project.` (`GroupIndustryProjectService.php:119`) : il ne se termine pas par un point après la limite, et les tests lisent les nombres entiers sans séparateur.
- **R2 : runs d'un item de 1 à 100 000.** `CreateGroupProjectInput::$items[i]['runs']` : entier de 1 à 100 000, inclus. La borne haute est une constante nommée de `CreateGroupProjectInput` (même valeur que `CreateProjectInput::MAXIMUM_PROJECT_RUNS`, `CreateProjectInput.php:11`, sans la réutiliser : la borne de Group Industry n'a plus le même rôle depuis #141). Violation : 422 sur `items[i][runs]`, rien n'est persisté, aucun plan n'est calculé (la validation précède le processor). **Borne aussi la somme** (décidé en autonomie le 2026-10-29, Q3) : la somme des `runs` de tous les items de la requête est au plus 100 000 (même constante), contrainte de classe (`Callback`) sur `CreateGroupProjectInput`, 422 sur le chemin `items`. Elle porte sur tous les items, même de produits différents, donc aussi sur les items que `targetsOf()` additionne (même produit, ME et TE). Elle ne compte que les items dont `runs` est un entier ≥ 1 : un item de forme invalide n'ajoute pas de seconde violation par la somme. Le refus par nombre de lignes (R1) reste un 400 : les deux bornes se complètent, la première borne le coût du plan, la seconde la taille de la BOM écrite.
- **R3 : listes de blacklist de 200 entrées au plus.** `blacklistGroupIds`, `blacklistTypeIds`, `blacklistCategoryKeys` : `Count(max: 200)` chacune, 422 sur le nom de la liste. La constante est nommée. Les clés de catégorie sont résolues en groupes après la validation (`CreateGroupProjectProcessor.php:62-68`) : la borne porte sur ce qui est envoyé, pas sur la liste résolue.
- **R4 : quantité d'une contribution bornée par la colonne.** `SubmitContributionInput::$quantity` : de 1 à 2 147 483 647 (borne de la colonne `integer`), 422 sur `quantity`. **Rien de plus sans changer le contrat** : voir « Cas limites » (reste à fournir) et Q2 (décidé : contrat inchangé, aucun plafond au reste à fournir).
- **R5 : frais de 0 à 100.** `brokerFeePercent` et `salesTaxPercent` : `Range(0, 100)`, bornes incluses, à la création (`CreateGroupProjectInput`) et à la mise à jour (`UpdateGroupProjectInput`). Absent ou `null` : inchangé (création : repli sur les réglages Industry du propriétaire, mise à jour : champ non touché, `UpdateGroupProjectProcessor.php:80-86`). Violation : 422.
- **R6 : libellés de la modale de contribution traduits.** « Auto-calculated » et « Unit price: » passent par deux clés i18n nouvelles sous `groupIndustry.contribute` (`en.json:1962`, `fr.json:1962`) : `autoCalculated` et `unitPrice`. EN : « Auto-calculated », « Unit price: ». FR : « Calculé automatiquement », « Prix unitaire : ». Aucune autre valeur ni mise en page ne change.
- **R7 : `submitAutoDetected()` supprimée.** La méthode et ses trois tests disparaissent. Le champ `isAutoDetected` de `GroupIndustryContribution` et son badge (`ContributionTable.vue:222`, `:235`) restent : ils appartiennent au modèle de données, pas à cette méthode.
- **R8 : les lignes protégées viennent de la base.** `reconcileBomLines()` ne lit plus `$project->getContributions()`. Une méthode nouvelle de `GroupIndustryContributionRepository` renvoie, **en une seule requête**, les identifiants des lignes de BOM du projet qui portent au moins une contribution, quel que soit son statut :
  - `SELECT DISTINCT IDENTITY(c.bomItem)` sur `c.project = :project` et `c.bomItem IS NOT NULL` ; une contribution sans ligne (`bomItem` nul, colonne nullable) ne protège rien (spec #253, « Cas limites ») ;
  - `GroupIndustryProjectService` reçoit ce repository par son constructeur ;
  - l'ensemble sert aux deux usages actuels : l'ordre des lignes de même clé (`:174-176`, une ligne contribuée passe avant une ligne sans contribution à runs égaux) et la décision de supprimer (`:196`). Le rapprochement se fait sur l'identifiant de la ligne (`getId()`, chaîne RFC 4122), plus sur `spl_object_id` ;
  - un projet qui n'a encore aucune ligne persistée (cas de `createProject()`) n'interroge pas la base : aucune ligne n'a pu recevoir de contribution ;
  - **précondition** : la base fait foi. Une contribution ajoutée dans l'unité de travail courante et pas encore flushée n'est pas vue. `submit()` flush immédiatement (`GroupIndustryContributionService.php:57-58`) et `buildBom()` n'a aucun autre appelant que `createProject()` : la précondition tient aujourd'hui ; tout futur appelant doit flusher avant.
- **R9 : somme des frais au plus 100** (décidé en autonomie le 2026-10-29, Q4). `brokerFeePercent + salesTaxPercent` ≤ 100 sur les valeurs **effectives** : à la création, après le repli sur les réglages du propriétaire (`CreateGroupProjectProcessor.php:80-81`) ; à la mise à jour, la valeur enregistrée du frais non envoyé. Violation : 422 sur `salesTaxPercent`, au même format que les violations d'input. Quand les deux frais sont envoyés, une contrainte de classe suffit ; quand l'un est absent, la valeur effective n'est connue que dans le processor, qui lève la violation : le mécanisme exact est fixé par le test rouge, le résultat attendu (422, chemin) est celui de la règle.
- **R10 : glossaire.** Les deux entrées de D3 sont dans `docs/glossary.md`. L'entrée **Payout** précise qu'il est inconnu quand la répartition est impossible (modification prévue par la spec #146, vérifiée dans `GroupIndustryDistributionService.php:106-107`).

## Exemples chiffrés

### Lignes de BOM (R1, `MAX_BOM_LINES = 5000`)

Les tests du lot 5 figent 989 runs de Nitrogen Fuel Block à 999 lignes, 990 à 1 000 (`GroupIndustryBomGoldenCharacterizationTest.php:1213-1217`), et 958 runs de Hail L à 1 000 lignes (`:1305-1317`), 959 à 1 001 (`:1321-1337`). Les comptes `runs + 10` (Nitrogen Fuel Block, plafond 1) et `runs + 42` (Hail L, plafond 1) généralisent ces valeurs à 4 990 et 4 958 runs : **déduit, non exécuté** (PHP absent de l'hôte). Le test rouge le confirme par `count($project->getBomItems())` avant de figer les valeurs.

| # | Item | Lignes | Aujourd'hui (1000) | Attendu (5000) |
|---|---|---|---|---|
| B1 | Nitrogen Fuel Block, plafond forcé à 1, ME 10, TE 20, 4 989 runs | 4 999 | refusé | accepté |
| B2 | idem, 4 990 runs | 5 000 | refusé | accepté (exactement la limite) |
| B3 | idem, 4 991 runs | 5 001 | refusé | refusé, message avec 5001 et 5000, rien de persisté |
| B4 | Hail L, plafond forcé à 1, ME 2, TE 4, 4 958 runs | 5 000 | refusé | accepté |
| B5 | idem, 4 959 runs | 5 001 | refusé | refusé |
| B6 | Hail L, plafond d'origine 10, ME 2, TE 4, **35 000 runs** | au moins 3 500 jobs finaux, total **inconnu** | refusé | accepté si le total ne dépasse pas 5000 (Q1, règle de blocage ci-dessous) |
| B7 | Hail L, 9 000 runs (test actuel) | 958 (`:667-683`) | accepté | accepté, inchangé |
| B8 | Fenrir, plafond 1, ME 10, TE 20, 5 000 runs | au moins 5 001 (5 000 jobs finaux et au moins un matériau) | refusé | refusé |
| B9 | Fenrir, 1 run | 113 (`:1294-1301`) | accepté | accepté, inchangé |

B6 : le total exact des lignes de 35 000 runs de Hail L n'a pas pu être établi : l'hôte n'a pas PHP, aucun test ne le compte (le test d'origine n'asserte pas le nombre de lignes) et l'extrapolation depuis les 58 lignes hors jobs finaux de 9 000 runs (958 moins 900) n'est qu'une tendance. Le compte exact est **fixé par le test rouge** (`assertCount` du total mesuré, avant l'écriture du vert). **Règle (décidée en autonomie le 2026-10-29)** : si ce total dépasse 5000, le vert s'arrête, `MAX_BOM_LINES` n'est pas élargie en silence et le point va au journal « Blocages ».

### Runs d'un item (R2)

Item de base : `{typeId: 20189, typeName: "Fenrir", meLevel: 10, teLevel: 20, runs: 1}`. Seule la clé `runs` change.

| # | `runs` | Aujourd'hui | Attendu |
|---|---|---|---|
| I1 | 100 000 | accepté par l'input ; le plan juge | accepté par l'input ; le plan juge (B8 pour le refus) |
| I2 | 100 001 | accepté par l'input ; le plan juge | 422 sur `items[0][runs]`, aucun plan calculé |
| I3 | 0 | 422 | 422 (inchangé) |
| I4 | deux items, le second à 100 001 | idem I2 | 422 sur `items[1][runs]` et sur `items` (somme 100 002) |
| I5 | 2 147 483 648 | accepté par l'input | 422 sur `items[0][runs]` |

Somme des runs de tous les items (R2), items de Fenrir identiques (même ME et TE, donc additionnés par `targetsOf()`) :

| # | Items | Somme | Attendu |
|---|---|---|---|
| S1 | 10 items de Fenrir à 10 000 runs | 100 000 | accepté par l'input (somme incluse) ; le plan juge : plafond 1, au moins 100 000 jobs finaux, donc B8 s'applique (400, R1) |
| S2 | 10 items de Fenrir à 10 001 runs | 100 010 | 422 sur `items`, aucun plan calculé ; aucune violation `items[i][runs]` (chaque item est ≤ 100 000) |
| S3 | 1 item de Fenrir à 100 000 runs | 100 000 | accepté par l'input (I1) |
| S4 | 2 items, l'un de Fenrir à 60 000 et l'autre de Scorpion (plafond 10, `sde-blueprint-recipes.json:9-13`) à 40 001 | 100 001 | 422 sur `items` : la somme porte aussi sur des produits différents |
| S5 | 3 items à 1, 1 et 1 runs | 3 | accepté (garde-fou) |

### Listes de blacklist (R3)

| # | Entrée | Attendu |
|---|---|---|
| L1 | `blacklistTypeIds` de 200 entiers distincts | accepté |
| L2 | `blacklistTypeIds` de 201 entiers | 422 sur `blacklistTypeIds` |
| L3 | `blacklistGroupIds` de 201, `blacklistCategoryKeys` de 201 | deux violations, `blacklistGroupIds` et `blacklistCategoryKeys` |
| L4 | trois listes vides | accepté (inchangé) |

### Quantité d'une contribution (R4)

| # | `quantity` | Attendu |
|---|---|---|
| N1 | 1 | accepté |
| N2 | 2 147 483 647 | accepté par l'input |
| N3 | 2 147 483 648 | 422 sur `quantity` (aujourd'hui : l'input passe, l'insertion dépasse la colonne `integer`, 500 attendu, non exécuté) |
| N4 | 0 et -1 | 422 (inchangé, `Positive`) |

### Frais (R5)

| # | `brokerFeePercent` (ou `salesTaxPercent`) | Attendu, création et mise à jour |
|---|---|---|
| F1 | 0 | accepté |
| F2 | 3.6 | accepté |
| F3 | 100, l'autre frais à 0 | accepté (somme 100) |
| F4 | 100.01 | 422 sur le champ |
| F5 | -0.01 | 422 (inchangé) |
| F6 | absent ou `null` | inchangé |

Somme des frais (R9) :

| # | Entrée | Attendu |
|---|---|---|
| F7 | création, courtage 60, taxe 40 | accepté (somme 100) |
| F8 | création, courtage 60, taxe 40.01 | 422 sur `salesTaxPercent` |
| F9 | création, courtage 100, taxe absente, réglages du propriétaire à 3,6 % | taxe effective 3.6, somme 103.6 : 422 sur `salesTaxPercent` |
| F10 | mise à jour, courtage enregistré 3.6, `salesTaxPercent` 96.4 | accepté (somme 100) |
| F11 | mise à jour, courtage enregistré 3.6, `salesTaxPercent` 96.5 | 422 sur `salesTaxPercent`, projet inchangé |
| F12 | mise à jour, `brokerFeePercent` 99 seul, taxe enregistrée 3.6 | 422 sur `salesTaxPercent` (somme 102.6) |

### Ligne contribuée après le chargement du projet (R8)

Nitrogen Fuel Block, 1 run, ME 0, TE 0 : 11 lignes (spec #253, E1). La collection `contributions` du projet est chargée vide. Une contribution `material` de 1 sur la ligne Robotics (quantité requise 1) est insérée en base ensuite. Un nouveau plan ne produit plus Robotics (cas de la spec #253, E3).

| Mesure | Aujourd'hui | Attendu |
|---|---|---|
| Ligne Robotics | supprimée (la collection ne voit pas la contribution) | conservée, 1 requis, 0 livré, même identifiant |
| Contributions en base | 0 (supprimée par cascade) | 1, inchangée |
| Lignes de la BOM | 10 | 11 |
| Requêtes ajoutées par R8 | 0 | 1, quel que soit le nombre de lignes |

## Cas limites

- **Pourquoi la somme des runs est bornée.** `targetsOf()` additionne les items de même produit, même ME et même TE (`GroupIndustryProjectService.php:277-279`), puis construit un seul `Runs`. Sans borne sur la somme, dix items de Fenrir à 100 000 runs passeraient la borne par item et donneraient une cible de 1 000 000 runs : le planificateur construirait au moins 1 000 000 `Job` avant que R1 refuse (plafond de blueprint 1). Avec la somme à 100 000 (R2), le pire cas est un plan d'au plus 100 000 runs, donc d'au plus 100 000 `Job` à plafond 1 ; son coût mémoire réel n'est pas mesuré (inconnu : PHP absent de l'hôte). Q3, décidé en autonomie le 2026-10-29.
- **Reste à fournir.** Une borne « jamais au-dessus du reste à fournir de la ligne » **change le contrat** : `submit()` ne lit ni la quantité requise ni le reste (`GroupIndustryContributionService.php:29-61`) ; `approve()` ajoute la quantité à `fulfilledQuantity` sans plafond (`:100-118`, `:197-200`) ; le surplus est toléré (`isFulfilled()` vrai au-delà, pourcentage plafonné à 100, reste à 0, `GroupIndustryBomItem.php:216-233`) ; la spec #154 ne parle que de la valeur, pas de la quantité ; la modale ne fait que préremplir le reste (`ContributeModal.vue:48`). De plus deux contributions en attente peuvent chacune viser le reste entier : seule la somme des approuvées est comparable. Faisable sans changer le contrat : la borne statique de R4. Q2, décidé en autonomie le 2026-10-29 : seule cette borne, aucun plafond au reste à fournir.
- **Dépassement à l'approbation.** `fulfilledQuantity` est aussi une colonne `integer` (`GroupIndustryBomItem.php:37-38`). Même avec R4, une quantité approuvée qui s'ajoute au livré peut dépasser 2 147 483 647 : `approve()` flushe alors une valeur hors colonne (`:117`). Hors lot (voir « Hors périmètre »).
- **Somme des frais.** R5 borne chaque frais ; sans R9, 100 % de courtage et 100 % de taxe donneraient un revenu net de -100 % du revenu (`GroupIndustryDistributionService.php:28-30`). R9 borne la somme (Q4, décidé en autonomie le 2026-10-29). Un projet existant dont la somme dépasse déjà 100 reste lisible ; il est refusé à sa prochaine mise à jour d'un frais, et seulement si la somme effective dépasse 100.
- **Rebuild.** Le contrôle de R1 s'applique aussi à une régénération (`buildBom()` est publique) ; R8 ne change pas son contrat de refus pour un projet terminé (`:98-100`).
- **Requête de R8 et lignes neuves.** Les lignes du plan courant n'ont pas d'identifiant avant le flush : elles ne sont jamais dans le résultat, ce qui est juste (aucune contribution ne peut les viser).
- **Format de l'identifiant.** Le repository renvoie des chaînes RFC 4122 ; le rapprochement les compare à `GroupIndustryBomItem::getId()?->toRfc4122()`. Même précédent que `sumApprovedValueByProject()` (`GroupIndustryContributionRepository.php:70`, clé `(string) $project->getId()`). Le test d'intégration le confirme sur PostgreSQL.
- **Blacklist sans type d'élément.** Les éléments des listes ne sont pas typés (`@var int[]`, `CreateGroupProjectInput.php:36-43`) : R3 ne borne que leur nombre.

## Hors périmètre

- Borne de la quantité d'une contribution par le reste à fournir de la ligne : contrat inchangé (Q2).
- **Débordement de `fulfilledQuantity` à l'approbation** : la colonne est `integer` (`GroupIndustryBomItem.php:37-38`) et `approve()` y ajoute la quantité sans contrôle (`GroupIndustryContributionService.php:100-118`, `:197-200`) ; avec R4 seul, une somme de quantités approuvées au-delà de 2 147 483 647 reste possible et donne une erreur 500 au flush. Non traité dans ce lot, ni par une règle ni par un test (décidé en autonomie le 2026-10-29, Q2).
- Attribut `max` du champ de runs du formulaire (`NewProjectModal.vue:519`) et de la saisie en masse : une valeur trop haute reçoit le 422 de l'API.
- Le libellé anglais en dur « remaining » de `ContributeModal.vue:282` : même fichier, même défaut, mais hors des deux libellés décidés.
- Les mentions de `submitAutoDetected()` dans les specs #107 (`:36`), #144 (`:5`, `:34`), #146 (`:96`) et #154 (`:84`) : elles décrivent un état passé (« aucun appelant ») et ne sont pas réécrites.
- La détection automatique de contributions depuis les jobs ESI : si elle revient, c'est une spec à part.
- Rafraîchir les prix ou régénérer la BOM : aucun point d'entrée n'est ajouté (spec #253, R8).

## Fichiers concernés (indicatif)

- Backend :
  - `GroupIndustryProjectService` (constante `MAX_BOM_LINES`, dépendance au repository, `reconcileBomLines()`) ;
  - `GroupIndustryContributionRepository` (méthode de R8) ;
  - `CreateGroupProjectInput` (borne de `runs`, `Callback` sur la somme des runs, `Count` des trois listes, `Range` des frais, somme des frais quand les deux sont envoyés) ;
  - `UpdateGroupProjectInput` (`Range` des frais, somme des frais quand les deux sont envoyés) ;
  - `CreateGroupProjectProcessor` et `UpdateGroupProjectProcessor` (somme des frais effectifs, R9) ;
  - `SubmitContributionInput` (borne de `quantity`) ;
  - `GroupIndustryContributionService` (suppression de `submitAutoDetected()`).
- Frontend : `ContributeModal.vue`, `frontend/src/i18n/locales/en.json` et `fr.json` (deux clés).
- Documentation : `docs/glossary.md`, `docs/specs/164-group-sale-input-validation.md` (renvois).
- Aucune migration, aucune nouvelle dépendance. Aucun changement de configuration : `GroupIndustryProjectService` est autowiré.

## Tests existants appelés à casser

Fichier `tests/Unit/Service/GroupIndustry/GroupIndustryBomGoldenCharacterizationTest.php` (numéros de ligne au 2026-10-29).

Cassent avec `MAX_BOM_LINES = 5000` (le plan refusé à 1001 lignes passe) :

- `testPlanOfOneThousandAndOneBomLinesIsRefusedNamingItsLineCountAndTheLimitBeforeAnyPriceIsRead` (`:1237`, 991 runs) ;
- `testCreatingAProjectWhosePlanExceedsTheBomLineLimitPersistsAndFlushesNothing` (`:1258`, 991 runs) ;
- `testOneThousandRunsOfFenrirAtItsMaxProductionLimitOfOneAreRefusedNamingTheBomLineLimit` (`:1274`, Fenrir 1 000 runs : au moins 1 001 lignes, total exact inconnu ; refusé à 1000, rien ne prouve le refus à 5000) ; à remplacer par B8 (5 000 runs) ;
- `testInventionAndCopyLinesOfAT2PlanPushItOneLineOverTheBomLineLimit` (`:1321`, Hail L 959 runs) ;
- `testBomLineLimitCountsTheWholePlanOfAllTheItemsOfTheProject` (`:1341`, 500 + 491 runs) ;
- `testRebuildOverTheBomLineLimitLeavesTheBomAndItsContributionsUntouched` (`:1360`, 991 runs).

Restent verts mais deviennent faux dans leur nom ou leur jeu de données (« exactly the limit », 989/990) :

- `planWithinTheBomLineLimitProvider` (`:1213-1218`) et `testPlanOfNitrogenFuelBlockAtAMaxProductionLimitOfOneWithinTheBomLineLimitIsBuilt` (`:1222`) : les cas 989 (999 lignes) et 990 (1 000 lignes) passent encore ; ils doivent viser 4 989, 4 990 (B1, B2), le cas 991 vise 4 991 (B3) ;
- `testInventionAndCopyLinesOfAT2PlanCountTowardsTheBomLineLimitAtExactlyTheLimit` (`:1305`, 958 runs, 1 000 lignes) : à passer à 4 958 (B4).

À réécrire dans l'autre sens :

- `testNineThousandRunsOfHailLAreSplitIntoJobsOfTenRunsAndInventedForAllTheirManufacturingRuns` (`:667`) : revient à **35 000 runs**, sous son nom d'origine `testThirtyFiveThousandRunsOfHailLAreSplitIntoJobsOfTenRunsAndInventedForAllTheirManufacturingRuns`. Valeurs d'origine (`git show a5f53ad3^`) : 3 500 lignes de job final de 10 runs, 10 295 tentatives d'invention, datacores 10 295 et 20 590 ; la constante `HAIL_L_MANUFACTURING_LINES_OF_NINE_THOUSAND_RUNS` (`:115`) redevient `…THIRTY_FIVE_THOUSAND_RUNS = 3_500`. Le test ajoute `assertCount(<total mesuré>, $project->getBomItems())` (B6).

`tests/Unit/State/Processor/GroupIndustry/CreateGroupProjectProcessorTest.php` : `testPlanRefusedByTheBomLineLimitIsABadRequestWithTheSameMessage` (`:112-126`) reste vert (l'exception est simulée) ; son texte `1001` / `1000` est à aligner sur la limite pour ne pas mentir.

`tests/Unit/ApiResource/Input/GroupIndustry/CreateGroupProjectInputValidationTest.php` : `testItemWithOneHundredThousandRunsIsAcceptedByTheInputValidation` (`:52`) reste vert et devient le test de la borne incluse (I1) ; rien d'autre ne casse.

Construction du service (R8) : les trois `new GroupIndustryProjectService(` gagnent un argument : `GroupIndustryProjectServiceTest.php:647`, `GroupIndustryBomGoldenCharacterizationTest.php:2348`, `tests/Integration/Service/GroupIndustry/GroupIndustryBomPersistenceTest.php:78`.

Les 18 tests du golden qui posent leurs contributions en mémoire par `contribution()` (`:2311`) : ils appellent `$this->contribution(` aux lignes 1366, 1528, 1579, 1609, 1625, 1648, 1673, 1689, 1709, 1725, 1738, 1798, 1811, 1824, 1842, 1889, 1910 et 1963. Le double du repository doit renvoyer les identifiants des lignes contribuées lues sur `$project->getContributions()` ; les lignes construites par `buildBom()` n'ont pas d'identifiant en test unitaire, le double leur en pose par réflexion, comme `recordedJobLineWithIdentifier()` (`:2296-2300`). Leurs assertions ne changent pas.

`tests/Unit/Service/GroupIndustry/GroupIndustryContributionServiceTest.php` (R7) : sont supprimés `testSubmitAutoDetectedIsApprovedAndIncrementsFulfillment` (`:382`), `testSubmitAutoDetectedRecordsTheDetectedQuantityForTheMemberOnTheBomItemOfTheProject` (`:409`) et `testSubmitAutoDetectedIsRefusedWhenTheMemberAlreadyHasAPendingContributionOfThatTypeOnTheBomItem` (`:424`). Ils n'existent que pour elle. Les helpers `givenNoExistingContribution()`, `givenExistingContributionWithStatus()` et `morphiteBomItem()` servent aussi à `submit()` (`:72`, `:182`, `:426`, `:440`) : ils restent.

Frontend : aucun test ne cite « Auto-calculated » ni « Unit price » (recherche dans `frontend/tests` et `frontend/src`). `contributeModalBomPriceSource.spec.ts` et ses voisins ne cassent pas.

Frais, quantité : aucun test existant n'envoie un frais supérieur à 100 ni une quantité de contribution au-delà de la colonne (recherche de `brokerFeePercent` et `salesTaxPercent` dans `tests/`, valeurs 0 à 3,6).

## Tests attendus par couche

- **Unitaire, validation** (`tests/Unit/ApiResource/Input/GroupIndustry/`, modèle `CreateGroupProjectInputValidationTest` avec `PropertyPathsOfViolations`) :
  - `CreateGroupProjectInputValidationTest` : I1 à I5 ; S1 à S5 (la borne de la somme est tuée aux deux côtés par S1 et S4) ; L1 à L4 ; F1 à F5, F7, F8 sur `brokerFeePercent` et `salesTaxPercent` ; chemins `items`, `items[i][runs]`, `blacklistTypeIds`, `blacklistGroupIds`, `blacklistCategoryKeys`, `salesTaxPercent` ;
  - nouvelle classe `UpdateGroupProjectInputValidationTest` : F1 à F6 et F7, F8 (deux frais envoyés) ;
  - `CreateGroupProjectProcessorTest` : F9 (taxe effective issue des réglages) ; `UpdateGroupProjectProcessorTest` : F10 à F12 (frais enregistré), projet inchangé et aucun flush sur F11 et F12 ;
  - nouvelle classe `SubmitContributionInputValidationTest` : N1 à N4.
- **Unitaire, service** (golden, harnais existant) : B1 à B9 ; le refus avant la lecture des prix (`$this->pricedTypeIds` vide) et l'absence de `persist` / `flush` pour B3, comme les tests actuels ; un mutant sur `>` / `>=` tué par B2 et B3 (`make infection`, `FILTER=src/Service/GroupIndustry/GroupIndustryProjectService.php`) ; **R8** : le repository n'est jamais appelé sur un projet sans ligne (`createProject()`) ; les 18 tests de contribution passent avec le double.
- **Unitaire, frontend** (vitest, modèle `contributeModalBomPriceSource.spec.ts`, `createI18n` avec `en.json` et `fr.json`) : en EN la modale d'un matériau à prix BOM affiche « Auto-calculated » et « Unit price: » ; en FR « Calculé automatiquement » et « Prix unitaire : » ; aucune clé manquante (le test échoue sur le texte de la clé).
- **Intégration** :
  - `tests/Integration/Repository/GroupIndustryContributionRepositoryTest.php` : la méthode de R8 renvoie les identifiants des lignes contribuées de tout statut, une seule fois chacun quand deux contributions visent la même ligne, rien pour une contribution sans ligne, rien pour un autre projet ;
  - `GroupIndustryBomPersistenceTest` : le scénario « ligne contribuée après le chargement » (tableau R8), avec lecture SQL des lignes et des contributions après `flush` puis `clear` ; la collection `contributions` est initialisée avant l'insertion SQL, sinon le chargement tardif la rendrait à jour et le test ne serait pas rouge ; un test de nombre de requêtes : la méthode de R8 en ajoute une, quel que soit le nombre de lignes.
- **Architecture** : `make deptrac` sans nouvelle violation (le service utilise déjà des repositories).
- Ordre de passage : tests rouges d'abord (validation humaine), puis implémentation ; `tests/` est verrouillé pendant le vert (ADR-0004).

## Termes du glossaire

Utilisés tels quels : **BOM**, **Ligne de BOM**, **Plan**, **Job**, **Job final**, **Run**, **Contribution**, **Blacklist**, **Régénération de la BOM**, **Frais de courtage** et **Taxe de vente**, **Prix BOM**, **Payout**, **ME**, **TE**.

Ajoutés au glossaire avec cette spec (section « Industrie collaborative ») : **Répartition impossible** (`GroupIndustryDistributionService::calculateDistribution()`, `DistributionResult::$marginPercent` nul) et **Avancement de la BOM** (`GroupIndustryResourceMapper::projectToResource()`, `GroupIndustryProjectResource::$fulfillmentPercent`). L'entrée **Payout** est complétée.

## Décisions prises en autonomie (2026-10-29)

Points laissés ouverts par la première version de la spec, **décidés en autonomie le 2026-10-29** sur consigne du coordinateur ; Sylvain peut les revoir.

1. **Q1 : total de lignes de 35 000 runs de Hail L.** Le test rouge le mesure (B6). Si le total dépasse 5000, le vert s'arrête et le point va au journal « Blocages » ; la limite n'est pas élargie en silence.
2. **Q2 : quantité d'une contribution.** Seule la borne 2 147 483 647 (R4) ; aucun plafond au reste à fournir, contrat inchangé. Le débordement de `fulfilledQuantity` à l'approbation est hors lot (« Hors périmètre »).
3. **Q3 : somme des runs.** Borne de 100 000 sur la somme des runs de tous les items de la requête, en plus de la borne par item (R2). Elle protège la mémoire même quand `targetsOf()` additionne des items du même produit.
4. **Q4 : somme des frais.** `brokerFeePercent + salesTaxPercent` ≤ 100 sur les valeurs effectives, 422 sur `salesTaxPercent` (R9).

## Questions ouvertes

Aucune.
