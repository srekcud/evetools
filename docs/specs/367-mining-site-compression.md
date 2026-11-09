# Spec #367 — Minage : badge de compression par type de site et bandeau d'avertissement

- **Statut** : rédigée en autonomie (mode nuit) le 2026-11-09, sans sous-agent.
- **Circuit** : `circuit:m`. **Périmètre** (commentaire de Sylvain du 2026-11-09) : écrans actuels, sans le compagnon #304 ; le compagnon réutilisera badge et bandeau.
- **Dépend de** : #267 (sites), #311 (verdict : `MiningCompressionAssessor`, `MiningOperationCompressionReport`, `MiningMinedQuantities`).
- **Périmètre interdit** : Industry, Group Industry, Stockpile ; aucune migration.

## 1. Contexte

Le verdict « compressible sur place » existe pour les types minés du ledger d'une opération (#311). Un site (#267) rattaché à une opération porte, dans son dernier scan, les types encore présents ; ce lot applique le même verdict à ces types, avec la coque et les compresseurs de l'opération du site.

## 2. Règles

- **R1 — Types d'un site.** Types du dernier scan dont `status` = `present` (les épuisés sont exclus), sans doublon, triés par `typeId`. Site sans scan : aucun type.
- **R2 — Verdict.** `MiningOperationCompressionReport::of($operation, $typeIds)` sans changement : mêmes statuts, mêmes écarts (`gaps.noHull`, `gaps.notMounted`), un type `null` n'entre dans aucun écart.
- **R3 — Lecture.** `MiningSiteResource` gagne `compression` (`?array`, même forme que `MiningOperationLedgerResource::$compression` : `boostShipTypeId`, `boostShipName`, `mountedCompressors`, `types`, `gaps`). Servie par `GET /api/mining-sites/{id}` et `GET /api/mining-sites` (donc `?operationId=`) ; absente (`null`) pour un site sans opération (site privé). Les réponses de création, de clôture et d'enregistrement d'un scan ne la portent pas.
- **R4 — Droits.** Aucun changement : lecture par un participant accepté de l'opération (contrôle existant de `MiningSiteRequest`), le propriétaire pour un site privé. Aucune donnée d'un autre périmètre.
- **R5 — Valeur inchangée.** Aucun montant, aucune estimation (ETA, valeur restante) ne change ; le verdict est une information de transport.

## 3. Exemples chiffrés (tests rouges)

Opération Porpoise, compresseurs `asteroid` et `gas` montés ; site rattaché dont le dernier scan contient Veldspar (asteroid), Zeolites (moon), Blue Ice (ice, épuisé).

| # | Lecture | Résultat |
|---|---|---|
| 1 | `GET /mining-sites/{id}` | `compression.types` = Veldspar `true` / `on_site`, Zeolites `false` / `no_hull_compressor` ; Blue Ice absent (épuisé) |
| 2 | idem | `gaps` = `noHull: [moon]`, `notMounted: []` ; `boostShipName` = `Porpoise` |
| 3 | opération Orca avec `asteroid` seul, scan Veldspar + Blue Ice | `gaps` = `notMounted: [ice]` ; Veldspar `on_site` |
| 4 | opération sans coque | `boostShipTypeId` `null`, types `no_boost_ship` / `null`, aucun écart |
| 5 | site rattaché sans scan | `compression.types` = `[]`, `gaps` vides |
| 6 | site privé (sans opération) | `compression` absent |
| 7 | `GET /mining-sites?operationId=…` | chaque site porte sa `compression` |
| 8 | accès | un étranger ou un membre en attente reçoit le même 404 qu'avant |

## 4. Front (HUD EVE, maquette `mockups/grouped-mining-v4.html`)

- **Page de l'estimation d'un site** (`MiningSiteDetail`) : dans le tableau des types du dernier scan, badge sous le nom du type : « sur place » (vert, `true`), « aucun » (ambre, `false`), « inconnu » (gris, `null`), même rendu que `MiningMinedQuantities`. Au-dessus du tableau, bandeau ambre par écart (`noHull`, `notMounted`) et la mention « la valeur des parts ne change pas ». Ni badge ni bandeau sans coque (`boostShipTypeId` `null`) ni sans `compression`.
- **Page de l'opération** (`MiningOperationSites`, liste des sites) : bandeau ambre unique, genres de `noHull` et de `notMounted` réunis sur tous les sites listés, nom de la coque de l'opération, même mention. Pas de bandeau sans écart. Les cartes de site n'ont pas de badge.
- Libellés : clés i18n existantes `miningOperation.boostFit.*` (`en`/`fr`) réutilisées, aucune nouvelle clé de libellé.
- Le calcul des badges et bandeaux sort de `MiningMinedQuantities` dans un composable partagé (pas de duplication) ; son rendu ne change pas.

## 5. Hors périmètre

- Compagnon et son bandeau (#304), valorisation, changement de coque depuis le site, badge dans le flux de scans, la courbe ou l'historique.
- Mercure : lecture seule, aucune action asynchrone, aucun topic nouveau.

## 6. Décisions prises en autonomie

1. Verdict calculé sur les types présents du dernier scan (pas les épuisés, qui ne se minent plus).
2. Site privé : pas de verdict (la coque est un réglage d'opération).
3. `compression` portée par la lecture d'un site et la liste, pas par les réponses d'écriture (éviter des requêtes SDE inutiles).
