# Spec #115 (lot 2, services industrie) — Un type absent du SDE a un nom inconnu dans les analyses industrie

## Contexte

Le lot 1 (`115-type-name-missing-from-sde.md`, 863c3e3) a introduit le **nom de type inconnu** sur le chemin des projets : `TypeNameResolver::findName()` renvoie `null`, l'API expose `null` à côté du typeId, le front affiche « Nom inconnu (#id) » (`useTypeNames().typeNameLabel()`).

Quatre services d'analyse industrie inventent encore `"Type #<typeId>"`, soit par `TypeNameResolver::resolve()` / `resolveMany()`, soit en direct :

- `PivotAdvisorService` : `:145`, `:227`, `:245`, `:332`, `:466`, `:473`, `:487`, `:539` ;
- `BatchProfitScannerService` : `:268`, `:382` ;
- `InventionService` : `:186` (matériaux d'invention), `:406` (`productName` du kit BPC), `:468-469` (`blueprintName`, `productTypeName` des copies) ;
- `SlotTrackerService` : `:82`, `:139` (`productTypeName` d'un job), `:361` (suggestion de slot libre).

## Règles métier

- **R1 — Nom inconnu.** Même règle que le lot 1 : un type absent de `invTypes` a un nom `null` dans la sortie de ces services et dans l'API, avec son typeId toujours renseigné. Aucun `"Type #<id>"`.
- **R2 — Résolution groupée.** `TypeNameResolver::findNames(array $typeIds): array<int, string>` renvoie les noms connus, indexés par typeId ; un type absent n'a pas d'entrée. Les quatre services l'utilisent (ou `findName()` pour un type seul) à la place de `resolve()` / `resolveMany()`. `resolve()` et `resolveMany()` ne changent pas (lots 3 et 4).
- **R3 — Calculs inchangés.** Un nom inconnu ne change ni les marges, coûts, volumes, probabilités, ni le nombre ou l'ordre des éléments renvoyés.
- **R4 — Suggestion de slot libre.** `SlotTrackerService` garde sa priorité actuelle : nom du SDE, sinon le nom enregistré sur la cible de stockpile (`IndustryStockpileTarget::$typeName`, non nullable) : la suggestion a donc toujours un nom.
- **R5 — Front.** Les écrans Pivot Advisor, Batch Scan, Kit BPC, coûts de copie, Slot Tracker et marges (datacores d'invention) affichent un nom `null` avec `typeNameLabel()` (« Nom inconnu (#id) »). Le tri par nom du Batch Scan utilise ce texte affiché.

## Décision prise en autonomie

- **Export multibuy du Batch Scan** (`BatchScanTab.vue:180`, `typeName\t1`) : un produit au nom inconnu est **exclu** de l'export, car le jeu ne reconnaît pas « Nom inconnu (#id) ». C'est l'option conservatrice : l'export ne contient que des lignes que le jeu sait lire. Les autres lignes restent exportées.
- **Datacores de l'écran Marges** : la sortie de `ProfitMarginService` ne porte pas de typeId pour les datacores (golden figé, branche CP en cours) : un nom inconnu s'y affiche « Nom inconnu », sans `#id` (clé `common.unknownName`). Ajouter le typeId reviendra à la migration de ProfitMargin sur le moteur.
- **Timeline du Slot Tracker** : `productTypeId` ajouté à chaque job de la timeline, seul moyen d'afficher « Nom inconnu (#id) ».
- **Harnais** : les stubs de `resolveMany()` / `resolve()` des tests Pivot Advisor, Slot Tracker et règles du scanner passent à `findNames()` / `findName()`, assertions inchangées (oubli de la phase rouge, repris avant la phase verte).
- **Pas de test front** pour R5 (pas de harnais composant) : vérifié par le typecheck et à la lecture du diff.

## Exemples chiffrés

Types : Nitrogen Fuel Block 4051 (connu), Robotics 9848 (absent de `invTypes`).

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | `findNames()` | `[4051, 9848]` | `[4051 => 'Nitrogen Fuel Block']`, pas d'entrée 9848 ; `[]` → `[]` |
| E2 | Garde-fou : tous les noms connus | une analyse de chaque service sur des types connus | noms du SDE, sortie inchangée |
| E3 | Pivot Advisor, matériau inconnu | matériau 9848 dans la matrice d'un produit candidat | `typeName = null` pour 9848, quantités et coûts inchangés |
| E4 | Pivot Advisor, produit source inconnu sans candidat | `emptyResult(9848)` | `sourceProduct.typeName = null`, `typeId = 9848` |
| E5 | Pivot Advisor, produit candidat inconnu | candidat 9848 | `typeName = null`, marge et classement inchangés |
| E6 | Batch Scan, produit inconnu | produit 9848 dans le scan | `typeName = null`, marge, profit et ISK/jour inchangés, la ligne reste listée |
| E7 | Invention, matériau inconnu | datacore 9848 d'un T1 | `getInventionData()` : `materials[].typeName = null`, `quantity` inchangée ; même `typeName = null` dans les datacores de `calculateInventionCost()` |
| E8 | Kit BPC, produit inconnu | projet T2 dont le produit est 9848 | `inventions[].productName = null`, probabilité et coûts inchangés |
| E9 | Coûts de copie, types inconnus | étape de copie du blueprint 9848 produisant 9848 | `copies[].blueprintName = null`, `productTypeName = null`, `cost` inchangé |
| E10 | Slot Tracker, job inconnu | job ESI produisant 9848 | `productTypeName = null`, temps restant et tri inchangés |
| E11 | Slot Tracker, suggestion avec nom enregistré | cible de stockpile 9848 nommée « Robotics » en base | `suggestion.typeName = "Robotics"` |

## Cas limites

- Un type connu du SDE garde exactement son nom actuel (E2).
- Les shapes PHPDoc (`typeName: string`) passent en `string|null` là où la valeur peut être inconnue, y compris dans `ProfitMarginService` qui relaie les datacores d'`InventionService` (signature uniquement, pas de changement de logique).

## Hors périmètre

- `ProfitMarginService` et `BuyVsBuildService` (sauf ajustement de type PHPDoc relayé) : branches CP / CS.
- Lots 3 (syncs qui enregistrent un nom) et 4 (lecture hors industrie, suppression de `resolve()` / `resolveMany()`).
- `GroupIndustryProjectService` (thread #23), statut d'autorisation (thread #86).

## Glossaire

Aucun terme ajouté (**Nom de type inconnu** existe depuis le lot 1). Symbole `TypeNameResolver::findNames()` ajouté à sa ligne.

## Tests impactés

À identifier par la phase rouge : tests qui figent `"Type #"` dans `PivotAdvisorServiceTest`, `BatchProfitScannerServiceTest`, `InventionServiceTest`, `SlotTrackerServiceTest` (aucun relevé au grep sur `Type #` ; les mocks de `resolveMany()` devront passer à `findNames()`).
