# #1287 — Market of each purchased line, Jita plus import or the capital market

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype (`baseOpts`, `computeBB` de `#acheter`). Back-end seulement : l'écran est l'étape 3 du wizard (#1289, #1290), il lira ces données.

## Contexte

`MaterialSourcingComparator` (`GET /industry/projects/{projectId}/material-sourcing`) compare déjà, à la lecture, Jita pondéré sur le carnet pour la quantité (`WeightedPrice`, couverture) plus l'import par le hub (#1286), et le prix mini (`sell_min`) du marché structure du lecteur (`User::getEffectiveMarketStructureId`, cache de la sync horaire). Il lui manque : la quantité en vente à la structure, son transport jusqu'à l'atelier, la règle « conseillé = moins cher avec assez de stock » et le choix stocké par ligne.

## Règles

R1. Stockage : colonne `industry_project_bom_lines.sourcing_market` `VARCHAR(20) NULL` (migration réversible, lignes existantes `null`). Valeurs `jita` ou `structure` (valeurs de `SourcingSide`, `unknown` interdite). `null` = suivre la recommandation. `IndustryProjectBomLine::getSourcingMarket(): ?SourcingSide` / `setSourcingMarket(?SourcingSide)`. La reconstruction de la BOM (`updateLineInPlace`) conserve la valeur ; une ligne supprimée emporte son choix.

R2. Stock en vente à la structure : méthode `StructureMarketPrices::sellVolumesFor(int $structureId, list<int> $typeIds): array<int, int>` (adaptateur `StructureMarketCachePrices`, jamais d'ESI à la demande) lue dans `structure_market_snapshots.sell_volume` par `StructureMarketSnapshotRepository::latestSellVolumes` : pour chaque type, la ligne de la date la plus récente, si elle a moins de `MAX_SNAPSHOT_AGE_DAYS` = 2 jours ; sinon (ou sans ligne) le type est absent. Un volume 0 est présent (rien en vente).

R3. Transport depuis la structure : `CourierHub::bridgeCostOf(float $cubicMeters, ?int $fromSolarSystemId, ?int $toSolarSystemId): Cost` : 0 si les deux systèmes sont connus et égaux ; sinon `m³ × jumpBridgeIskPerCubicMeter` ; un système inconnu = coût inconnu (`MissingData::jumpRoute()`), jamais 0. Le comparateur reçoit `?int $structureSolarSystemId` (4e paramètre optionnel de `compare`, lu par le provider dans `CachedStructure::getSolarSystemId`, `null` si la structure n'est pas résolue) et le système de l'atelier (`FavoriteSystems::forActivity`). Pas de `CourierHub` et systèmes différents : coût inconnu (`MissingData::courierHub()`), comme `JitaPurchase::importCostOf`. Pas de structure du lecteur : coût du transport inconnu (la structure l'est déjà).

R4. Options d'une ligne (`MaterialSourcing`) :
- Jita : inchangé (`jitaCost` carnet parcouru, `importCost`, total livré `jitaWithImportCost()`).
- Structure : `structureCost` inchangé (quantité × `sell_min`), plus `structureBridgeCost` (R3) ; total livré `structureDeliveredCost()` = prix + pont, inconnu si l'un des deux l'est (les `MissingData` s'additionnent comme `Cost::plus`).
- Stock de la structure : `structureAvailableQuantity: ?int` (R2, `null` = inconnu) et `structureStock: StructureStock` (`enough` si disponible ≥ besoin, `insufficient` si <, `unknown` si `null`).

R5. Conseillé (`side`) : parmi les options éligibles (coût livré connu ; pour la structure, en plus `structureStock = enough`), la moins chère ; égalité : Jita. Aucune éligible : `Unknown`. Un stock inconnu (`structureStock = unknown`) rend la structure inéligible, même avec un prix connu. Jita est éligible dès que son coût est connu, même avec une couverture partielle du carnet (l'avertissement `partial_market_depth` reste). `saving` = écart absolu des deux totaux livrés quand les deux sont connus. `structureFallback` : inchangé (prix structure absent, Jita retenu).

R6. Choix effectif : `market` = valeur stockée si elle existe (même si le stock est insuffisant : l'écran l'affiche, `structureStock` le dit), sinon `side`. `selectedCost()` = total livré du côté de `market` (`Unknown` : somme des deux, comme `bestCost()`). Les totaux de la ligne et du projet ne portent jamais 0 pour une donnée absente.

R7. Lecture : chaque ligne de `rows` gagne `lineId`, `chosenMarket` (`jita|structure|null`), `market`, `selectedTotal`, `structureBridgeTotal`, `structureDeliveredTotal`, `structureAvailableQuantity`, `structureStock`. Les champs existants gardent leur sens (`structureTotal` = prix seul, `totals.structure` = somme des prix) ; `bestTotal`, `side`, `saving`, `totals.best` utilisent les totaux livrés et la règle R5. `totals` gagne `structureDelivered` et `selected`. Arrondi à 2 décimales, inconnu = `null`. `MaterialToSource` reçoit `lineId` et `chosenMarket` (derniers paramètres optionnels, `MaterialsToSourceFactory::of` et `reservedOf` les remplissent).

R8. Écriture : `POST /industry/projects/{projectId}/bom/{lineId}/sourcing-market` (`{market: "jita"|"structure"}`, `ProjectBomLineSourcingMarketResource`, `uriVariables` avec `Link`, processeur `ChooseSourcingMarketProcessor`, réponse 200 `id`, `lineId`, `market`) et `DELETE` au même chemin (provider qui renvoie la ressource, 404 sans choix, processeur `ClearSourcingMarketProcessor`). Contrôles dans l'ordre : utilisateur connecté (401), projet (404), `assertAdminOrOwner` (403), ligne du projet (404), projet terminé (400), ligne de job (400), valeur autre que `jita` / `structure` (400). Le choix se change à tout moment (pas de verrou). Après écriture : `BomLineMarkNotifier::notifyLineChanged` (topic `industry-project` déjà déclaré dans `MercurePublisherService::getTopicsForUser()`, l'écran relit).

## Exemples chiffrés (tests rouges)

Réglages : courier 800 ISK/m³, pont 50 ISK/m³, hub H, atelier en W (≠ H), Jita 30000142, volume 0,01 m³/unité, besoin 100 (donc 1 m³).

| Cas | Jita (livré) | Structure (livrée) | Stock | Conseillé |
|---|---|---|---|---|
| Jita 10 ISK/u, structure en H à 12 ISK/u | 1 000 + 850 = 1 850 | 1 200 + 50 = 1 250 | 100 | `structure` |
| Idem, stock 99 | 1 850 | 1 250 | 99 | `jita` (structure `insufficient`) |
| Structure en W | 1 850 | 1 200 + 0 = 1 200 | 100 | `structure` |
| Structure à 30 ISK/u, stock 100 | 1 850 | 3 000 + 50 = 3 050 | 100 | `jita` |
| Jita sans prix, structure stock 99 | inconnu | 1 250 | 99 | `unknown` |
| Jita sans prix, structure stock 100 | inconnu | 1 250 | 100 | `structure` |
| Système de la structure inconnu | 1 850 | inconnu (`jumpRoute`) | 100 | `jita` |
| Pas de snapshot récent | 1 850 | 1 250 | `null` | `jita` (structure `unknown`) |
| Stocké `structure`, stock 99 | 1 850 | 1 250 | 99 | `side` = `jita`, `market` = `structure`, `selectedTotal` 1 250 |
| Stocké `jita`, structure moins chère | 1 850 | 1 250 | 100 | `side` = `structure`, `market` = `jita`, `selectedTotal` 1 850 |

Écriture : ligne matière, POST `jita` puis `structure` : valeur remplacée ; DELETE : `null` ; ligne de job : 400 ; membre non admin : 403 ; ligne d'un autre projet : 404.

## Cas limites

- Aucune structure de marché pour le lecteur : option structure inconnue, `structureStock = unknown`, conseillé Jita si son coût est connu.
- Ligne couverte par le stock (reste 0) : absente de `rows`, son choix reste stocké.
- Besoin 0 jamais comparé (absent de `rows`).
- Rien n'est écrit par la lecture ; la reconstruction de la BOM ne touche pas au choix.

## Hors périmètre

Écrans du wizard (#1289, #1290), acheter ou fabriquer de bas en haut (#1288), parcours du carnet de la structure (le prix reste `sell_min`, le stock insuffisant est signalé, pas valorisé), verrou du choix après achat, marché d'une autre structure que celle du lecteur, Amarr et autres hubs.

## Décisions

D1. « Marché de la capitale » = la structure de marché du lecteur (réglage existant) ; la valeur stockée est `structure` (`SourcingSide`) pour ne pas doubler le vocabulaire de l'API.
D2. Écriture réservée au propriétaire et aux admins du projet (état partagé par la ligne), comme `workStations`.
D3. Montants existants conservés ; les totaux livrés s'ajoutent (additif), `side` et `totals.best` passent au livré et à la règle du stock (changement visible, à relire).
D5. Le choix stocké est une `App\Enum\SourcingMarket` (`jita`, `structure`) : l'entité ne peut pas dépendre de `Industry\Application` (deptrac), `MaterialsToSourceFactory` la convertit en `SourcingSide`. `SourcingMarketAccess` (Service) refait le contrôle propriétaire ou admin de `ProjectAccessChecker` parce qu'un processeur ne peut pas dépendre d'un provider (deptrac).
D4. Le pont se calcule depuis le système réel de la structure (`CachedStructure`), sans supposer qu'elle est dans le système du hub (le prototype le suppose).
