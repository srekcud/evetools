# #323 — Distribution « en attente des ventes » et marge provisoire

Mode nuit, spec validée en autonomie. Circuit m. Ajoute des champs à `GET /industry/projects/{projectId}/distribution` : aucun nouvel endpoint, aucune migration, aucun accès modifié, aucune action async (pas de Mercure : le recalcul passe déjà par les rafraîchissements `sale_recorded`, `sale_updated`).

## Constat vérifié sur origin/main (eef95b13)

- `ProjectDistributionService::calculateDistribution()` : sans vente, `totalRevenue` vaut 0, `netRevenue` 0, `marginPercent` −100, `payoutTotal` 0 pour chaque membre.
- `DistributionTable.vue` affiche l'encart « Loss Scenario » dès `marginPercent < 0`.
- `FinancialSummary.vue` affiche profit et marge calculés sur ce revenu.
- Consommateurs de `calculateDistribution()` : `ProjectDistributionProvider`, `CompletedProjectFiguresLoader` (profit = `profitPart`, déjà `null`-tolérant), `ProjectReferenceReader` (`marginPercent`, déjà `null`-tolérant).
- Unités à vendre d'un produit : somme des `requiredQuantity` des lignes BOM `isJob` avec `jobGroup === 'final'`, par `typeId` (règle de `ProjectCockpitCalculator::soldUnitsOf()`).

## Règles

**R1 — Aucune vente.** Le projet n'a aucune `IndustryProjectSale` : `DistributionResult::$awaitingSales` vaut `true` ; `netRevenue`, `marginPercent`, et pour chaque membre `profitPart` et `payoutTotal` valent `null` (donnée pas encore connue, jamais 0 ni −100). `totalRevenue` reste 0.0. `brokerFee` et `salesTax` gardent leur calcul (0.0 si les frais sont connus, `null` sinon). `totalProjectCost`, `totalCostsEngaged`, les coûts par type et `sharePercent` restent calculés. Avec au moins une vente, `awaitingSales` vaut `false` et rien d'autre ne change.

**R2 — Ventes partielles.** `DistributionResult::$unsoldProducts` : une entrée par `typeId` produit final dont `unités à vendre − unités vendues > 0`, dans l'ordre des lignes BOM finales : `typeId`, `typeName`, `unsoldQuantity`, `lastUnitPrice` (`unitPrice` de la vente la plus récente de ce type par `soldAt`, puis `createdAt` ; `null` si aucune vente de ce type). Vide quand `awaitingSales` est vrai (R1 prime) ou que tout est vendu. Les quantités vendues au-delà du besoin ne rendent jamais une quantité négative.

**R3 — Marge projetée.** `DistributionResult::$projectedMarginPercent` : non nul seulement si `unsoldProducts` n'est pas vide, aucun `lastUnitPrice` n'est `null`, les frais sont connus, `totalProjectCost` est connu et > 0. Valeur : `((netRevenue + Σ unsoldQuantity × lastUnitPrice × (1 − (courtage + taxe) / 100)) − totalProjectCost) / totalProjectCost × 100`. Un item jamais vendu rend la marge projetée `null` (« inconnue »), le bandeau liste quand même l'item. Sinon `null`.

**R4 — Payouts avec ventes partielles.** Inchangés : calculés sur le revenu réalisé. Le front les marque provisoires (R7).

**R5 — Ressource.** `ProjectDistributionResource` expose `awaitingSales: bool`, `unsoldProducts: list<{typeId:int, typeName:string, unsoldQuantity:int, lastUnitPrice:float|null}>`, `projectedMarginPercent: float|null`. Même accès qu'aujourd'hui (`assertAcceptedMember`).

**R6 — Front, aucune vente** (`distribution.awaitingSales`). `FinancialSummary` : ligne revenu « Aucune vente », revenu net, profit et marge « En attente des ventes » (nouveau composant `AwaitingSalesValue.vue`, comme `UnknownValue.vue`), coûts engagés inchangés. `DistributionTable` : bandeau « En attente des ventes » ; profit, payout de chaque membre et leurs totaux en « En attente des ventes » ; anneau des parts et parts inchangés ; ni bandeau « répartition impossible », ni encart de perte. L'état aucune vente prime sur « répartition impossible » (le coût nul n'a de sens qu'avec un revenu).

**R7 — Front, ventes partielles** (`unsoldProducts` non vide). Bandeau « marge provisoire » dans `DistributionTable` : une ligne par item (nom, quantité invendue, dernier prix de vente ou « inconnu »), puis la marge projetée (`projectedMarginPercent`, ou `UnknownValue` si `null`). `FinancialSummary` : libellé du profit « Profit provisoire ». Les colonnes payout restent affichées, le titre de colonne ne change pas.

**R8 — Scénario de perte.** L'encart « Loss Scenario » ne s'affiche que si `marginPercent < 0`, `awaitingSales` est faux et `unsoldProducts` est vide.

**R9 — i18n.** Clés FR et EN dans `groupIndustry.distribution` et `groupIndustry.financialSummary` (aucune chaîne en dur nouvelle).

## Exemples chiffrés (courtage 1,5 %, taxe 3,5 %, coût total 1 000 000)

| Cas | Entrée | Sortie |
|---|---|---|
| Aucune vente | 0 vente, 50 Hammerhead II à vendre | `awaitingSales` vrai, `netRevenue` null, `marginPercent` null, `payoutTotal` null, `unsoldProducts` [] |
| Partielle | 30 vendues à 40 000 (revenu 1 200 000), 50 à vendre | `awaitingSales` faux, `unsoldProducts` = [20 de ce type, `lastUnitPrice` 40 000], `netRevenue` 1 140 000, `marginPercent` 14, `projectedMarginPercent` 90 |
| Partielle, deux ventes | 10 à 30 000 puis 20 à 40 000 (`soldAt` croissants), 50 à vendre | `lastUnitPrice` 40 000 |
| Partielle, item jamais vendu | produit A vendu en partie, produit B jamais | entrée B avec `lastUnitPrice` null, `projectedMarginPercent` null |
| Tout vendu | 50 vendues sur 50, revenu 800 000 | `unsoldProducts` [], `marginPercent` −24, front : encart de perte |
| Frais inconnus | courtage null, 30/50 vendues | `projectedMarginPercent` null |

Cas « Partielle » : projeté = 1 140 000 + 20 × 40 000 × 0,95 = 1 900 000 ; (1 900 000 − 1 000 000) / 1 000 000 = 90 %.

## Cas limites

- Vente d'un `typeId` absent des lignes BOM finales : comptée dans le revenu, ignorée par `unsoldProducts`.
- Projet sans ligne BOM finale (brouillon) avec ventes : `unsoldProducts` vide, `awaitingSales` faux.
- Vente supprimée jusqu'à zéro vente : retour à R1 au prochain calcul (rien de stocké).
- Coût total inconnu ou nul : `projectedMarginPercent` null ; le bandeau « répartition impossible » reste régi par #146 quand `awaitingSales` est faux.
- Projet terminé sans vente : profit et marge inconnus (`CompletedProjectFiguresLoader`, `ProjectReferenceReader`) au lieu de −100 % ; voulu, noté en Décisions.

## Hors périmètre

Marge projetée sur un autre prix que le dernier prix de vente ; prix du marché pour un item jamais vendu ; refonte de la cascade « du revenu au profit » ; suppression d'un champ, d'un réglage ou d'un écran existant (rien n'est retiré).

## Tests

Unit `ProjectDistributionService` (R1, R2, R3, deux ventes, item jamais vendu, frais inconnus, tout vendu, ordre des lignes) ; Unit `ProjectDistributionProvider` (R5, mappage) ; vitest `DistributionTable` et `FinancialSummary` (R6, R7, R8, garde : un projet entièrement vendu en perte affiche toujours l'encart).

## Décisions ouvertes (tranchées en autonomie)

1. Un item jamais vendu rend la marge projetée inconnue, pas seulement l'item.
2. Les payouts restent calculés sur le revenu réalisé quand il y a des ventes partielles.
3. Un projet terminé sans vente a désormais un profit inconnu (Revenus, projet de référence) au lieu de −100 %.
