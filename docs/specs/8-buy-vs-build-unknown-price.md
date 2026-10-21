# Spec #8 (lot Buy vs Build) — Prix inconnu dans Buy vs Build

## Contexte

`BuyVsBuildService::analyze()` remplace un prix absent par 0 à trois endroits : le coût d'achat d'un composant sans prix ni en structure ni à Jita (`?? 0.0`), le prix Jita d'un matériau d'un composant, et le prix Jita d'une feuille du job final. Un composant sans prix d'achat est alors « à fabriquer » faute de comparaison, un matériau sans prix rend la fabrication gratuite, et le coût total et la marge du produit sont faux sans le dire. Partie de #8 ; la spec #98 avait volontairement laissé ce repli en place.

## Règles métier

- **R1 — Coût de fabrication inconnu.** Un matériau de composant sans prix Jita a `unitPrice` et `totalPrice` à `null`, et le `buildCost` du composant est `null`.
- **R2 — Coût d'achat inconnu.** Coût d'achat = prix structure, sinon prix Jita, sinon `null` (plus de 0). `buyCostJita` et `buyCostStructure` restent comme aujourd'hui.
- **R3 — Verdict.** Les deux coûts connus : `build` si fabrication ≤ achat, sinon `buy` (inchangé). Seul le coût de fabrication connu : `build`. Seul le coût d'achat connu : `buy`. Aucun des deux : `unknown`.
- **R4 — Économie.** `savings` et `savingsPercent` valent `null` sauf si les deux coûts sont connus.
- **R5 — Totaux.** `buildAllCost` est `null` dès qu'un `buildCost` est inconnu ; `buyAllCost` est `null` dès qu'un coût d'achat est inconnu ; `optimalMixCost` additionne le coût du côté choisi par le verdict et vaut `null` dès qu'un composant a le verdict `unknown`. Un composant au verdict `unknown` ne figure ni dans `buildTypeIds` ni dans `buyTypeIds`.
- **R6 — Coût total et marge.** Une feuille du job final sans prix Jita rend le coût des feuilles inconnu. `totalProductionCost` = mix optimal + feuilles + installation, `null` dès qu'un terme est inconnu ; `marginPercent` est alors `null`.
- **R7 — Types sans prix.** Le résultat porte `missingPriceTypeIds` : matériaux de composant et feuilles du job final sans prix Jita, et composants sans aucun prix d'achat ; sans doublon, triés ; `[]` si tout est connu.
- **R8 — Tri.** Composants triés par `savings` décroissant (inchangé), ceux dont `savings` est `null` ensuite, par typeId croissant.
- **R9 — Frontend.** `BuyVsBuildComponent.verdict` accepte `unknown` ; les montants `null` s'affichent comme inconnus, jamais 0 ; le verdict `unknown` a un libellé FR/EN (« Prix inconnu » / « Unknown price »).

## Exemples chiffrés

Composant C1 : 10 unités, matériaux M1 ×100 (prix 5) et M2 ×20 (prix 50), installation 200 → fabrication 100×5 + 20×50 + 200 = 1 700. Les montants exacts sont recalculés par le test-writer sur les fixtures existantes.

| # | Cas | Attendu |
|---|---|---|
| E1 | C1, achat structure absent, Jita 150/u (1 500) | `buy`, économie −200 → savings = 1 500 − 1 700 = −200 (inchangé) |
| E2 | C1, aucun prix d'achat | verdict `build`, `savings` null, `buyAllCost` null, `optimalMixCost` compte 1 700, `missingPriceTypeIds` contient C1 |
| E3 | C1, M2 sans prix, Jita 150/u | `buildCost` null, ligne M2 à prix null, verdict `buy`, `buildAllCost` null, `optimalMixCost` compte 1 500, M2 dans `missingPriceTypeIds` |
| E4 | C1, M2 sans prix et aucun prix d'achat | verdict `unknown`, `optimalMixCost` null, `totalProductionCost` null, `marginPercent` null |
| E5 | Feuille du job final sans prix Jita | `totalProductionCost` null, `marginPercent` null, la feuille dans `missingPriceTypeIds` |
| E6 | Tout connu | sortie identique à aujourd'hui, `missingPriceTypeIds` [] |

## Cas limites

- Prix à 0 renvoyé par le marché : prix connu (inchangé).
- Même matériau absent dans deux composants : une seule entrée dans `missingPriceTypeIds`.
- Prix de vente absent : `marginPercent` null (inchangé).

## Hors périmètre

- Coût d'installation et EIV (#7) ; frais de vente (`brokerFee + salesTax`) ; migration sur le noyau.
- Profit Margins hors invention, Group Industry.

## Décision prise en autonomie

Spec écrite en mode boucle : verdict `unknown` ajouté pour le seul cas où aucun coût n'est connu ; dans les autres cas le verdict reste le côté chiffré.

## Glossaire

Termes existants : **Coût inconnu**, **Donnée manquante**. Aucun ajout.
