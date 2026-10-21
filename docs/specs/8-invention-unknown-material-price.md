# Spec #8 (lot invention) — Prix inconnu d'un décrypteur ou d'un datacore

## Contexte

`InventionService::calculateInventionCost()` lit le prix du décrypteur avec `JitaMarketService::getPrice()`, qui ne lit que le carnet d'ordres du sync de fond. Ce sync ne couvre pas les décrypteurs (ils ne sont matériau d'aucune activité du SDE) : en production, le décrypteur coûte toujours 0 ISK (`?? 0.0`). Les datacores passent par `getPricesWithFallback()`, mais un prix encore absent devient aussi 0 ISK. Toutes les options de décrypteur de Profit Margins et du BPC Kit sont sous-évaluées. Partie de #8 (prix absent = matériau gratuit) et de #18.

## Règles métier

- **R1 — Source du prix.** Les prix des datacores et du décrypteur choisi sont lus par un seul appel à `getPricesWithFallback()` (cache du sync, puis ESI à la demande).
- **R2 — Prix inconnu.** Un datacore sans prix a `unitPrice` et `totalPrice` à `null`. Dès qu'un datacore ou le décrypteur n'a pas de prix : `costBreakdown.datacores` (si datacore) ou `costBreakdown.decryptor` (si décrypteur) vaut `null`, et `costPerAttempt` et `totalCost` valent `null`. Jamais 0.
- **R3 — Types sans prix.** Le résultat porte `missingPriceTypeIds` : les typeId sans prix, sans doublon, triés par ordre croissant ; `[]` si tout est connu.
- **R4 — Motif.** `unknownReason` vaut `missing_invention_probability` si la probabilité est inconnue (prioritaire), sinon `missing_market_price` si `missingPriceTypeIds` n'est pas vide, sinon `null`.
- **R5 — Options de décrypteur.** `buildDecryptorOptions()` applique R2-R4 à chaque option : une option dont le décrypteur n'a pas de prix a un coût inconnu, les autres options restent chiffrées.
- **R6 — Appelants.** Profit Margins : une option à coût inconnu a `inventionCost`, `totalProductionCost` et `bestMargin` à `null` (comportement existant pour `totalCost` null) ; si l'option choisie est inconnue, le coût total et les marges sont inconnus. BPC Kit : une option à coût inconnu n'est pas comparée pour le résumé (comportement existant).
- **R7 — Frontend.** `InventionUnknownReason` accepte `missing_market_price`, avec un libellé FR/EN (« Prix de marché inconnu pour un datacore ou le décrypteur »). Les montants `null` s'affichent comme inconnus, jamais comme 0.

## Exemples chiffrés

Datacore A ×2, datacore B ×3, copie 1 000 ISK, installation 500 ISK, probabilité 0,34 (sans décrypteur), 1 succès voulu.

| # | Prix A | Prix B | Décrypteur (prix) | `costPerAttempt` | `totalCost` | `missingPriceTypeIds` | `unknownReason` |
|---|---|---|---|---|---|---|---|
| E1 | 100 | 200 | aucun | 2 300 | 3 × 2 300 = 6 900 | [] | null |
| E2 | 100 | 200 | Accelerant (×1,2 → 0,408 ; 3 tentatives), 50 000 | 52 300 | 156 900 | [] | null |
| E3 | 100 | 200 | Accelerant, prix absent du cache et de l'ESI | null | null | [id Accelerant] | missing_market_price |
| E4 | absent | 200 | aucun | null | null | [id A] | missing_market_price |
| E5 | absent | 200 | probabilité absente du SDE | null | null | [id A] | missing_invention_probability |
| E6 | 100 | 200 | Accelerant, absent du cache mais 50 000 via ESI | 52 300 | 156 900 | [] | null |

Les montants des exemples sont indicatifs : le test-writer les recalcule à partir de la formule actuelle (copie, installation) et ne change que la partie prix.

## Cas limites

- Même typeId absent deux fois (datacore listé deux fois) : une seule entrée dans `missingPriceTypeIds`.
- Prix à 0 renvoyé par le marché : traité comme un prix connu (pas de changement ici).

## Hors périmètre

- Formule d'installation (`0.02 × EIV…`, #7), `ceil(1/p)` et nombre de succès (#18), frais de vente (#18).
- `PivotAdvisorService`, `BuyVsBuildService`, `ProfitMarginService` hors invention, `GroupIndustryContributionService` (#8, autres lots).

## Glossaire

Termes existants : **Coût inconnu**, **Donnée manquante** (motif `market_price` déjà prévu pour les services migrés). Aucun ajout.
