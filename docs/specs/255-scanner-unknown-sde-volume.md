# Spec #255 — Scanner : un volume SDE absent rend le coût de transport inconnu

**Statut : validée par Sylvain le 2026-10-28.**

## Contexte

Le scanner de rentabilité (`BatchProfitScannerService::scan()`, `GET /industry/profit-scan`, onglet `BatchScanTab.vue`) ajoute au coût total deux coûts de transport au tarif `exportCostPerM3` des réglages (1 200 ISK/m³ par défaut, `EveConstants::DEFAULT_EXPORT_COST_PER_M3`) :

- **coût d'import** : volume des matériaux d'un run × tarif (`importCost`) ;
- **coût d'export** : volume du produit × tarif, seulement quand le lieu de vente n'est pas `structure` (`exportCost`). Un vaisseau d'un groupe connu prend son volume packagé (`SHIP_PACKAGED_VOLUMES`).

Un volume absent devient 0 : `loadTypeVolumes()` (`(float) ($row['volume'] ?? 0.0)`, et un type absent de `invTypes` n'a pas de ligne, lu ensuite par `?? 0.0`) et `getExportVolume()` (`$meta['volume'] ?? 0.0`, 0 aussi sans métadonnées). Le coût de transport paraît valide, la marge, le profit par unité et l'ISK/jour sont surestimés sans signal (`testProductOrMaterialWithoutSdeVolumeCurrentlyCostsNothingToTransportIssue255`).

Modèle retenu ailleurs (#104 liste d'achats, #108, spec #7 L3b scanner) : montant inconnu = `null` + motif, une somme qui contient un inconnu est inconnue.

## Règles métier

- **R1 — Volume inconnu.** Le volume d'un type est inconnu quand `volume` est `null` dans `invTypes` ou quand le type en est absent (pour le produit : sans métadonnées, la jointure type → groupe → catégorie ne renvoie rien). Un volume `0.0` du SDE est connu. Un vaisseau dont le groupe a un volume packagé garde ce volume, connu, quel que soit son volume SDE.
- **R2 — Coût d'import.** Inconnu (`null`) dès qu'un matériau du run a un volume inconnu.
- **R3 — Coût d'export.** Inconnu (`null`) quand le produit a un volume inconnu et que le lieu de vente n'est pas `structure`. En vente `structure`, il vaut 0.0 comme aujourd'hui, quel que soit le volume.
- **R4 — Tarif nul.** Avec `exportCostPerM3` = 0, les deux coûts valent 0.0, connus, même avec un volume inconnu (aucun transport facturé).
- **R5 — Marges.** Un coût de transport inconnu rend le coût total inconnu : `marginPercent`, `profitPerUnit` et `iskPerDay` valent `null`. `materialCost`, `sellPrice`, `dailyVolume` et l'autre coût de transport, s'il est connu, restent affichés.
- **R6 — Motif.** `unknownReason` = `missing_volume` (constante `BatchProfitScannerService::UNKNOWN_REASON_MISSING_VOLUME`), en dernier dans l'ordre de priorité : `missing_sale_price`, `missing_material_price`, motif d'installation (`missing_cost_index`, `missing_adjusted_price`), `missing_volume`. Pas de nouveau champ de typeIds (comme spec #7 L3b, R4).
- **R7 — Règles existantes.** Une marge inconnue ne passe jamais un filtre de marge minimale ; les produits à coût inconnu sont classés après les produits à ISK/jour connu.
- **R8 — API et frontend.** `BatchScanResultResource::$importCost` et `$exportCost` deviennent `?float`. `BatchScanItem.importCost` / `exportCost` passent en `number | null` ; `BatchScanTab.vue` affiche « Inconnu » (cellule ambre et infobulle `unknownCostTooltip`, déjà utilisées pour le coût matériaux) pour un coût de transport `null`. Clé i18n `industry.inventionUnknown.missing_volume` (FR, EN). Aucun calcul front ne traite `null` comme 0 (le tri gère déjà `null`).

## Exemples chiffrés

Produit 5402 (groupe 38 Shield Extender, catégorie 7), 1 unité par run, ME 10 : Tritanium 10 → 9, Pyerite 4 → 4. Jita : Tritanium 5, Pyerite 11, produit 310. Coût matériaux 89. Cost index et prix ajustés à 0 (installation 0), sans frais, sans volume journalier, lieu de vente `jita`, tarif 2 ISK/m³ sauf mention. Volumes : Tritanium 0,01, Pyerite 0,01, produit 5 sauf mention.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Tous les volumes connus (garde-fou) | import 0.26, export 10.0, profit 210.74, marge 212.31, motif `null` | inchangé |
| E2 | Tritanium volume `null` | import 0.08, export 10.0, profit 210.92 | import `null`, export 10.0, coût matériaux 89.0, profit, marge, ISK/jour `null`, motif `missing_volume` |
| E3 | Pyerite absent de `invTypes` | import 0.18, export 10.0, profit 210.82 | même attendu que E2 |
| E4 | Produit volume `null` | import 0.26, export 0.0, profit 220.74 | import 0.26, export `null`, profit, marge, ISK/jour `null`, motif `missing_volume` |
| E5 | Produit absent de `invTypes` (sans métadonnées) | export 0.0 | export `null`, motif `missing_volume` |
| E6 | E4 vendu en structure à 310 | import 0.26, export 0.0, profit 220.74, marge 247.30 | inchangé (R3) |
| E7 | Vaisseau du groupe 380 (Transport Ship, catégorie 6), volume SDE `null` | export 40 000.0 (packagé 20 000 m³) | inchangé (R1) |
| E8 | Volumes SDE à 0.0 pour Tritanium et Pyerite | import 0.0, profit 211.0, marge 213.13 | inchangé (garde-fou) |
| E9 | Tarif 0, Tritanium et produit volume `null` | import 0.0, export 0.0, profit 221.0, marge 248.31 | inchangé (R4) |
| E10 | E2 avec `minMarginPercent` 10 | produit gardé | produit exclu (R7) |
| E11 | E2 sans prix de vente Jita / avec Pyerite sans prix Jita / sans cost index | — | motif `missing_sale_price` / `missing_material_price` / `missing_cost_index` (R6) |
| E12 | Cas du test de l'issue : 5401 sans métadonnées vendu 300, 5402 volume `null` vendu 310, Tritanium volume `null`, Pyerite absent | import et export 0.0, coût matériaux 89.0, profit 211.0 et 221.0 | import et export `null`, coût matériaux 89.0, profit `null`, motif `missing_volume` pour les deux |
| E13 | E1 et E2 dans le même scan | — | E1 classé avant E2 (R7) |

## Cas limites

- Produit sans matériau : coût d'import 0.0, connu.
- Même matériau dans plusieurs blueprints : chaque produit qui le consomme a un coût d'import inconnu.
- Produit dont le coût connu serait nul ou négatif mais dont le transport est inconnu : gardé comme inconnu (le filtre `totalCost <= 0` ne s'applique qu'à un coût connu, règle existante).

## Hors périmètre

- Les autres volumes ramenés à 0 : `OreValueService`, `ContractCollectionProvider`, Profit Margins (coût d'export).
- Volume packagé des vaisseaux d'un groupe absent de `SHIP_PACKAGED_VOLUMES` (SDE assemblé, inchangé).
- Migration du scanner sur `missingData` du noyau (glossaire, **Coût inconnu**).

## Décisions (validées par Sylvain le 2026-10-28)

- **Forme de l'API** : option A retenue, `importCost` et `exportCost` nullables et motif `missing_volume` seulement, sans champ `missingVolumeTypeIds` (R6, R8).
- **Tarif au m³ à 0 avec un volume inconnu** : option A retenue, transport 0.0 connu, montant affiché inchangé (R4, E9).

## Tests existants appelés à changer

- `BatchProfitScanRulesTest::testProductOrMaterialWithoutSdeVolumeCurrentlyCostsNothingToTransportIssue255` (groupe `issue-255`) : fige import et export à 0.0 et un profit de 211.0 et 221.0. Attendu E12 : import et export `null`, profit `null`, motif `missing_volume`, coût matériaux 89.0. Renommé sans `Currently…Issue255`, le groupe `issue-255` devient un groupe de régression.
- `BatchProfitScanRulesTest::testShipOfAGroupWithoutPackagedVolumeIsExportedWithItsSdeVolume` : le Tritanium n'a pas de volume dans le harnais et le tarif vaut 1,5. L'import devient `null`, donc le `profitPerUnit` 5 005.0 asserté devient `null`. Correction du harnais : volume `'0.0'` pour le Tritanium (`materialVolumeRows`), qui garde import 0.0, export 4 800.0 et profit 5 005.0.

Restent verts (vérifiés à la lecture, à confirmer par la phase rouge) :

- `BatchProfitScanRulesTest::testStructureVenueSellsAtTheStructurePriceWithTheRegionDailyVolumeAndNoExportCost` (tarif 300, Tritanium sans volume) : l'import devient `null`, mais le test n'asserte que prix de vente, volume journalier et export 0.0.
- `BatchProfitScanRulesTest::testJitaVenueIgnoresAGivenStructure` (tarif 300, Tritanium sans volume) : n'asserte que prix de vente, volume journalier et export 1 500.0 (produit de 5 m³, connu).
- Tous les autres tests de `BatchProfitScanRulesTest` : tarif 0 (R4) ou volumes tous fournis (`testProfitableT1ModuleReportsEveryFigureRoundedToTheCent`).
- `BatchProfitScannerServiceTest` (`testScanIncludesExportCost`, `testExportCostUsesPackagedVolumeForShips`, `testScanIncludesImportCost`, tarifs 10 et 1 200) : chaque matériau a un volume, le produit est une frégate au volume packagé connu.
- `BatchScanProviderTest` : passe des montants connus, inchangé ; un cas `importCost` / `exportCost` `null` est à ajouter.
- Frontend : aucun test de `BatchScanTab` n'existe ; un test des cellules « Inconnu » pour le coût de transport est à créer.

## Glossaire

Ajouts faits dans [`docs/glossary.md`](../glossary.md) :

- nouveau terme **Coût de transport** (Transport cost), section Marché ;
- **Coût inconnu** : motif `missing_volume` et constante `BatchProfitScannerService::UNKNOWN_REASON_MISSING_VOLUME`.
