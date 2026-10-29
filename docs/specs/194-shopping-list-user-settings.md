# Spec #194 — Liste d'achats d'un projet : structure de marché et tarif de transport pris dans les réglages de l'utilisateur

**Statut : validée en autonomie en mode nuit le 2026-10-29, à relire par Sylvain.**

Circuit : `circuit:m` (pas de note de câblage). Tests unitaires seulement, pas de base : `Security`, dépôts, `MarketService`, `JitaMarketService` doublés ; utilisateur, projet, étapes et réglages industrie en vraies entités, sur le harnais golden existant (`IndustryShoppingListBuilder` réel). Tests de composants front (Vitest, `en.json`).

## Contexte

**Liste d'achats** — `GET /api/industry/projects/{id}/shopping-list` (`ProjectResource.php:61-66`, sortie `ShoppingListResource`, provider `ShoppingListProvider`).

- Structure (`ShoppingListProvider.php:75-77`) : paramètre de requête `structureId` s'il est présent, sinon `$user->getPreferredMarketStructureId()`, qui peut valoir `null`. La valeur part telle quelle dans `MarketService::comparePrices()` (`:98`), qui remplace `null` par `app.default_market_structure_id` (`MarketService.php:199-201`).
- Tarif de transport (`:78`) : paramètre `transportCost`, sinon le littéral `1200`, écrit deux fois. Coût d'import d'un matériau = volume × quantité × tarif (`:142`). Le tarif retenu est rendu dans `transportCostPerM3` (`:242`). `IndustryUserSettings` n'est pas lu.
- Si `comparePrices()` échoue, `structureId` et `structureName` de la réponse prennent ceux du défaut d'instance (`:237-238`), même quand la liste a été demandée pour une autre structure.
- Le défaut d'instance existe bien : `app.default_market_structure_id` = `1049588174021` et `app.default_market_structure_name` = `C-J6MT - 1st Taj Mahgoon (Keepstar)` (`config/services.yaml:8-9`), injectés par `bind` (`:16-17`) et exposés par `MarketService::getDefaultStructureId()` / `getDefaultStructureName()` (`MarketService.php:281-289`). Même cascade « paramètre explicite > préférence > défaut d'instance » dans `SyncMarketProcessor.php:44-47` et `ParseProcessor.php:49`.

**Front** — `ShoppingTab.vue` et `ShoppingConfigPanel.vue`.

- `DEFAULT_STRUCTURE_ID = 1049588174021` et `DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon'` (`ShoppingTab.vue:104-105`) recopient le défaut d'instance (le nom diffère : sans « (Keepstar) »). Ce sont eux, le doublon signalé par l'issue ; le provider n'a pas de littéral de structure.
- `loadPersistedStructure()` (`:107-118`) lit la structure choisie dans l'onglet, gardée dans `localStorage` sous `industry_shopping_structure` (`:103`, écrite par `saveStructureToStorage()`, `:120-126`). Sans choix, elle rend `DEFAULT_STRUCTURE_ID`. `fetchShoppingList()` (`frontend/src/stores/industry/projects.ts:146-164`) n'ajoute `structureId` que s'il est non nul (`:152`) : il part donc **toujours**, et la préférence de l'utilisateur n'est jamais lue.
- `transportCostPerM3 = ref(1200)` (`ShoppingTab.vue:75`) part à chaque appel (`:316`, `projects.ts:153`). Champ éditable « Coût transport / m3 » (`ShoppingConfigPanel.vue:164-178`), sans persistance, recalcul par le bouton « Recalculer » (`:179-184`). Le `transportCostPerM3` de la réponse n'est pas lu.
- Le placeholder du sélecteur de structure affiche `selectedStructure.name || defaultStructureName` (`ShoppingConfigPanel.vue:109`), prop alimentée par `DEFAULT_STRUCTURE_NAME` (`ShoppingTab.vue:513`). Le bouton « Revenir au défaut » émet `{ id: null, name: '' }` (`ShoppingConfigPanel.vue:86-90`), ce qui efface le choix stocké.
- « Synchroniser » le marché structure envoie `{ structureId: selectedStructure.id ?? undefined }` (`ShoppingTab.vue:354-358`) : le défaut en dur est envoyé là aussi.

**Réglages de l'utilisateur.**

- **Tarif** : `IndustryUserSettings::$exportCostPerM3`, `float` non nullable, défaut `EveConstants::DEFAULT_EXPORT_COST_PER_M3` = 1200.0 (`IndustryUserSettings.php:44-46`, `EveConstants.php:16`). Une ligne n'existe qu'après un premier enregistrement des frais (`UpdateUserSettingsProcessor.php`, Industry). Saisi dans `FeesConfig.vue:13`, `:35-39` (onglet configuration, `Industry.vue:516`), borné à `PositiveOrZero` (`UpdateUserSettingsInput.php:23-24`). Sans ligne, `GET /api/industry/settings` affiche 1200 (`UserSettingsResource.php:48`, Industry).
- **Seul lecteur du tarif** : le scanner. `BatchScanProvider.php:57-61` lit `$settings?->getExportCostPerM3() ?? EveConstants::DEFAULT_EXPORT_COST_PER_M3`. Le scanner l'applique à l'import des matériaux et à l'export du produit (`BatchProfitScannerService.php:324`, `:333-336`, `transportCost()` `:599-606`).
- **Structure** : contrairement à ce qu'écrit l'issue, elle n'est pas dans `IndustryUserSettings`. Elle est sur l'utilisateur : `User::$preferredMarketStructureId` et `$preferredMarketStructureName` (`User.php:44`, `:48`, getter `:203-206`). Aucun écran ne l'écrit aujourd'hui : le modal de réglages n'envoie que la liste `marketStructures` (`MainLayout.vue:373-380`), et le PATCH en merge garde la valeur existante (`UpdateUserSettingsProcessor.php:39-41`, UserSettings). `UserSettingsProvider.php:42-46` la qualifie de préférence « legacy ». Tous les providers backend la lisent pourtant (comparaison de prix, scanner en vente structure, Buy vs Build, marges, historique, alertes). La sync planifiée des marchés structure ne couvre que le défaut d'instance et ces préférences (`TriggerStructureMarketSyncHandler.php:94-106`).

**Glossaire.** « Structure de marché préférée » (`docs/glossary.md:142`), « Coût de transport » (`:148`, « volume × tarif au m³ des réglages », tarif à 0 → 0 connu), ambiguïté « Structure de marché par défaut appliquée inégalement » (`:194`).

## Règles métier

- **R1 — Structure de la liste d'achats.** Première valeur présente dans cet ordre :
  1. paramètre `structureId` de la requête ;
  2. `User::getPreferredMarketStructureId()` non nul ;
  3. le défaut d'instance, `MarketService::getDefaultStructureId()` (`app.default_market_structure_id`).

  Le provider résout la structure lui-même et passe toujours un entier à `comparePrices()`.
- **R2 — Tarif de transport.** Première valeur présente dans cet ordre :
  1. paramètre `transportCost` de la requête, `0` compris ;
  2. `IndustryUserSettings::getExportCostPerM3()` de l'utilisateur, lu par `IndustryUserSettingsRepository::findOneBy(['user' => $user])` ;
  3. sans ligne de réglages, `EveConstants::DEFAULT_EXPORT_COST_PER_M3`.

  C'est la même expression que `BatchScanProvider.php:61` : à réglage égal, le scanner et la liste d'achats appliquent le même tarif au m³. Le dépôt n'est pas lu quand `transportCost` est fourni. Le littéral `1200` disparaît du provider.
- **R3 — Réponse.** `transportCostPerM3` rend le tarif retenu par R2. Quand le marché répond, `structureId` et `structureName` sont ceux de `comparePrices()`, comme aujourd'hui. Les calculs (import, « Jita + import », meilleur lieu, économies, totaux) ne changent pas, seules leurs entrées changent.
- **R4 — Pas de valeur inventée.** Il n'y a pas de cas « réglage `null » : `exportCostPerM3` n'est pas nullable. Un réglage absent est la ligne absente, et prend le défaut produit documenté (colonne, constante, scanner, écran des frais : 1200). Une préférence de structure `null` veut dire « aucune préférence » et prend le défaut d'instance (glossaire `:142`). Aucune erreur dans ces deux cas (D2).
- **R5 — Marché en échec.** Si `comparePrices()` lève une exception, `structureId` vaut la structure résolue par R1, et non plus le défaut d'instance. `structureName` vaut :
  - `User::getPreferredMarketStructureName()` si la structure résolue est la préférence ;
  - sinon `MarketService::getDefaultStructureName()` si c'est le défaut d'instance ;
  - sinon `null`.

  `priceError` et le reste sont inchangés.
- **R6 — Le front n'envoie que les choix faits dans l'onglet.**
  - `structureId` : seulement la structure choisie dans l'onglet (gardée dans `localStorage`, comportement actuel conservé, voir Q1). Sans choix, pas de paramètre.
  - `transportCost` : seulement un tarif saisi dans l'onglet depuis son montage. Sans saisie, pas de paramètre.
  - `DEFAULT_STRUCTURE_ID` et `DEFAULT_STRUCTURE_NAME` disparaissent du front. « Synchroniser » sans choix envoie `{}` : `SyncMarketProcessor` applique la même cascade que R1.
- **R7 — Valeurs affichées.**
  - Le champ « Coût transport / m3 » affiche le tarif saisi dans l'onglet s'il y en a un, sinon le `transportCostPerM3` de la dernière réponse : la valeur des réglages ou le défaut, celle-là même qui a servi au calcul.
  - Le placeholder du sélecteur affiche le nom choisi dans l'onglet, sinon le `structureName` de la dernière réponse.
  - Aucun appel supplémentaire à `/api/industry/settings` ni à `/api/me/settings`.
  - « Revenir au défaut » efface le choix (inchangé). Le recalcul suivant prend la préférence ou le défaut d'instance.

## Contrat de l'API

`GET /api/industry/projects/{id}/shopping-list` :

| Cas | Avant | Après |
|---|---|---|
| Sans paramètre, préférence X | X | X (inchangé côté API ; le front envoyait le défaut en dur) |
| Sans paramètre, sans préférence | `comparePrices(null)` → défaut d'instance | `comparePrices(défaut d'instance)`, même résultat |
| Sans `transportCost`, réglage 800 | 1200 | **800** |
| Sans `transportCost`, sans ligne de réglages | 1200 | 1200 (`EveConstants`) |
| `transportCost` fourni | sa valeur | inchangé |
| Marché en échec, structure résolue X | `structureId` = défaut, `structureName` = nom du défaut | `structureId` = X, `structureName` = nom de la préférence ou `null` (R5) |

`ShoppingListResource` garde sa forme ; `structureName` était déjà `?string`. Type front `ShoppingListResponse.structureName` : `string` → `string | null`. Pas de consommateur externe connu.

## Exemples chiffrés

Harnais golden (`ShoppingListProviderGoldenCharacterizationTest`) : un run de Nitrogen Fuel Block, ME 10. Nitrogen Isotopes : 405 u × 0,5 m³ = 202,5 m³, Jita 500 ISK/u, structure 1 200 ISK/u. Volume de tous les types listés : 414,625 m³. Structures : X = 1 000 000 000 002 (préférence, nom « Preferred Market »), R = 1 000 000 000 003 (demandée), D = 1 035 466 617 946 (défaut d'instance, « Default market »). Le marché répond avec `structureId` 1 000 000 000 001 (« Priced market ») sauf en E9 à E11.

| # | Données | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Préférence X, réglage 800, aucun paramètre (test de l'issue) | `comparePrices(…, X)`, tarif 1200, import isotopes 243 000, `totals.import` 497 550 | `comparePrices(…, X)` ; `transportCostPerM3` 800.0 ; isotopes : import 162 000.0, Jita + import 364 500.0, best `jita`, économie 121 500.0 ; `totals.import` 331 700.0 ; `findOneBy(['user' => U1])` une fois |
| E2 | Pas de préférence, réglage 950 | `comparePrices(…, null)`, 1200 | `comparePrices(…, D)` ; 950.0 ; import isotopes 192 375.0 ; `totals.import` 393 893.75 |
| E3 | Préférence X, aucune ligne de réglages | X, 1200 | inchangé : X, 1200.0, import isotopes 243 000.0, `totals.import` 497 550.0 |
| E4 | Préférence X, réglage 800, `?structureId=R&transportCost=650` | R, 650 | inchangé : R, 650.0, import isotopes 131 625.0, `totals.import` 269 506.25 ; dépôt de réglages **non lu** |
| E5 | Préférence X, réglage 800, `?transportCost=450` | X, 450 | inchangé : X, 450.0, import isotopes 91 125.0, `totals.import` 186 581.25 ; dépôt non lu |
| E6 | Préférence X, réglage 800, `?structureId=R` | R, 1200 | R, **800.0**, import isotopes 162 000.0 |
| E7 | Préférence X, réglage 0 | 1200 | 0.0 ; import isotopes 0.0 ; Jita + import isotopes 202 500.0 ; `totals.import` 0.0 |
| E8 | Réglage 800, `?transportCost=0` | 0 | inchangé : 0.0 ; dépôt non lu (le `0` explicite n'est pas « absent ») |
| E9 | Préférence X, marché en échec, aucun paramètre | `structureId` D, « Default market » | `structureId` X, `structureName` « Preferred Market » ; `priceError` renseigné |
| E10 | Pas de préférence, marché en échec | D, « Default market » | inchangé : D, « Default market » |
| E11 | Préférence X, marché en échec, `?structureId=R` | D, « Default market » | `structureId` R, `structureName` `null` |

Cohérence avec le scanner (`BatchScanProvider`, garde-fou, code inchangé) :

| # | Données | Attendu |
|---|---|---|
| E12 | Réglage 800, `GET /industry/profit-scan` | `scan()` reçoit `exportCostPerM3` 800.0, comme `transportCostPerM3` en E1 |
| E13 | Aucune ligne de réglages | `scan()` reçoit 1200.0, comme E3 |

Écran (`ShoppingTab` monté, `ShoppingConfigPanel` en stub, réponse : `structureId` X, `structureName` « Preferred Market », `transportCostPerM3` 800) :

| # | Données | Aujourd'hui | Attendu |
|---|---|---|---|
| F1 | `localStorage` vide, chargement | URL `…/shopping-list?structureId=1049588174021&transportCost=1200` ; champ 1200 | URL exactement `/industry/projects/{id}/shopping-list` ; props du panneau : `transportCostPerM3` 800, `effectiveStructureName` « Preferred Market », `selectedStructure` `{ id: null, name: '' }` |
| F2 | `localStorage` `industry_shopping_structure` = `{"id":1000000000003,"name":"Requested Market"}` | `?structureId=1000000000003&transportCost=1200` | `?structureId=1000000000003`, sans `transportCost` |
| F3 | F1, puis le panneau émet `update:transportCostPerM3` 650 et `recalculate` | `?structureId=1049588174021&transportCost=650` | `?transportCost=650` ; prop `transportCostPerM3` 650 avant et après la réponse |
| F4 | F1, puis `update:selectedStructure` `{ id: 1000000000004, name: 'Picked Market' }` et `recalculate` | `?structureId=1000000000004&transportCost=1200` | `?structureId=1000000000004` ; `localStorage` garde le choix (inchangé) |
| F5 | F4, puis `update:selectedStructure` `{ id: null, name: '' }` et `recalculate` | `?transportCost=1200` puis `localStorage` vidé | URL sans paramètre ; clé `industry_shopping_structure` absente |
| F6 | F1, puis `sync-structure` | POST `/shopping-list/sync-structure-market`, body `{"structureId":1049588174021}` | body `{}` |
| F7 | `ShoppingConfigPanel` seul, `selectedStructure` `{ id: null, name: '' }`, `effectiveStructureName` « Preferred Market » | placeholder « C-J6MT - 1st Taj Mahgoon » | placeholder « Preferred Market » |

## Cas limites

- **Préférence non synchronisée.** Une préférence X a ses prix en cache par la sync planifiée (`TriggerStructureMarketSyncHandler.php:100`). Sinon, le bandeau « prix structure indisponibles » existant s'affiche, et « Synchroniser » sans choix synchronise X (cascade de `SyncMarketProcessor`).
- **Choix de structure stocké dans le navigateur** (Q1). Il reste prioritaire sur la préférence, comme un paramètre explicite. Pour suivre sa préférence, l'utilisateur clique « Revenir au défaut ».
- **Tarif saisi dans l'onglet.** Il n'est ni enregistré ni gardé au rechargement, comme aujourd'hui. Il prime jusqu'au démontage de l'onglet. Le champ vidé envoie `0` (`Number('')`, `ShoppingConfigPanel.vue:171`), comme aujourd'hui.
- **Réglage modifié pendant que l'onglet est ouvert.** Sans saisie dans l'onglet, « Recalculer » relit le réglage (aucun paramètre envoyé). Avec une saisie, la saisie prime.
- **Tarif à 0 et volume inconnu.** La liste d'achats rend un import `null` (spec #104), le scanner 0.0 connu (spec #255 R4). L'écart existait déjà avant ce lot et reste en place (Q3).
- **Préférence égale au défaut d'instance.** En R5, le nom de la préférence passe avant celui du défaut. `null` seulement si aucun nom n'est stocké.
- **Projet terminé.** La liste n'est pas chargée (`activate()`, `ShoppingTab.vue:381-382`) et le panneau est masqué : inchangé.
- **Utilisateur sans personnage avec token.** Prix structure `null` (`MarketService.php`, branche `$token === null`), inchangé.

## Hors périmètre

- **Écran de la préférence de structure.** Aucun écran n'écrit `preferredMarketStructureId`. Pour un utilisateur qui n'a qu'une liste `marketStructures`, la liste d'achats sans choix prend le défaut d'instance (Q2).
- **Nom de structure introuvable.** `MarketService::comparePrices()` prend le nom du défaut d'instance quand le nom d'une autre structure est introuvable (`MarketService.php:202`) : la structure X est alors affichée sous le nom de C-J6MT. À ouvrir en issue.
- **Autres tarifs à 1200 en dur** : liste collée (`ParseListInput.php:16`, `ShoppingList.vue:88`), partage (`ShareListInput.php:22`, `SharedShoppingListProvider.php:40`), Appraisal (`Appraisal.vue:93`), minage (`MiningTab.vue:134`). Même défaut, autres écrans : à ouvrir en issue.
- **Validation des paramètres** `structureId` / `transportCost` (non numérique → 0 par cast, négatif accepté) : comportement actuel conservé.
- **`exportCostPerM3` mal nommé** (il sert aussi à l'import) : le renommer demande une migration.
- **Coût de transport saisi sur le projet** (`IndustryProject::$transportCost`, un montant total) : autre notion.
- **Littéraux 1200 restants** : `UserSettingsResource::$exportCostPerM3` (Industry, `:48`) et `FeesConfig.vue:13`.

## Décisions retenues en autonomie

- **D1 — Ordre de priorité : paramètre explicite > réglage > défaut documenté (R1, R2).** C'est la cascade déjà en place pour la sync et la liste collée (`SyncMarketProcessor.php:44-47`, `ParseProcessor.php:49`), et celle du scanner pour le tarif. Le paramètre explicite reste utile : c'est le choix ponctuel de l'onglet.
- **D2 — Réglage absent : défaut documenté, pas d'erreur (R4).** La règle de CLAUDE.md vise une donnée ESI manquante remplacée par une valeur plausible. Ici, c'est une préférence que l'utilisateur n'a jamais saisie, et son défaut est un choix produit déjà documenté et appliqué partout : colonne `default 1200`, `EveConstants`, scanner, écran des frais. Une erreur bloquerait la liste d'achats de tout utilisateur qui n'a jamais ouvert les frais.
- **D3 — Le front affiche les valeurs de la réponse (R7)**, il ne lit pas les réglages dans un store. La valeur affichée est donc celle qui a servi au calcul, sans requête de plus ni risque d'écart entre deux lectures. Le panneau reçoit le nom effectif (`effectiveStructureName`, renommage de `defaultStructureName`) et un tarif `number | null`.
- **D4 — Le choix de structure de l'onglet reste dans `localStorage`** (Q1, option a). Seul le défaut en dur disparaît. Le test de l'issue (« sans paramètre explicite ») porte sur un utilisateur qui n'a rien choisi dans l'onglet.
- **D5 — Préférence = `User::getPreferredMarketStructureId()`** (Q2, option a), comme l'issue et tous les autres providers. La liste `marketStructures` n'est pas lue.
- **D6 — Repli R5 sur la structure résolue.** Afficher le nom du défaut d'instance pour une liste demandée sur X est le même défaut que celui de l'issue. Trois cas, sans appel ESI.
- **D7 — Pas d'extraction commune avec `BatchScanProvider`.** L'expression tient en une ligne sur la même constante, et E12/E13 figent l'égalité. Une méthode de dépôt partagée casserait le harnais de `BatchScanProviderTest` (un stub rend 0.0 pour une méthode non configurée) sans rien simplifier.
- **D8 — Pas de maquette.** Aucun élément visuel nouveau : seuls la valeur initiale du champ tarif et le placeholder de la structure changent.
- **D9 — Montants visibles qui changent : c'est le but de l'issue.** Changements au premier chargement après déploiement :
  - utilisateurs dont `exportCostPerM3` ≠ 1200 : import, « Jita + import », meilleur lieu, économies et totaux ;
  - utilisateurs avec une préférence ≠ C-J6MT et sans choix dans l'onglet : prix structure.
  - Un `materialCost` déjà appliqué par « Appliquer comme coût matériaux » est un montant enregistré : il ne bouge pas tant que l'utilisateur ne l'applique pas de nouveau.

  Pas de migration, pas de message Messenger, **aucune commande à lancer en production**. À mentionner dans les notes de version.

## Blocage : options

Aucun blocage pour ce lot. Trois décisions produit sont prises par défaut, à confirmer :

- **Q1 — Choix de structure gardé dans le navigateur.**
  - (a) Le garder comme choix explicite prioritaire. **Recommandé, retenu.** Aucun écran ne permet de fixer la préférence (Q2) : le supprimer ramènerait ces utilisateurs au défaut d'instance, sans autre moyen de choisir.
  - (b) Le supprimer : la préférence s'applique à tous, mais un choix déjà fait est perdu.
- **Q2 — Quelle structure est « celle de l'utilisateur ».**
  - (a) `preferredMarketStructureId`, comme le reste du backend. **Recommandé, retenu**, avec une issue à ouvrir : « l'écran des réglages ne permet plus de choisir la structure préférée ». Ce trou touche tous les écrans.
  - (b) La préférence, sinon la première de `marketStructures` (le repli de `BatchScanTab.vue:295-302`). La liste d'achats divergerait alors des autres providers, et ses prix ne seraient pas couverts par la sync planifiée.
- **Q3 — Tarif à 0 et volume inconnu** (liste d'achats `null`, scanner 0.0).
  - (a) Laisser l'écart et ouvrir une issue. **Recommandé, retenu** : sans rapport avec la source du tarif.
  - (b) Aligner ici la liste d'achats sur la spec #255 R4.

## Fichiers de production à modifier

| Fichier | Changement |
|---|---|
| `src/State/Provider/Industry/ShoppingListProvider.php` | Dépendance `IndustryUserSettingsRepository` (après `JitaMarketService`) ; structure résolue par R1 (`getDefaultStructureId()` en dernier) ; tarif par R2 (`EveConstants::DEFAULT_EXPORT_COST_PER_M3`, littéral `1200` retiré, dépôt lu seulement sans `transportCost`) ; repli R5 sur la structure résolue |
| `frontend/src/components/industry/ShoppingTab.vue` | Retrait de `DEFAULT_STRUCTURE_ID` / `DEFAULT_STRUCTURE_NAME` ; `loadPersistedStructure()` rend `{ id: null, name: '' }` sans choix stocké ; tarif saisi (`number \| null`, `null` au montage) distinct du tarif de la réponse ; `fetchShoppingList(projectId, choix ?? undefined, saisie ?? undefined)` ; props `effective-structure-name` (← `structureName` de la réponse) et `transport-cost-per-m3` (saisie, sinon réponse) (R6, R7) |
| `frontend/src/components/industry/shopping/ShoppingConfigPanel.vue` | Prop `defaultStructureName` renommée `effectiveStructureName: string \| null` (placeholder) ; `transportCostPerM3: number \| null` |
| `frontend/src/stores/industry/types.ts` | `ShoppingListResponse.structureName: string \| null` (R5) |

`projects.ts`, `SyncMarketProcessor`, `BatchScanProvider`, les entités et `config/services.yaml` ne changent pas.

## Câblage des tests (tests unitaires)

**Tests existants appelés à casser :**

- `tests/Unit/State/Provider/Industry/ShoppingListProviderGoldenCharacterizationTest.php`, `provideShoppingList()` (l.909-918) : le constructeur prend un `IndustryUserSettingsRepository` de plus.
  - Correction du harnais : un stub dont `findOneBy` enregistre ses critères (comme `$comparePricesCalls`) et rend `private ?IndustryUserSettings $settings = null`.
  - Avec `null`, le tarif reste 1200.0 : toutes les assertions existantes tiennent, `testProvideReadsTheMarketStructureOfTheRequestAndKeepsTheDefaultTransportCost` (l.431-440) compris.
  - Sans préférence, `comparePrices` reçoit désormais D au lieu de `null`. Aucun test n'asserte `null`. `testPricedShoppingListWithoutMarketKeepsTheQuantitiesAndTheImportAndLeavesEveryPriceTotalUnknown` (l.565-587) attend toujours D.
- Front : les specs `shoppingTab*.spec.ts` comparent l'URL par `stringContaining('/shopping-list')` et mettent `ShoppingConfigPanel` en stub. Le renommage de prop et la requête sans paramètre ne les cassent pas. Aucun test ne lit `industry_shopping_structure` (`grep -rn industry_shopping_structure frontend/tests` vide).

**Tests rouges attendus** (`#[Group('issue-194')]`) :

- `ShoppingListProviderGoldenCharacterizationTest` : E1 à E11.
  - `$this->pilot->setPreferredMarketStructureId(X)` et `setPreferredMarketStructureName('Preferred Market')` ; réglages en vraie entité, `(new IndustryUserSettings())->setUser($this->pilot)->setExportCostPerM3(800.0)`.
  - E4, E5 et E8 assertent que `findOneBy` n'a pas été appelé (liste des critères vide). E1 asserte `[['user' => $this->pilot]]`.
  - E9 à E11 : `marketUnavailable = true`. Le stub `MarketService` rend déjà D et « Default market » (l.874-876).
- `tests/Unit/State/Provider/Industry/BatchScanProviderTest.php` : E12 et E13. Scanner en mock capturant l'argument `exportCostPerM3` ; garde-fou de cohérence, vert dès l'écriture.
- `frontend/tests/components/industry/shoppingTabUserSettings.spec.ts` (nouveau) : F1 à F6.
  - Montage comme `shoppingTabUnknownPrice.spec.ts` : `apiRequest` mocké par URL, `ShoppingConfigPanel` en stub, `localStorage.clear()` en `beforeEach`.
  - Émissions depuis le stub (`findComponent({ name: 'ShoppingConfigPanel' }).vm.$emit(…)`), puis `props()` du stub et premier argument des appels à `apiRequest`.
- `frontend/tests/components/industry/shoppingConfigPanelEffectiveStructure.spec.ts` (nouveau) : F7, panneau monté seul (`useAuthStore` avec Pinia réel, aucune recherche lancée).

## Glossaire (proposé, à valider)

- Ajout : **Tarif de transport** (Transport rate). ISK par m³ appliqué au **coût de transport** : réglage de l'utilisateur, 1 200 ISK/m³ sans réglage. Le scanner et la liste d'achats d'un projet lisent le même réglage ; la liste d'achats accepte un tarif saisi pour un calcul (spec #194). Le nom de code `exportCostPerM3` est historique : le tarif sert aussi à l'import. Code : `IndustryUserSettings::$exportCostPerM3`, `EveConstants::DEFAULT_EXPORT_COST_PER_M3`, `ShoppingListResource::$transportCostPerM3`, paramètre `transportCost`.
- Ajout : **Structure de marché par défaut** (Default market structure). Structure joueur fixée par la configuration de l'instance, prise quand l'utilisateur n'a pas de **structure de marché préférée** et n'a rien choisi à l'écran. Code : `app.default_market_structure_id`, `app.default_market_structure_name`, `MarketService::getDefaultStructureId()`.
- Ajout : **Choix ponctuel** (One-off choice). Structure ou tarif choisi dans l'onglet de la liste d'achats pour un calcul, prioritaire sur les réglages et envoyé en paramètre de requête. La structure est gardée dans le navigateur ; le tarif ne l'est pas (spec #194). Code : `structureId`, `transportCost` (`ShoppingListProvider`).
- Modification de **Coût de transport** (`docs/glossary.md:148`) : remplacer « tarif au m³ des réglages » par « **tarif de transport** ».
- Modification de l'ambiguïté **Structure de marché par défaut appliquée inégalement** (`:194`) : ajouter « La liste d'achats d'un projet suit la structure préférée, puis la structure par défaut (spec #194). Aucun écran n'écrit plus la structure préférée : seule la liste `marketStructures` est éditable. »
