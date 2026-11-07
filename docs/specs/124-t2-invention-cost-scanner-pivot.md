# Spec #124 — Coût d'invention dans le scanner et le Pivot Advisor

## Contexte

Pour un produit T2, Profit Margins et Buy vs Build (noyau `ProductionCostCalculator`) ajoutent le coût d'invention attendu au coût de production. Le scanner de rentabilité (`BatchProfitScannerService`) et le Pivot Advisor (`PivotAdvisorService`) ne l'ajoutent pas : leur marge T2 est surestimée et diffère de celle de Profit Margins. Buy vs Build est déjà corrigé dans `main` (`rootCostOutsideComponents`, `BuyVsBuildServiceCharacterizationTest::testTotalProductionCostIsTheOptimalMixPlusTheRootLeavesTheRootInstallCostAndTheInvention`) : il ne change pas.

## Règles métier

- **R1 — Coût d'invention par run.** Pour un produit T2, sans decryptor et sans compétence d'inventeur (même hypothèse que Buy vs Build), le coût d'invention d'un run de fabrication est `costPerAttempt / (probabilité de base × runs du BPC inventé)`. C'est l'espérance de la règle R8 du noyau (`InventionExpectation::costForRuns`), jamais arrondie à un nombre entier de tentatives. Méthode publique `InventionService::expectedCostPerRun(int $t2TypeId, int $solarSystemId, ?float $facilityTaxPercent): array{cost: ?float, unknownReason: ?string}`.
- **R2 — Coût inconnu.** Probabilité absente du SDE, prix d'un datacore absent, coût d'installation inconnu ou chaîne d'invention introuvable : `cost` est `null` et `unknownReason` dit pourquoi (`missing_invention_probability`, `missing_market_price`, `missing_cost_index`, `missing_adjusted_price`). Jamais 0.
- **R3 — Scanner.** Le coût total d'un produit T2 (`totalCost`) ajoute le coût d'invention par run ; marge, profit par unité et ISK par jour en découlent. Coût d'invention inconnu : coût, marge, profit et ISK par jour sont inconnus (`null`), `unknownReason` porte le motif de R2, après les motifs de prix de vente, de matériau et d'installation. Un produit non T2 n'appelle pas l'invention.
- **R4 — Pivot Advisor.** Même règle pour chaque candidat T2 et pour le produit source T2 : le coût total ajoute le coût d'invention, une marge inconnue reporte le motif de R2 dans `unknownReason`.
- **R5 — ME du T2.** Le ME utilisé pour un T2 sans decryptor est `InventionService::BASE_INVENTION_ME` (2), le ME de base de l'invention, pas celui du decryptor Attainment (qui donne 1). Valeur inchangée, l'erreur était dans le commentaire.
- **R6 — Système et taxe.** Système de fabrication et taxe d'installation de fabrication déjà résolus par chaque service.

## Exemples chiffrés

| # | Cas | Attendu |
|---|---|---|
| E1 | attempt 467 670, probabilité 0,25, 10 runs par BPC | invention par run = 467 670 / 2,5 = 187 068 |
| E2 | Jaguar T2, matériaux 980 (200 de base, ME 2), install 0, invention 187 068, vente 400 000, frais 0 | coût 188 048, profit 211 952, marge 112,71 % |
| E3 | même cas, probabilité absente | marge `null`, `unknownReason` = `missing_invention_probability` |
| E4 | produit T1 | `expectedCostPerRun` jamais appelée |

## Hors périmètre

- Exposition d'un champ `inventionCost` dans les ressources du scanner et du Pivot Advisor.
- Compétences réelles de l'inventeur et decryptor dans le scanner (Profit Margins les porte).
- Migration du scanner et du Pivot vers `ProductionCostCalculator`.
