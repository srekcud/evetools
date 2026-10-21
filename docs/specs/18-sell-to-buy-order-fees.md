# Spec #18 (lot vente) — Frais d'une vente à un buy order

## Contexte

Profit Margins calcule une marge par lieu de vente. `ProfitMarginService::computeMarginForPrice()` applique `frais de courtage + taxe de vente` aux trois lieux de marché, y compris `structureBuy` (vente immédiate à un buy order de la structure). En jeu, vendre à un buy order existant ne crée pas d'ordre : seule la taxe de vente s'applique. La marge `structureBuy`, et la meilleure marge de chaque option de décrypteur, sont sous-évaluées. Partie « vente » de #18, hors noyau (spec moteur §8).

## Règles métier

- **R1 — Vente par ordre de vente** (`jitaSell`, `structureSell`) : frais = revenu brut × (frais de courtage + taxe de vente). Inchangé.
- **R2 — Vente à un buy order** (`structureBuy`) : frais = revenu brut × taxe de vente. Pas de frais de courtage.
- **R3 — Contrat** (`contractSell`) : frais = 0. Inchangé.
- **R4 — Meilleure marge.** `findBestMarginForCost()` (meilleure marge d'une option de décrypteur) applique les mêmes règles par lieu.

## Exemples chiffrés

Frais de courtage 3,6 %, taxe de vente 3,6 %, 40 unités, coût total C (celui du golden Nitrogen Fuel Block 1 run ME 10).

| # | Lieu | Prix unitaire | Revenu brut | Frais attendus |
|---|---|---|---|---|
| E1 | jitaSell | 4 500 | 180 000 | 180 000 × 0,072 = 12 960 |
| E2 | structureSell | 4 400 | 176 000 | 176 000 × 0,072 = 12 672 |
| E3 | structureBuy | 4 000 | 160 000 | 160 000 × 0,036 = 5 760 (aujourd'hui 11 520) |
| E4 | contractSell | 4 300 | 172 000 | 0 |

Profit = revenu brut − frais − C ; marge = profit / C × 100 (définition actuelle, #19 hors périmètre).

Meilleure marge d'option (R4) : avec des taux de courtage et de taxe différents (ex. 5 % et 2 %) et un buy order à 4 000 contre un ordre de vente à 4 150, la vente au buy order (frais 2 %) l'emporte : 160 000 × 0,98 = 156 800 > 166 000 × 0,93 = 154 380.

## Cas limites

- Prix du buy order absent ou ≤ 0 : marge `structureBuy` nulle (inchangé).
- Taux de taxe de vente à 0 : frais `structureBuy` = 0.

## Hors périmètre

- Définition de la marge (profit / coût, #19).
- Autres services qui chiffrent une vente (scanner, pivot, Group Industry).
- Frais de relist, taxes de structure sur les ordres.

## Glossaire

Aucun ajout.

## Complément (phase rouge)

- **R5 — Frontend.** `frontend/src/components/industry/venueMargin.ts` recalcule la marge par lieu quand on choisit un décrypteur : il applique les mêmes règles R1-R3 (buy order = taxe de vente seule).
