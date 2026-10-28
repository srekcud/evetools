# Spec #253 : reconstruire la BOM d'un projet de groupe la met à jour sur place

- **Statut** : brouillon, rédigé en mode nuit sans Sylvain. Les décisions de la [spec #141](141-group-bom-on-core.md) (Q4 et « Note de livraison », validées le 2026-10-28) sont reprises telles quelles ; les questions de la dernière section ne sont pas tranchées.
- **Date** : 2026-10-28
- **Issue** : #253, « Group Industry : reconstruire la BOM ajoute une seconde BOM complète ». Le corps de l'issue n'a pas pu être lu (GitHub injoignable) : la spec part du titre, de la spec #141 et du code.
- **Lot** : une seule spec, un seul lot (un rouge puis un vert).

Les termes en gras sont ceux du [glossaire](../glossary.md).

## Contexte

`GroupIndustryProjectService::buildBom()` est publique et n'a pour seul appelant que `createProject()` (`src/Service/GroupIndustry/GroupIndustryProjectService.php:86` ; recherche dans `src/` et `tests/` : les autres appelants sont des tests). Elle calcule le **plan**, puis crée une `GroupIndustryBomItem` par **feuille** et par **job** et l'ajoute au projet par `addBomItem()` (`:116-141`). Elle ne regarde jamais les lignes que le projet porte déjà : sur un projet qui a une **BOM**, chaque appel en ajoute une seconde complète. Les **lignes de BOM** n'ont aucune clé unique : l'identifiant est un UUID généré (`src/Entity/GroupIndustryBomItem.php:18-22`) et `addBomItem()` ne dédoublonne que le même objet (`src/Entity/GroupIndustryProject.php:319-326`).

Le défaut est figé par deux tests de caractérisation :

- `tests/Unit/Service/GroupIndustry/GroupIndustryBomGoldenCharacterizationTest.php:1342-1367` (`#[Group('issue-253')]`) : 1 run de Nitrogen Fuel Block à ME 0 et TE 0 donne 11 lignes (`:1347`), le second appel en donne 22 (`:1357`), avec deux lignes Nitrogen Isotopes de 450 dont une seule porte les 300 livrés (`:1358-1360`) ;
- `tests/Integration/Service/GroupIndustry/GroupIndustryBomPersistenceTest.php:155-214` (`testRebuildingThePersistedBomCurrentlyAppendsASecondBomAndLeavesTheContributionsUntouchedIssue253`) : même constat en base (22 lignes, `:185`), les deux **contributions** restent sur leurs lignes d'origine (`:177-184`).

Les deux copies d'une même ligne ne sont pas équivalentes : la copie neuve n'a ni livré ni contribution, la copie d'origine garde les deux. Les joueurs verraient chaque besoin en double et un avancement faux.

Pourquoi ne pas simplement vider la BOM avant de la reconstruire : une **contribution** est liée à sa ligne en `onDelete: 'CASCADE'` (`src/Entity/GroupIndustryContribution.php:35-37`) et `bomItems` est en `orphanRemoval` (`src/Entity/GroupIndustryProject.php:81`). Supprimer une ligne supprime donc sa contribution, y compris une contribution `approved` qui a déjà fait avancer `fulfilledQuantity` (`GroupIndustryBomItem.php:37-38`). Une contribution approuvée ne diminue pas (glossaire, **Contribution**) : c'est exactement ce que la suppression ferait.

## Décisions de Sylvain (2026-10-28, reprises de la spec #141)

- **D1.** Quand la régénération viendra : mise à jour sur place des lignes par clé (type, job, groupe, activité, ME, TE, index de découpage), jamais de suppression d'une ligne qui porte une contribution, refus pour un projet terminé (spec #141, Q4).
- **D2.** Les projets existants et leurs contributions gardent leurs quantités enregistrées tant qu'aucune régénération explicite n'est demandée. Pas de commande console de recalcul, pas de migration (spec #141, D2).

## Règles métier

- **R1 : idempotence.** Appeler `buildBom` sur un projet qui a déjà une BOM ne crée aucun doublon. Avec le même plan (mêmes items, mêmes structures, mêmes recettes du SDE, mêmes prix), les lignes sont les mêmes, avec les mêmes identifiants, et rien n'est modifié.
- **R2 : clé d'une ligne.** Une ligne de matériau se reconnaît à `typeId` (le plan n'a qu'une feuille par type : `Plan::$leaves` est indexé par type, lu en `:104`). Une ligne de job se reconnaît à `typeId`, `activityType`, `jobGroup` (`final`, `component` ou `blueprint`), `meLevel`, `teLevel` et à son rang parmi les lignes de même clé (index de découpage). Le nom (`typeName`), les quantités, les runs et le parent ne font pas partie de la clé. Le rang est défini en Q1, car la ligne ne porte aucun index de découpage.
- **R3 : mise à jour sur place.** Une ligne du nouveau plan qui existe déjà garde son identifiant et ses contributions. Sont réécrits : `typeName`, `requiredQuantity`, et pour un job `runs` et `parentTypeId`. Ne sont jamais réécrits : `fulfilledQuantity`, les contributions (statut, quantité, valeur estimée), la clé. Le prix estimé suit Q2.
- **R4 : ligne nouvelle.** Une ligne du plan sans ligne existante de même clé est créée (`addBomItem`), avec `fulfilledQuantity` à 0.
- **R5 : ligne absente du plan.** Une ligne existante qu'aucune ligne du plan ne reprend est supprimée **seulement si aucune contribution, quel que soit son statut, ne pointe sur elle**. Sinon elle est conservée telle quelle (quantités enregistrées comprises) : aucun nouvel état, aucun champ, aucune migration. Voir « Cas limites ».
- **R6 : projet terminé refusé.** Sur un **projet terminé** (`GroupIndustryProject::isCompleted()`, `GroupIndustryProject.php:157-160`), `buildBom` lève une `\RuntimeException` à message explicite, avant tout calcul et avant toute modification. Un nouveau projet est au statut `published` (`GroupIndustryProject.php:36-37`) : `createProject` n'est pas touché.
- **R7 : rien n'est modifié si le calcul échoue.** Le plan est calculé avant la première modification de la BOM (`:96`) : une erreur de R10 de la spec #141 (item sans recette, cycle, type absent du SDE, invention sans probabilité) laisse la BOM telle qu'elle était. `buildBom` ne fait pas de `flush` (le flush de `createProject` est en `:89`) : l'appelant décide.
- **R8 : aucun point d'entrée.** Ni route API, ni bouton, ni commande console, ni message Messenger n'est ajouté. Le lot corrige le comportement de `buildBom` quand on la rappelle ; il n'offre aucun moyen de la rappeler (voir « Hors périmètre »).
- **R9 : projets existants inchangés.** Rien ne s'exécute au déploiement. Un projet n'est reconstruit que par un appel explicite à `buildBom`, qu'aucun code de production ne fait aujourd'hui sur un projet existant (D2).

## Exemples chiffrés (tests rouges)

SDE golden (`tests/Fixtures/Golden/sde-blueprint-recipes.json`) : Nitrogen Fuel Block, 40 unités par run, plafond 200, copie possible (`:29-46`). Les quantités viennent des tests cités ; un chiffre sans source est marqué `[TO CONFIRM]`.

### E1 : même plan, aucun doublon (R1, test #253 qui devient comportement)

Nitrogen Fuel Block, 1 run, ME 0, TE 0. Une contribution `approved` de 300 sur Nitrogen Isotopes (livré 300), une contribution `pending` d'installation de 1 sur le job `final`.

| Mesure | Aujourd'hui | Attendu |
|---|---|---|
| Lignes de la BOM après le premier calcul | 11 (`GroupIndustryBomGoldenCharacterizationTest.php:1347`) | 11 |
| Lignes après le second appel | 22 (`:1357`) | 11 |
| Nitrogen Isotopes : `requiredQuantity` / `fulfilledQuantity` | deux lignes, 450 / 300 et 450 / 0 (`:1358-1360`) | une ligne, 450 / 300, même identifiant |
| Job `final` : runs / `requiredQuantity` | deux lignes `final` de 1 run, dont l'une de 1 / 40 (`GroupIndustryBomPersistenceTest.php:200-212`) | une ligne, 1 / 40, même identifiant |
| Contributions | 2, inchangées (`:184`) | 2, inchangées, sur les mêmes lignes |

### E2 : une quantité change, la ligne reste (R3, R4)

Projet enregistré avant #141 pour Nitrogen Fuel Block, 25 runs, ME 10, TE 20, avec la BOM d'un run : Nitrogen Isotopes 405 (livré 300, contribution `approved` de 300), job `final` 1 run / 40 (contribution `pending` de 1), copie 1 run / 1 (`GroupIndustryBomPersistenceTest.php:224-271`). Régénération :

| Ligne | Avant | Après |
|---|---|---|
| Nitrogen Isotopes | 405, livré 300 | 10 125, livré 300, même identifiant, contribution intacte |
| Job `final` | 1 run / 40 | 25 runs / 1 000, même identifiant, contribution intacte |
| Job de copie | 1 run / 1 | 25 runs / 25, même identifiant |
| Enriched Uranium, Oxygen, Mechanical Parts, Coolant, Robotics, Heavy Water, Liquid Ozone, Strontium Clathrates | absentes | créées : 90, 495, 90, 203, 25, 3 825, 7 875, 450 (`:97-104`), livré 0 |
| Lignes de la BOM | 3 | 11 |

Ici le livré (300) reste sous les quantités requises ; si une quantité baisse sous le livré, voir « Cas limites ».

### E3 : ligne absente du plan, avec ou sans contribution (R5)

Projet de 1 run de Nitrogen Fuel Block à ME 0, TE 0 (11 lignes), puis un plan qui ne produit plus Nitrogen Isotopes ni Robotics comme feuilles. Cette situation n'arrive qu'après un changement du SDE, de la blacklist ou des structures : le test la fabrique en retirant les lignes du plan simulé, il n'en existe aucun cas réel dans les sources. Nitrogen Isotopes (450) porte la contribution `approved` de 300 ; Robotics (1) n'en porte aucune.

| Ligne | Attendu |
|---|---|
| Nitrogen Isotopes | conservée : 450, livré 300, contribution `approved` de 300 intacte |
| Robotics | supprimée |
| Les 9 autres lignes (7 matériaux, 2 jobs) | inchangées |

### E4 : jobs découpés, rapprochement par rang (R2, Q1)

Scénario construit par le test (aucun projet réel n'a cette BOM : avant #141, 250 runs de ce produit donnaient un job d'1 run) : Nitrogen Fuel Block, 250 runs, ME 10, TE 20, avec l'ancienne répartition « jobs pleins puis reste » (200 runs, 50 runs ; spec #141, R7), quantités `runs × 40`, la contribution `pending` portant sur le job de 50 runs. Le plan actuel répartit également (spec #141, R7 et E4) : 2 jobs de 125 runs, `requiredQuantity` 5 000 chacun.

| Ligne | Avant | Après |
|---|---|---|
| Job de 200 runs (rang 1) | 200 runs / 8 000 | 125 runs / 5 000, même identifiant |
| Job de 50 runs (rang 2) | 50 runs / 2 000, une contribution | 125 runs / 5 000, même identifiant, contribution intacte |
| Lignes `final` | 2 | 2 |

### E5 : projet terminé (R6)

Projet `completed` avec 11 lignes et 2 contributions. `buildBom` lève une `\RuntimeException`, aucune ligne n'est ajoutée, modifiée ni supprimée. Texte du message : [TO CONFIRM : fixé par le test rouge, il doit nommer le statut du projet].

## Cas limites

| Cas | Comportement attendu |
|---|---|
| Ligne conservée parce qu'elle porte une contribution (R5) | Elle garde sa `requiredQuantity` d'origine et reste dans la BOM, donc dans les totaux (`GroupIndustryResourceMapper.php:87-91` somme `estimatedPrice × requiredQuantity` des lignes). Aucun état « obsolète » n'est ajouté : c'est le coût assumé de ne pas détruire une contribution. Si le besoin réel a baissé, la ligne reste visible tant que sa contribution existe. |
| Quantité requise qui passe sous le livré | Rien ne s'efface : `isFulfilled` est vrai, le reste vaut 0, le pourcentage est plafonné à 100 (`GroupIndustryBomItem.php:216-233`). |
| Nombre de jobs découpés qui baisse | Les lignes de rang au-delà du nouveau nombre de jobs sont des lignes absentes du plan : R5 (supprimées sans contribution, conservées sinon). |
| Nombre de jobs découpés qui monte | Les lignes de rang manquant sont créées (R4). |
| Ligne enregistrée dont la clé ne correspond à aucune ligne du plan (ancienne constante de ME/TE d'un composant, ligne d'un projet antérieur à #107 ou #141) | Traitée comme absente (R5), et la ligne du plan est créée (R4). Aucun rapprochement approximatif. |
| Ligne de copie ou d'invention | Même règle que les autres lignes de job : clé `(typeId, activityType, jobGroup, 0, 0, rang)`. Elles ne sont pas découpées (spec #141, R7) : rang 1. |
| Contribution sans ligne (`bomItem` nul, colonne nullable `GroupIndustryContribution.php:35-37`) | Ignorée : elle ne protège aucune ligne. |
| Utilisateur passé à `buildBom` | Ses structures de production servent au calcul (`:154`). Rien ne vérifie qu'il est le propriétaire du projet : l'appelant passe le propriétaire. Hors lot. |
| Même projet reconstruit deux fois de suite | Idempotence (R1) : le second appel ne change rien, à prix constants (Q2). |
| `createProject` | Inchangé : le projet n'a encore aucune ligne, toutes sont créées (R4). |

## Hors périmètre

- Tout point d'entrée de régénération (route, bouton, commande, message) : à spécifier quand le besoin sera exprimé, avec sa confirmation côté joueur. Aucun appelant de production hors `createProject` n'est ajouté.
- Une commande console ou une migration de recalcul des projets existants (D2).
- Un état « ligne obsolète » ou « ligne à supprimer » sur `GroupIndustryBomItem`, et toute colonne nouvelle (index de découpage compris, voir Q1).
- Le recalcul des contributions déjà enregistrées (quantité, valeur estimée) et de `fulfilledQuantity`.
- La modification des items d'un projet après sa création (aucune opération ne le permet aujourd'hui).
- Le contrôle que l'utilisateur passé est le propriétaire du projet.
- La borne haute des runs et le nombre de lignes de BOM (spec #164, Q5).

## Tests attendus par couche

- **Service** (`tests/Unit/Service/GroupIndustry/`) :
  - `GroupIndustryBomGoldenCharacterizationTest::testRebuildingTheBomCurrentlyAppendsASecondFullBomNextToTheContributedLinesIssue253` (`:1342`) devient un test de comportement (E1) : il perd `Currently` et le groupe `issue-253`. 11 lignes après le second appel, mêmes objets, `fulfilledQuantity` 300, contributions inchangées.
  - Nouveaux : E2 (quantité changée sur place, lignes créées), E3 (ligne supprimée sans contribution, conservée avec), E4 (rapprochement par rang), E5 (projet terminé refusé, BOM intacte), échec du plan (BOM intacte, R7), lignes de copie et d'invention rapprochées, contribution `pending` ou `rejected` qui protège aussi sa ligne.
  - Les tests existants de `GroupIndustryProjectServiceTest` et du golden appellent `buildBom` sur un projet sans ligne : ils ne changent pas.
- **Intégration** (`tests/Integration/Service/GroupIndustry/GroupIndustryBomPersistenceTest.php`) : le test `…Issue253` (`:155`) devient E1 persisté (11 lignes, mêmes identifiants, contributions et `fulfilledQuantity` inchangés, `flush` puis `clear` avant les lectures SQL). Nouveau : E2 persisté, sur le projet de `projectRecordedWithTheBomOfOneRunForTwentyFiveRuns()` (`:224`). À conserver tel quel : `testProjectCreatedBeforeThePlanKeepsItsRecordedQuantitiesAndContributionsWhenAnotherProjectIsCreated` (`:121`, D2).
- **Architecture** : `make deptrac` sans nouvelle violation. Aucune dépendance nouvelle.
- Ordre de passage : tests rouges d'abord (validation humaine), puis implémentation ; `tests/` est verrouillé pendant le vert (ADR-0004).

## Termes du glossaire

Utilisés tels quels : **BOM**, **Ligne de BOM**, **Plan**, **Feuille**, **Intermédiaire**, **Job**, **Job final**, **Job de composant**, **Contribution**, **Projet terminé**, **Quantité**, **Run**, **ME**, **TE**.

Proposé, non ajouté (le symbole n'existe pas encore comme action) :

- **Régénération de la BOM** (BOM regeneration) : rappel de `buildBom` sur un projet de groupe qui a déjà une BOM. Les lignes sont mises à jour sur place par clé ; une ligne qui porte une contribution n'est jamais supprimée ; un **projet terminé** est refusé (spec #253). À ajouter au glossaire avec le lot, avec `GroupIndustryProjectService::buildBom()` comme symbole.

L'entrée **Groupe découpé** du glossaire décrit les étapes d'un projet solo (`splitGroupId`), pas les jobs d'une BOM de groupe : cette spec dit « job découpé » pour ces derniers.

## Questions ouvertes

1. **Q1 : rang d'une ligne de job découpé.** La ligne ne porte aucun index de découpage et ajouter une colonne demande une migration, écartée par D2. Comment rapprocher les jobs découpés d'un même produit, d'une même activité, d'un même groupe, du même ME et du même TE ? Recommandation : le rang après tri par runs décroissants des deux côtés (le planificateur donne le reste aux premiers jobs, `src/Industry/Domain/ProductionPlanner.php:187-210`, donc les runs du plan sont décroissants). À runs égaux, une ligne qui porte une contribution passe avant une ligne qui n'en porte pas, puis ordre des identifiants : ainsi, quand le nombre de jobs baisse, la ligne supprimée est de préférence celle sans contribution. Les lignes d'un même rang sont équivalentes en quantité.
2. **Q2 : prix estimé d'une ligne mise à jour.** `buildBom` récupère de toute façon les prix (`getCheapestPercentilePrices`, `:112-114`). Faut-il réécrire `estimatedPrice` des lignes existantes ? Recommandation : oui, avec ce que renvoie l'appel, `null` compris (un prix absent devient « inconnu », pas l'ancien prix présenté comme actuel, cf. spec #146 et #156). La valeur estimée des contributions n'est pas touchée. Conséquence : R1 vaut à prix constants.
