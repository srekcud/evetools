# Spec #108 — Profit Margins : marge et volume inconnus

## Contexte

`ProfitMarginService` remplace plusieurs données manquantes par une valeur plausible :

- `bestMargin` d'une option de décrypteur vaut 0 quand aucun lieu de vente n'a de prix (sentinelle -999) ; une marge réelle inférieure à -999 % devient aussi 0 ;
- la marge d'un lieu de vente vaut 0 quand le coût total est nul ;
- le volume journalier absent vaut 0.

Le frontend reprend la même règle (`venueMargin.ts`, spec #19, R3) et retombe sur 0 quand aucun lieu de vente n'a de marge (`ProfitMarginTab.vue`, `bestMargin`).

## Règles métier

- **R1 — Meilleure marge d'une option.** `bestMargin` d'une option de décrypteur est la plus grande marge parmi les lieux de vente qui en ont une, quelle que soit sa valeur (même sous -999 %). Sans aucune marge, elle est `null`.
- **R2 — Coût total nul.** Quand le coût total est nul ou négatif, la marge d'un lieu de vente est `null` (backend et frontend). Revenu, frais et profit restent calculés.
- **R3 — Volume journalier.** Sans volume journalier connu, `dailyVolume` vaut `null`.
- **R4 — Meilleure marge affichée.** Côté frontend, sans lieu de vente qui ait une marge, la meilleure marge est `null` (affichée « inconnue »), jamais 0.

## Exemples chiffrés

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Hail L, 1 run, aucun prix de vente : `bestMargin` des 9 options de décrypteur | 0.0 × 9 | `null` × 9 |
| E2 | Option dont la seule marge est -1 500 % | 0.0 | -1 500.0 |
| E3 | Nitrogen Fuel Block, aucun prix de feuille, coût total 0, prix Jita 4 500, contrat 4 300 | marge 0.0 (Jita et contrat), revenu 180 000 / 172 000, frais 12 960 / 0 | marge `null`, revenu, frais et profit inchangés |
| E4 | Produit sans volume journalier | `dailyVolume` 0.0 | `null` |
| E5 | Produit avec volume 1 234,5 | 1 234,5 | inchangé |
| E6 | Frontend, `venueMargin` avec coût total 0 (spec #19, E3) | 0 | `null` |

## Hors périmètre

- Coût total inconnu quand un prix de feuille manque (#8) : le coût reste 0 ici, seule la marge devient inconnue.
- `dailyVolume` du scanner (`BatchScanResultResource`).

## Tests existants appelés à changer

- `ProfitMarginGoldenCharacterizationTest::testDecryptorOptionWithoutAnySellPriceCurrentlyHasZeroBestMargin` → E1.
- `ProfitMarginGoldenCharacterizationTest::testZeroProductionCostCurrentlyReportsZeroMarginInsteadOfUnknown` → E3.
- `ProfitMarginGoldenCharacterizationTest::testProductWithoutDailyVolumeCurrentlyReportsZeroVolume` → E4.
- `frontend/tests/components/industry/venueMargin.spec.ts` (E3 de #19) → E6.

## Décision prise en autonomie

Spec validée en mode nuit. Choix conservateurs : seules les valeurs listées par l'issue deviennent `null` ; le coût total à 0 sans prix (#8) n'est pas touché ; la branche `feat/profit-margin-on-engine` (bloquée) devra reprendre ces règles. Le contrat d'API change (`dailyVolume`, `margin`, `bestMargin` nullables) : le frontend les affiche déjà comme « inconnu » quand ils sont `null`.

## Glossaire

Aucun terme ajouté (« coût inconnu », « donnée manquante » existent).
