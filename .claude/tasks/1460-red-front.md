# #1460 phase rouge, partie front

## Fichiers

- `frontend/tests/utils/planetarySupplyTransfer.spec.ts` (10 tests, calcul pur `planStockTransfers`)
- `frontend/tests/components/planetary/piSupplyStockTransfer.spec.ts` (5 tests, bloc « Du stock au personnage » de `PiSupplyTab`)
- `frontend/tests/planetarySupplyTransferFixtures.ts` (scénario E1 : Alpha LP1 X 1 000 / Y 500, LP2 X 200, Bravo LP3 X 400, 0,75 m³, 4 jours ; X = Coolant, Y = Mechanical Parts ; détenteurs `knownHolder` / `unknownHolder` / `holdersOf`)
- `frontend/tests/planetarySupplyFixtures.ts` : `launchpadOf` gagne `characterId: 2_119_000_001`, `characterName: 'Jita Pilot'` (spec, tests existants touchés)

## Squelette dans `frontend/src/utils/planetarySupply.ts` (autorisé)

- `SupplyLaunchpad` : `characterId: number`, `characterName: string` (règle 1).
- `SupplyGrid.rows[]` : `characterId`, `characterName`, recopiés du launchpad dans `buildSupplyGrid` (règle 6, plomberie de type ; pas de test dédié, couverte par les tests de `planStockTransfers`).
- Types nouveaux : `SupplyHolderUnknownReason`, `SupplyHolderCharacter`, `SupplyHolders` (`homeLocationId`, `homeLocationName`, `characters[]`, aligné sur `PlanetarySupplyHoldersResource` du back), `StockTransferLine` (`typeId`, `typeName`, `need`, `transfer|null`, `volumeM3|null`), `StockTransferRecipient` (`characterId`, `characterName`, `lines`, `totalM3|null`, `excludedCount`, `rowKeys`), `StockHolder` (`available|null`, `unknownReason|null`), `StockTransferType` (`holders`, `shortfall|null`), `StockTransferPlan`.
- `planStockTransfers(launchpads, days, holders): StockTransferPlan`, renvoie `{ recipients: [], types: [] }`.

## Contrat attendu par les tests

- Destinataires triés par nom, lignes triées par typeId, seulement les types de leurs lignes de grille `known` ; `rowKeys` = toutes leurs lignes de grille (`${colonyId}-${pinId}`), y compris les inconnues.
- Détenteurs par type : `known` à disponible > 0 triés par disponible décroissant puis nom, puis les `unknown` (`available: null`, raison) ; `types` triés par typeId = colonnes de la grille.
- Jours invalides : `{ recipients: [], types: [] }`.
- Composant, data-testid : `pi-transfer-block` (contient l'`ErrorBanner` d'échec de `/planetary/supply-holders`), `pi-transfer-card[data-character-id]`, `pi-transfer-character`, `pi-transfer-line[data-type-id]` > `pi-transfer-need`, `pi-transfer-quantity`, `pi-transfer-volume`, `pi-transfer-holder[data-character-id]` ; `pi-transfer-total`, `pi-transfer-copy` ; `pi-transfer-home-unset` > `pi-transfer-open-stock` (émet `open-stock`) ; `pi-transfer-assets-unknown[data-character-id][data-reason]` ; `pi-transfer-assets-stale[data-character-id]` (date au format `formatDate`/`formatDateTime`, contient `26/11` en format eu). Inconnu affiché avec le libellé `pi.supply.unknown`.

## Raison de chaque rouge (15/15 sur une AssertionError)

- 10 tests purs : `expected [] to deeply equal …`, le squelette ne renvoie ni destinataire ni type (E1 x2, E2, E3, E4, E5, E6, E7, type sans nom, 0 jour puis 4).
- 5 tests composant : aucun élément `pi-transfer-*` rendu (`expected [] to deeply equal …`) : cartes E1, copie multibuy + bouton désactivé, lieu non réglé, marques inconnu/périmé, bannière d'échec avec grille gardée (3 lignes).

## Vérifications

- Tests voisins (utils planetarySupply, components/planetary, stores/planetarySupply, views/PlanetaryInteraction*) : 471 verts, seuls les 5 nouveaux en rouge.
- `eslint .` : 0 erreur (5 warnings préexistants hors lot).
- `vue-tsc -b` : aucune erreur sur les fichiers du lot ; seules erreurs, préexistantes, `src/help/renderHelpMarkdown.ts` (module `marked` absent du volume `evetools_wt_node_modules`, pas du lot).
- Lancement : pas de `node_modules` dans le worktree ; `docker run node:22-alpine` avec le volume `evetools_wt_node_modules`.
- Recherches 5a : aucun test front ne fige la forme d'une ligne de grille ni de `supply-holders` ; pas de `Currently…Issue1460`.
