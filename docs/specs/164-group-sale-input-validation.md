# Spec #164 — Saisies Group Industry validées (items, ventes), vente corrigeable et supprimable

Statut : **brouillon, rédigé sans Sylvain**. Les réponses recommandées sont appliquées dans la spec ; elles ne valent qu'après validation des questions de la section finale.

**Périmètre de la boucle de nuit du 2026-10-28.** Les ventes (R2 à R9, S1 à S17, correction et suppression) restent dans le lot. Pour les items, R1 est réduit aux contraintes de forme et aux bornes basses (`runs` ≥ 1, `meLevel` 0 à 10, `teLevel` 0 à 20). **La borne haute des runs (Q5, recouvre #257) est hors lot, en attente de la décision de Sylvain** : depuis #141, le plafond SDE vaut 1 pour Fenrir, Widow, Nomad et Marshal, donc une ligne de BOM par run, et la borne 1 à 100 000 du projet solo ne convient pas à Group Industry.

## Contexte

Faits vérifiés dans le code au 2026-10-28 (les numéros de ligne de l'issue sont périmés) :

- **Items d'un projet.** `CreateGroupProjectInput::$items` ne porte que `NotBlank` et `Count(min: 1, max: 10)`. La forme de chaque item n'est pas contrôlée : `GroupIndustryProjectService::createProject()` lit `$itemData['typeId']`, `['typeName']`, `['meLevel']`, `['teLevel']`, `['runs']` sans garde (`:77-81`), une clé absente provoque une erreur 500, et le processor lit `$item['typeId']` (`CreateGroupProjectProcessor.php:104`). Les valeurs hors bornes sont en revanche déjà refusées en 400 par les objets du noyau : `GroupIndustryProjectService::targetsOf()` (`:166-186`) construit `Runs`, `MaterialEfficiency` et `TimeEfficiency`, qui lèvent `\InvalidArgumentException` (`Runs.php:16-18`, `MaterialEfficiency.php:14-15`, `TimeEfficiency.php:17-18`), et `CreateGroupProjectProcessor::process()` convertit en 400 (`:78-79`). Le message ne nomme pas l'item et seule la première violation est signalée. Exception : pour un item de réaction, ME et TE sont ignorés (forcés à 0, `:171-172`) mais enregistrés tels quels sur `GroupIndustryProjectItem` (`:79-80`). Autre exception : deux items du même produit au même ME/TE se somment (`:173-175`) ; `runs: 5` et `runs: 0` passent, et l'item à 0 run est enregistré. Le collage en masse du formulaire (`NewProjectModal.vue::parseBulkText()`) accepte n'importe quels chiffres : `Ishtar 12 25 x0` envoie ME 12, TE 25 et 0 run, et reçoit 400 sur la seule première violation (ME 12).
- **Saisie d'une vente.** `RecordSaleInput` n'a aucune contrainte. `RecordSaleProcessor::process()` refuse lui-même `quantity <= 0` et `unitPrice < 0`, en **400** (pas 422), après le contrôle d'accès et le contrôle du projet terminé. `soldAt` est lu par `new \DateTimeImmutable($data->soldAt)` sans garde. Comportement vérifié sous PHP 8.5 :
  - `not-a-date` lève `DateMalformedStringException`, d'où une erreur 500 ;
  - `2026-02-30T10:00:00+00:00` devient en silence le 2026-03-02 ;
  - `tomorrow` est accepté (minuit du lendemain).
- **Autres erreurs 500 sur une vente.** Un champ requis absent (ex. `quantity`) fait lire une propriété typée non initialisée. `typeName` ou `venue` de plus de 255 caractères dépasse la colonne `string(255)`. Une `quantity` au-delà de 2 147 483 647 dépasse la colonne `integer`.
- **Vente non corrigeable.** `GroupIndustrySaleResource` ne déclare que `GetCollection` et `Post` : aucune opération `Patch` ni `Delete`.
- **Distribution.** Elle n'est pas stockée. `GroupIndustryDistributionService::calculateDistribution()` relit les ventes du projet à chaque appel (revenu = Σ `totalPrice`). Une correction ou une suppression se reflète donc tout de suite, sans migration.
- **Frontend.** `SalesTable.vue` envoie toujours `typeId: 0` (nom saisi à la main, sans sélecteur de type) et n'envoie jamais `soldAt`. Le bouton est désactivé si `unitPrice <= 0`. Seuls le propriétaire et les admins voient le formulaire (`isOwnerOrAdmin`).

## Règles métier

- **R1 — Items bornés.** Chaque item de `CreateGroupProjectInput::$items` porte exactement les cinq clés `typeId`, `typeName`, `meLevel`, `teLevel`, `runs`, toutes requises :
  - `typeId` : entier ≥ 0 (0 = type à résoudre par son nom, cas du collage en masse) ;
  - `typeName` : chaîne non vide, 255 caractères au plus ;
  - `meLevel` : de 0 à 10 ;
  - `teLevel` : de 0 à 20 ;
  - `runs` : entier ≥ 1, **sans borne haute** (hors lot, Q5).

  Les contraintes sont des contraintes Symfony sur `CreateGroupProjectInput::$items`. Toute violation renvoie 422, avec le chemin de l'item (`items[1][runs]`). Aucun projet n'est créé et aucune BOM n'est calculée. Elles valent pour tous les items, y compris une réaction dont le noyau ignorerait ME et TE : un ME de 11 sur une réaction passe donc de « accepté, ignoré » à 422.

  Le pas du TE n'est pas contrôlé par ces contraintes : un TE impair (ex. 15) dans la plage reste refusé en 400 par `TimeEfficiency` du noyau (`LEVEL_STEP`), comme aujourd'hui. Q5 porte sur ces choix.
- **R2 — Vente bornée.** `RecordSaleInput` :
  - `typeId` : requis, entier ≥ 0 (le formulaire envoie 0) ;
  - `typeName` : requis, non vide, 255 caractères au plus ;
  - `quantity` : requise, de 1 à 2 147 483 647 ;
  - `unitPrice` : requis, ≥ 0 ;
  - `venue` : facultatif, 255 caractères au plus ;
  - `soldAt` : facultatif, date ISO 8601 avec fuseau au format `DATE_ATOM` (`2026-10-25T14:30:00+00:00`, `Z` accepté, sans millisecondes). Une date impossible (30 février) est refusée. Sans `soldAt`, la date reste celle de l'enregistrement (Q4).

  Toute violation renvoie 422. Aucune vente n'est persistée et aucun événement Mercure n'est publié.
- **R3 — Plus de 400 de saisie dans le processor.** Les refus `Quantity must be positive` et `Unit price must not be negative` de `RecordSaleProcessor` disparaissent, remplacés par R2. Le refus d'un projet terminé (400, #152 R1) et le contrôle d'accès (403/404) restent.
- **R4 — Correction d'une vente.** `PATCH /group-industry/projects/{projectId}/sales/{id}`, en `application/merge-patch+json`, avec `uriVariables` `projectId` (`Link` vers `GroupIndustryProjectResource`) et `id` (`Link` vers `GroupIndustrySaleResource`). Un provider d'item charge la vente.
  - Champs facultatifs : `typeId`, `typeName`, `quantity`, `unitPrice`, `venue`, `soldAt`, avec les contraintes de R2 sur les champs fournis. Un champ absent ou `null` reste inchangé, comme dans `UpdateGroupProjectInput`.
  - `totalPrice` est recalculé (`quantity × unitPrice`). `recordedBy` et `createdAt` ne changent pas.
  - Réponse 200 avec la vente. Événement Mercure `sale_updated`, avec les mêmes données que `sale_recorded` (`saleId`, `typeName`, `quantity`, `totalPrice`).
- **R5 — Suppression d'une vente.** `DELETE /group-industry/projects/{projectId}/sales/{id}` (mêmes `uriVariables`), avec un provider qui renvoie la vente. Réponse 204 et événement Mercure `sale_deleted` (`saleId`).
- **R6 — Qui corrige et supprime.** Le propriétaire du projet ou un admin `accepted`, comme pour l'enregistrement, quel que soit l'auteur de la vente (Q1). Sinon 403. Les cas 404 sont un projet inconnu, une vente inconnue, et une vente d'un autre projet que `projectId`. Le provider fait ces contrôles avant la validation de l'input.
- **R7 — Projet terminé.** Sur un projet `completed`, la correction et la suppression sont refusées en 400 et la vente reste inchangée (Q2, même règle que #152 R1).
- **R8 — Distribution.** Elle suit les ventes du moment : une vente corrigée compte pour son nouveau total et une vente supprimée ne compte plus. Cela vaut pour le revenu, les frais, le revenu net, la marge et les payouts. Une fois sa dernière vente supprimée, un projet sans contribution approuvée redevient supprimable (#152 R3).
- **R9 — Frontend (si Q6 est validée).**
  - Dans `SalesTable.vue`, le propriétaire et les admins voient sur chaque ligne une action « Modifier », qui ouvre le formulaire de vente prérempli et envoie un PATCH, et une action « Supprimer » avec confirmation.
  - `groupProjectRefreshes.ts` associe `sale_updated` et `sale_deleted` à `['sales', 'distribution']`.
  - Les erreurs 422 s'affichent par le message de `ApiValidationError`.
  - Une maquette `/frontend-design` est à produire avant le développement frontend. Elle ne l'est pas ici : ce lot est limité à `docs/specs/`.

## Exemples chiffrés

### Items d'un projet (`POST /group-industry/projects`)

Item de base : `{typeId: 12005, typeName: "Ishtar", meLevel: 10, teLevel: 20, runs: 5}`. Seule la clé indiquée change. « 400 (noyau) » veut dire que la saisie passe la validation de l'input puis est refusée par les objets du noyau pendant le calcul de la BOM, avec un message qui ne nomme pas l'item (ex. « A job has at least 1 run, got 0. »).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| I1 | `runs: 0` | 400 (noyau) | 422 sur `items[0][runs]`, rien de persisté |
| I2 | `runs: -5` | 400 (noyau) | 422 sur `items[0][runs]` |
| I3 | `runs: 100000` | accepté, sans borne haute | inchangé (borne haute hors lot, Q5) |
| I4 | `meLevel: 11` | 400 (noyau) pour une fabrication ; accepté, ignoré pour une réaction | 422 sur `items[0][meLevel]` |
| I5 | `meLevel: -1` | idem | 422 sur `items[0][meLevel]` |
| I6 | `teLevel: 21` | idem | 422 sur `items[0][teLevel]` |
| I7 | `meLevel: 0, teLevel: 0` | accepté | accepté (garde-fou) |
| I8 | `teLevel: 15` (impair, dans la plage) | 400 (noyau) pour une fabrication ; accepté, ignoré pour une réaction | inchangé : 400 (noyau) pour une fabrication, `TimeEfficiency` exige un niveau pair |
| I9 | item sans clé `runs` | 500 (`createProject()` `:81`) | 422 sur `items[0][runs]` |
| I10 | `typeId: 0, typeName: "Ishtar"` (collage en masse) | accepté, type résolu par le nom | inchangé (garde-fou, `CreateGroupProjectProcessorTest::testResolvesTypeIdFromNameWhenTypeIdIsZero`) |
| I11 | `typeId: -3` | 400 « No blueprint or reaction found for: … » (`CreateGroupProjectProcessor::resolveItemTypeIds()`, blueprint introuvable) | 422 sur `items[0][typeId]` |
| I12 | `typeName: ""` | accepté si `typeId` a un blueprint (le nom n'est pas contrôlé) | 422 sur `items[0][typeName]` |
| I13 | deux items de produits différents, le second à `runs: 0` | 400 (noyau) | 422 sur `items[1][runs]`, aucun projet créé |
| I14 | collage `Ishtar 12 25 x0` | 400 (noyau), une seule erreur (ME 12) | 422, trois violations : `items[0][meLevel]`, `items[0][teLevel]`, `items[0][runs]` |
| I15 | deux items du même produit au même ME/TE, `runs: 5` puis `runs: 0` | accepté : les runs se somment (`targetsOf()` `:173-175`), l'item à 0 run est enregistré | 422 sur `items[1][runs]`, aucun projet créé |

### Saisie d'une vente (`POST …/sales`, par un admin, projet `selling`)

Vente de base : `{typeId: 0, typeName: "Ishtar", quantity: 5, unitPrice: 1200000}`. Seul le champ indiqué change.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| S1 | vente de base, `soldAt: "2026-10-25T14:30:00+00:00"` | 201, total 6 000 000, `soldAt` gardé | inchangé (garde-fou) |
| S2 | `soldAt: "not-a-date"` | 500 | 422 sur `soldAt`, aucune vente persistée, aucun Mercure |
| S3 | `soldAt: "2026-02-30T10:00:00+00:00"` | 201, `soldAt` = 2026-03-02T10:00:00+00:00 | 422 sur `soldAt` |
| S4 | `soldAt: "tomorrow"` | 201, minuit du lendemain | 422 sur `soldAt` |
| S5 | `soldAt: "2026-10-25T14:30:00Z"` | 201 | 201, `soldAt` = 2026-10-25T14:30:00+00:00 |
| S6 | `soldAt: "2026-10-25"` (date seule) | 201, minuit | 422 sur `soldAt` (Q4) |
| S7 | `soldAt` absent | 201, date de l'enregistrement | inchangé (garde-fou) |
| S8 | `quantity: 0` | 400 « Quantity must be positive » | 422 sur `quantity` |
| S9 | `quantity: -2` | 400 | 422 sur `quantity` |
| S10 | `quantity` absent | 500 | 422 sur `quantity` |
| S11 | `quantity: 2147483648` | 500 (colonne `integer`) | 422 sur `quantity` |
| S12 | `unitPrice: -1` | 400 « Unit price must not be negative » | 422 sur `unitPrice` |
| S13 | `unitPrice: 0` | 201, total 0 | inchangé (garde-fou) |
| S14 | `typeName: ""` | 201 | 422 sur `typeName` |
| S15 | `typeName` de 256 caractères | 500 | 422 sur `typeName` |
| S16 | `venue` de 256 caractères | 500 | 422 sur `venue` |
| S17 | vente valide sur un projet `completed` | 400 | 400 (garde-fou, #152 E6) |

### Correction et suppression (projet `selling`, vente V = 2 × 900 000, total 1 800 000, saisie par un admin)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| C1 | un admin envoie `PATCH V {unitPrice: 950000}` | opération inexistante | 200 : `unitPrice` 950 000, `quantity` 2, total 1 900 000, `recordedByCharacterName` et `createdAt` inchangés ; Mercure `sale_updated` |
| C2 | le propriétaire envoie `PATCH V {quantity: 3, soldAt: "2026-10-24T09:00:00+00:00"}` | — | 200 : total 2 700 000, `soldAt` changé |
| C3 | `PATCH V {quantity: 0}` | — | 422 sur `quantity`, V inchangée |
| C4 | `PATCH V {soldAt: "not-a-date"}` | — | 422 sur `soldAt`, V inchangée |
| C5 | `PATCH V {venue: null}` | — | 200, `venue` inchangé (null = inchangé) |
| C6 | un membre `accepted` de rôle `member` corrige V | — | 403, V inchangée |
| C7 | le propriétaire corrige V par l'URI d'un autre projet dont il est propriétaire | — | 404 |
| C8 | correction d'une vente inconnue | — | 404 |
| C9 | correction de V sur un projet `completed` | — | 400, V inchangée |
| C10 | correction d'une vente dont l'auteur a supprimé son compte (`recordedBy` null) | — | 200, `recordedByCharacterName` toujours null |
| D1 | un admin supprime V | opération inexistante | 204, V absente de la liste ; Mercure `sale_deleted` |
| D2 | un membre `member` supprime V | — | 403, V conservée |
| D3 | suppression de V sur un projet `completed` | — | 400, V conservée |
| D4 | suppression de V par l'URI d'un autre projet | — | 404 |
| D5 | projet `in_progress`, V seule vente, aucune contribution approuvée : suppression de V, puis du projet par le propriétaire | — | 204 puis 204 (#152 R3) |

### Effet sur la distribution (R8)

Projet avec frais de courtage à 3 % et taxe de vente à 4 %. Contributions approuvées : 3 000 000 ISK pour le membre A, 2 000 000 ISK pour le membre B. Ventes : 5 × 1 200 000, puis V = 2 × 900 000.

| # | État des ventes | Revenu | Courtage | Taxe | Net | Marge | Payout A | Payout B |
|---|---|---|---|---|---|---|---|---|
| X1 | initial | 7 800 000 | 234 000 | 312 000 | 7 254 000 | 45,08 % | 4 352 400 | 2 901 600 |
| X2 | après C1 (V à 950 000) | 7 900 000 | 237 000 | 316 000 | 7 347 000 | 46,94 % | 4 408 200 | 2 938 800 |
| X3 | après D1 (V supprimée) | 6 000 000 | 180 000 | 240 000 | 5 580 000 | 11,60 % | 3 348 000 | 2 232 000 |

Montants en ISK, à comparer avec une tolérance (calculs en `float`).

## Cas limites

- **Ordre des erreurs à la saisie d'une vente.** Le `POST` n'a pas de provider : API Platform valide l'input avant le processor. Un non-admin qui envoie une date invalide reçoit donc 422, pas 403. Le `PATCH` et le `DELETE` passent par le provider, qui répond 404/403 avant toute validation.
- **ME et TE des items.** Ils ne s'appliquent qu'à la profondeur 0 : les composants fabriqués gardent ME 10 / TE 20 (`ProductionPlanner::MANUFACTURED_INTERMEDIATE_ME` et `_TE`, `ProductionPlanner.php:15-16`, `:169`) et les réactions ME 0 / TE 0 (`targetsOf()` `:170-172`). `GroupIndustryProjectService::flattenTree()` n'existe plus (supprimée par #141). R1 ne change rien à ce calcul.
- **Item sans blueprint.** Déjà refusé en 400 par `CreateGroupProjectProcessor::resolveItemTypeIds()` (« No blueprint or reaction found for: … »). Inchangé : ce n'est pas une erreur de forme.
- **Clés en trop dans un item.** Refusées en 422, car la contrainte `Collection` refuse les champs non déclarés. Le formulaire n'envoie que les cinq clés.
- **TE impair (ex. 15).** Refusé en 400 par `TimeEfficiency` du noyau pour une fabrication (`LEVEL_STEP`, `TimeEfficiency.php:17-18`), que R1 ne double pas par une contrainte de pas (Q5). Pour une réaction, ME et TE sont ignorés.
- **Prix unitaire à 0.** Accepté par l'API comme aujourd'hui ; seul le formulaire le bloque.
- **`typeId` 0 sur une vente.** C'est le cas de toutes les ventes saisies par le formulaire. Il reste accepté : aucun type n'est inventé, et le nom saisi reste la seule information.
- **Vente datée dans le futur.** Acceptée : aucune borne de date.

## Hors périmètre

- Sélecteur de type et `typeId` réel dans le formulaire de vente (aujourd'hui toujours 0).
- Effacer le lieu d'une vente par PATCH (`null` = inchangé).
- Historique ou trace des corrections (auteur, date de modification) : il faudrait des colonnes et une migration (Q3).
- Contrôle de la quantité vendue par rapport à la quantité produite par le projet.
- Modification des items d'un projet après sa création (aucune opération ne le permet aujourd'hui).
- Erreurs de type JSON (`"quantity": "abc"`) : elles restent en 400 (dénormalisation d'API Platform), sans changement de configuration.
- Restreindre les ventes au statut `selling` (déjà hors périmètre de #152).

## Fichiers concernés (indicatif)

- Backend :
  - `CreateGroupProjectInput` (`All` + `Collection` sur `$items`) ;
  - `RecordSaleInput` (contraintes de R2) ;
  - nouvel `UpdateSaleInput` ;
  - `GroupIndustrySaleResource` (opérations `Patch` et `Delete`) ;
  - nouveau provider d'item de vente ;
  - nouveaux processors de correction et de suppression ;
  - `RecordSaleProcessor` (retrait des 400 de saisie).
- Frontend (si Q6 est validée) : `stores/group-industry/distribution.ts`, `stores/group-industry/types.ts`, `SalesTable.vue`, `groupProjectRefreshes.ts`, locales i18n.
- Aucune migration, aucune nouvelle dépendance.

## Tests existants appelés à changer

- `RecordSaleProcessorTest` (deux tests : projet terminé, projet `selling`) : aucun ne fige les 400 de saisie, rien ne casse.
- `CreateGroupProjectProcessorTest` appelle le processor sans le validator : rien ne casse. Les tests rouges des contraintes suivent le modèle `tests/Unit/ApiResource/Input/Industry/CreateProjectInputValidationTest` (`Validation::createValidatorBuilder()->enableAttributeMapping()`).

## Glossaire

Proposé, à ajouter seulement après validation (section « Industrie collaborative ») :

- **Vente** (Sale) : vente d'un produit d'un projet collaboratif, saisie par le propriétaire ou un admin. Elle porte une quantité en unités, un prix unitaire, un lieu libre et une date ; son total vaut quantité × prix unitaire. Les ventes forment le revenu de la distribution. Une vente se corrige et se supprime tant que le projet n'est pas terminé (spec #164). Symboles : `GroupIndustrySale`, `RecordSaleProcessor`, `GroupIndustrySaleResource`.
- Le `venue` libre d'une vente (« Jita 4-4 ») n'est pas le **Lieu de vente** du glossaire (`jita` ou `structure`, pour le calcul d'une marge). À ne pas fusionner.

## 🚦 Questions pour Sylvain

1. **Qui corrige et supprime une vente ?** Recommandé : le propriétaire ou un admin `accepted`, quel que soit l'auteur de la vente, comme pour l'enregistrement. Les alternatives sont le propriétaire seul (lecture littérale de l'issue) ou l'auteur seul. Oui / non ?
2. **Projet terminé.** Correction et suppression refusées en 400 sur un projet `completed`, comme l'enregistrement (#152) ? Recommandé : oui.
3. **Trace des corrections.** Aucune trace en base dans ce lot (pas de migration ; seul l'événement Mercure `sale_updated`) ? Recommandé : oui. Des colonnes `updatedAt` / `updatedBy` demanderaient une migration.
4. **Format de `soldAt`.** ISO 8601 avec fuseau (`DATE_ATOM`, `Z` accepté), qui refuse la date seule, les millisecondes et les dates relatives ? Recommandé : oui. Un futur sélecteur de date côté frontend convertira dans ce format.
5. **Bornes des items.** Bornes basses retenues pour la boucle de nuit : `runs` ≥ 1, ME de 0 à 10, TE de 0 à 20 (422), le TE impair restant refusé en 400 par le noyau. **La borne haute des runs est hors lot, en attente de Sylvain** (recouvre #257). La borne 1 à 100 000 du projet solo (`CreateProjectInput::MAXIMUM_PROJECT_RUNS`) ne tient plus depuis #141 : le nombre de lignes de BOM persistées vaut `ceil(runs / plafond)` par produit, et le plafond SDE vaut 1 pour Fenrir, Widow, Nomad et Marshal, donc 10 items × 100 000 runs peuvent écrire 1 000 000 lignes. Options : A borne plus basse (ex. 1 000 runs) ; B borne sur le nombre de lignes de BOM produites. Recommandation : B, à confirmer.
6. **Frontend dans ce lot.** Actions « Modifier » et « Supprimer » dans `SalesTable.vue` pour le propriétaire et les admins, avec une maquette avant le développement ? Recommandé : oui. Sans frontend, la correction n'est accessible que par l'API.
