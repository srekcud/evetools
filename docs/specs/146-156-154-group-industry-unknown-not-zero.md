# Spec #146 + #156 — Group Industry : une donnée manquante est inconnue, jamais 0

> Statut : **proposition, non validée**. Rédigée sans interrogatoire (Sylvain absent) : chaque point porte la réponse recommandée, les décisions produit sont dans « 🚦 Questions pour Sylvain ». `/tdd-red` ne démarre qu'après son accord.
>
> #154 (source du prix d'une contribution matériau) s'est révélée indépendante : le backend renvoie déjà `null` sans prix (spec #8), il reste une question de source de prix et de libellé, traitée dans [`154-group-contribution-price-source.md`](154-group-contribution-price-source.md).

## Contexte

Règle du projet (CLAUDE.md, glossaire « Coût inconnu ») : une donnée manquante n'est jamais remplacée par 0, et une somme qui en contient une est inconnue. Group Industry y déroge à plusieurs endroits (code lu sur `fix/group-group-industry`, 2026-10-26, les lignes citées dans les issues sont périmées) :

- **#146** `GroupIndustryDistributionService::calculateDistribution()` : `marginRatio = totalProjectCost > 0 ? … : 0.0` ; `buildMemberDistributions()` : `sharePercent = totalProjectCost > 0 ? … : 0.0`. Avec un coût total de 0, la marge vaut 0 %, chaque part 0 % et chaque payout 0 : le revenu net d'une vente n'est attribué à personne, et l'écran affiche « Margin: +0.0% » comme une valeur exacte. Un coût total de 0 reste atteignable : aucune contribution approuvée, location de ligne gratuite (`requireEnteredValue` accepte 0), matériau au prix Jita connu de 0, contributions enregistrées à 0 avant #8. Les contributions de job et de BPC exigent désormais une valeur > 0 (commit caf1d3f), le scénario de l'issue (« uniquement des Job Install à 0 ») ne vaut donc plus que pour l'historique.
- **#156**
  - `GroupIndustryProjectService::buildBom()` ne pose `estimatedPrice` que sur les lignes de matériau ; les lignes de job l'ont toujours à `null`, donc `GroupBomProvider::mapToResource()` renvoie `estimatedTotal = null` pour chaque job. `JobsTable.vue` (`totalEstCost`) somme `j.estimatedTotal ?? 0` : le pied du tableau des jobs affiche toujours « 0 ISK ».
  - `GroupIndustryResourceMapper::projectToResource()` : `totalBomValue += (estimatedPrice ?? 0.0) × requiredQuantity` ; un matériau sans prix rend la valeur de la BOM fausse au lieu d'inconnue. Même repli dans `BomTable.vue` (`totalEstCost`, `m.estimatedTotal ?? 0`).
  - `fulfillmentPercent` du projet (même méthode) = Σ livré / Σ requis sur des matériaux d'unités différentes : 1 000 000 Tritanium pèsent cent mille fois plus que 10 Morphite. `BomTable.vue` (`overallFulfillment`) refait ce calcul côté front, sans même plafonner chaque ligne à son requis.
  - `ProjectKpiCards.vue` : « Contributions totales » = Σ `totalBomValue × fulfillmentPercent / 100`, une valeur de BOM multipliée par un avancement en unités mélangées, qui ne lit aucune contribution. « Valeur pipeline » = Σ `totalBomValue`, décrite « valeur de vente estimée » alors que c'est un coût matériaux.
  - `FinancialSummary.vue` : `marginPercent ?? 0` ; aujourd'hui `marginPercent` n'est jamais null (repli de #146), il le deviendra.

## Règles métier

**R1 — Répartition impossible (#146).** Quand le coût total des contributions approuvées vaut 0, la marge est inconnue : `DistributionResult::$marginPercent = null`. Pour chaque membre, `sharePercent`, `profitPart` et `payoutTotal` sont `null` ; ses coûts apportés (`totalCostsEngaged` et le détail par type) restent connus (0). Revenu, frais, revenu net et coût total restent calculés. Le cas « coût total > 0 » est inchangé, perte totale incluse (pas de vente : marge −100 %).
- Où : `GroupIndustryDistributionService::calculateDistribution()`, `buildMemberDistributions()` ; `DistributionResult`, `MemberDistribution`, `GroupIndustryDistributionResource` (champs `?float`) ; `GroupDistributionProvider::mapToResource()` ; type front `Distribution` / `MemberDistribution` (`number | null`).
- Le signal est `marginPercent === null` : un seul motif possible, pas de champ supplémentaire (Q1).

**R2 — Affichage d'une répartition impossible (#146).** `FinancialSummary.vue` : la marge s'affiche `—` avec l'infobulle `common.unknownValue` (même rendu que `<UnknownValue>`, spec #222), jamais `0.0%`. Le profit (revenu net − coût total) reste affiché, il est connu. `DistributionTable.vue` : un bandeau « Répartition impossible : aucune contribution approuvée n'a de coût, le revenu net ne peut pas être réparti » (clés i18n FR/EN nouvelles) ; part, part de profit et payout de chaque membre et leurs totaux en `—` (`<IskAmount :value="null">` pour les montants) ; l'anneau des parts n'est pas dessiné.

**R3 — Valeur de la BOM (#156).** `totalBomValue` = Σ `estimatedPrice × requiredQuantity` des lignes de matériau ; `null` dès qu'une ligne de matériau n'a pas de prix. Un prix connu de 0 compte 0. `GroupIndustryProjectResource::$totalBomValue` devient `?float`, le type front `GroupProject.totalBomValue` `number | null`.
- Où : `GroupIndustryResourceMapper::projectToResource()`.

**R4 — Avancement de la BOM (#156).** `fulfillmentPercent` du projet = moyenne, sur les lignes de matériau, de l'avancement de chaque ligne (`GroupIndustryBomItem::getFulfillmentPercent()`, déjà plafonné à 100 %), arrondie à 2 décimales. Indépendant des unités et des prix, toujours connu (Q3).
- Où : `GroupIndustryResourceMapper::projectToResource()`.

**R5 — Une seule source pour la BOM affichée (#156).** Le pied de `BomTable.vue` lit `totalBomValue` et `fulfillmentPercent` du projet courant (`projectStore.currentProject`) et ne les recalcule plus (`totalEstCost` et `overallFulfillment` supprimés). `totalBomValue` null → `<IskAmount :value="null">` ; l'avancement s'affiche arrondi à l'entier, comme `ProjectCard.vue`.

**R6 — Coût des jobs (#156).** Le total du pied de `JobsTable.vue` est la somme des `estimatedTotal` des jobs, inconnu dès qu'un job a un `estimatedTotal` null, affiché par `<IskAmount>`. Aujourd'hui aucune ligne de job n'a de prix : le total s'affiche donc toujours `—`, la colonne aussi (déjà le cas). Le calcul d'un coût d'installation des jobs d'un projet collaboratif n'est pas dans ce lot (Q2).

**R7 — KPI « Contributions totales » (#156).** Somme, sur les projets listés, de la valeur des contributions **approuvées** de tous les membres (`estimatedValue`, tout type). Le backend l'expose par projet : `GroupIndustryProjectResource::$approvedContributionValue` (`float`, toujours connu, la colonne `estimated_value` est non nulle), précalculé par une requête groupée dans les providers de collection (même schéma que `acceptedMembersCount` / `countAcceptedByProject`), sinon calculé depuis `GroupIndustryProject::getContributions()` filtrées sur `approved` (provider d'item et processors de création, d'adhésion et de mise à jour). Le front ne multiplie plus rien par un avancement (Q4).
- Où : `GroupIndustryContributionRepository` (nouvelle méthode de somme par projets), `GroupProjectCollectionProvider`, `AvailableGroupProjectsProvider`, `GroupIndustryResourceMapper::projectToResource()` (nouveau paramètre optionnel), `ProjectKpiCards.vue`. Aucune migration.

**R8 — KPI « Valeur pipeline » et carte projet (#156).** Valeur pipeline = Σ des `totalBomValue` **connus** des projets listés ; sous la valeur, « N projet(s) à la valeur inconnue exclu(s) » quand N > 0, rien sinon (règle de la spec #181, clés i18n nouvelles). Sa description devient « coût matériaux estimé » / "estimated material cost" (`groupIndustry.kpis.pipelineDesc`), la valeur n'étant pas un prix de vente. `ProjectCard.vue` affiche `totalBomValue` par `<IskAmount>` (`—` si null) (Q5).

## Exemples chiffrés

Backend (PHPUnit). Frais de courtage et taxe de vente à 3,6 % sauf mention.

| # | Entrée | Attendu |
|---|---|---|
| D1 | Vente 1 000 000 ; une contribution approuvée de A, location de ligne 0 | revenu net 928 000 ; coût total 0 ; `marginPercent` null ; A : `totalCostsEngaged` 0, `lineRentalCosts` 0, `sharePercent` null, `profitPart` null, `payoutTotal` null |
| D2 | Frais 3 % / 3 % ; vente 500 000 ; aucune contribution approuvée | revenu net 470 000 ; coût total 0 ; `marginPercent` null ; aucun membre |
| D3 | Aucune vente, aucune contribution | revenu 0, revenu net 0, coût total 0, `marginPercent` null |
| D4 | Frais 0 ; vente 2 000 000 ; A location gratuite 0, B matériau 1 000 000 | coût total 1 000 000 ; marge 100 % ; A part 0 %, payout 0 ; B part 100 %, payout 2 000 000 (inchangé) |
| D5 | Aucune vente ; A matériau 600 000, B matériau 400 000 | marge −100 % ; payouts 0 (inchangé) |
| M1 | Matériaux : X prix 10, requis 1 000 ; Y sans prix, requis 50 | `totalBomValue` null |
| M2 | Matériaux : X prix 10, requis 1 000 ; Y prix 2 000, requis 5 | `totalBomValue` 20 000 |
| M3 | Matériau X prix 0, requis 100 | `totalBomValue` 0 (prix connu) |
| M4 | Matériau X sans prix + une ligne de job | `totalBomValue` null (le job n'entre pas dans la somme) |
| P1 | X requis 1 000 000, livré 500 000 ; Y requis 10, livré 10 | `fulfillmentPercent` 75.0 (aujourd'hui 50.0) |
| P2 | X requis 100, livré 150 ; Y requis 100, livré 0 | 50.0 (X plafonné à 100 %) |
| P3 | X requis 300, livré 100 ; Y requis 300, livré 100 ; Z requis 300, livré 100 | 33.33 |
| P4 | X requis 100, livré 100 ; une ligne de job non livrée | 100.0 (les jobs n'entrent pas dans l'avancement) |
| K1 | Projet P : contributions approuvées 1 000 000 et 250 000, une en attente 500 000, une rejetée 100 000 | `approvedContributionValue` 1 250 000 |
| K2 | Projet sans contribution approuvée | `approvedContributionValue` 0 |

Frontend (vitest).

| # | Composant, entrée | Attendu |
|---|---|---|
| F1 | `FinancialSummary`, `marginPercent` null, revenu net 928 000, coût total 0 | marge `—` avec l'infobulle « Valeur inconnue » ; aucun texte `0.0%` ; profit affiché 928 000 |
| F2 | `FinancialSummary`, `marginPercent` 58.3 | « Margin: +58.3% » (inchangé) |
| F3 | `DistributionTable`, `marginPercent` null, un membre à `payoutTotal` null | bandeau de répartition impossible ; part et payout du membre `—` ; total des payouts `—` ; aucun « 0 ISK » de payout |
| J1 | `JobsTable`, 3 jobs à `estimatedTotal` null | total du pied `—`, pas « 0 ISK » |
| J2 | `JobsTable`, jobs à 100 et 200 | total 300 |
| J3 | `JobsTable`, jobs à 100 et null | total `—` |
| B1 | `BomTable`, projet courant `totalBomValue` null, lignes de matériau à prix partiels | total du pied `—` (pas la somme des lignes connues) |
| B2 | `BomTable`, projet courant `fulfillmentPercent` 75, lignes P1 | progression globale 75 % (lue du projet, pas recalculée) |
| C1 | `ProjectCard`, `totalBomValue` null | `—` |
| K3 | `ProjectKpiCards`, projets `approvedContributionValue` 1 250 000 et 0 | « Contributions totales » 1 250 000 |
| K4 | `ProjectKpiCards`, `totalBomValue` 5 000 000, null, 2 000 000 | valeur pipeline 7 000 000 et « 1 projet à la valeur inconnue exclu » |
| K5 | `ProjectKpiCards`, `totalBomValue` 5 000 000 et 2 000 000 | 7 000 000, aucune mention d'exclusion |

## Cas limites

- Coût total strictement positif mais un membre à 0 (location gratuite) : sa part vaut 0 % et son payout 0, valeurs connues (D4).
- Revenu net négatif avec coût total 0 (frais sans vente : impossible, les frais sont proportionnels au revenu) : non atteignable.
- Projet sans aucune ligne de matériau : `totalBomValue` 0 et `fulfillmentPercent` 0, comportement actuel gardé ; non atteignable en pratique (tout arbre a des feuilles).
- `DistributionTable.vue` teste `dist.marginPercent < 0` pour son avertissement de perte : avec `null`, l'expression est fausse, pas d'avertissement (voulu).
- Arrondi : l'avancement reste arrondi à 2 décimales côté API (P3), à l'entier à l'écran.

## Hors périmètre

- Calcul d'un coût d'installation pour les lignes de job d'un projet collaboratif (le projet n'a ni structure ni système) : issue de suivi si Q2 le confirme.
- Source du prix d'une contribution matériau et libellé de la modale : spec #154.
- KPI « Approbations en attente » de `ProjectKpiCards.vue`, codé en dur à `0` : même famille de règle, non couvert par #156 ; à ouvrir en issue séparée.
- `GroupIndustryContributionService::submitAutoDetected()` : aucun appelant dans `src/` (test seul).
- Répartition égale ou à la tête quand le coût total vaut 0 (nouvelle règle métier) : seulement si Q1 la retient.
- Migration du module sur le noyau (`App\Industry\Domain\Cost`, `missingData`).

## Tests existants appelés à casser

- `tests/Unit/Service/GroupIndustry/GroupIndustryDistributionServiceTest.php::testZeroCostsNoContributions` : attend `marginPercent` 0.0, devient null (D2).
- Aucun test PHP n'asserte `totalBomValue` ni le `fulfillmentPercent` du projet (`GroupBomProviderTest` porte celui d'une ligne, inchangé). Aucun test vitest sur `JobsTable`, `BomTable`, `ProjectKpiCards`, `FinancialSummary`, `DistributionTable`.

## Glossaire

Termes proposés, **à ajouter seulement après validation** :

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Répartition impossible** | Undistributable distribution | Distribution d'un projet collaboratif dont le coût total des contributions approuvées vaut 0 : marge, parts et payouts sont inconnus, le revenu net n'est réparti entre personne et l'écran le dit (spec #146). | `DistributionResult::$marginPercent` null |
| **Avancement de la BOM** | BOM progress | Moyenne des avancements des lignes de matériau d'une BOM Group Industry, chacun plafonné à 100 % ; indépendant des unités et des prix (spec #156). À distinguer de l'**Avancement** d'une étape (runs). | `GroupIndustryProjectResource::$fulfillmentPercent` |

Termes existants modifiés après validation : **Payout** (ajouter « inconnu quand la répartition est impossible »).

## 🚦 Questions pour Sylvain

1. **#146, répartition impossible.** Quand le coût total approuvé vaut 0 et qu'il y a du revenu : (a) marge, parts et payouts inconnus, bandeau « répartition impossible » (**recommandé** : aucune règle inventée, le propriétaire corrige les contributions) ; ou (b) revenu net réparti à parts égales entre les membres ayant une contribution approuvée ?
2. **#156, coût des jobs.** Aucune ligne de job n'a de prix : (a) garder la colonne et un total `—`, et ouvrir une issue pour le coût d'installation des jobs groupés (**recommandé**) ; ou (b) retirer la colonne « Coût est. » et le total du tableau des jobs jusqu'à cette issue ?
3. **#156, avancement global.** Le pourcentage affiché d'un projet change (P1 : 50 % → 75 %). (a) moyenne des avancements par ligne de matériau (**recommandé** : toujours connu, sans unités) ; (b) pondéré par la valeur `prix × requis`, inconnu dès qu'un prix manque ; ou (c) part des lignes entièrement livrées ?
4. **#156, KPI « Contributions totales ».** (a) somme des contributions approuvées de tous les membres des projets listés, nouveau champ API `approvedContributionValue`, sans migration (**recommandé**) ; ou (b) uniquement mes contributions approuvées ? Le montant affiché change dans les deux cas.
5. **#156, valeur pipeline avec un projet sans prix.** (a) somme des projets connus et « N exclus », comme la spec #181, et description corrigée en « coût matériaux estimé » (**recommandé**) ; ou (b) KPI inconnu dès qu'un projet l'est ?
