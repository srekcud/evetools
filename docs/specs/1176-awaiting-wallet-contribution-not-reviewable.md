# #1176 — A contribution awaiting wallet units is not reviewable

Back : un prédicat d'entité. Front : un libellé sur la ligne en projet solo. Pas de migration, pas de ressource API nouvelle, pas de topic Mercure.

## Contexte
Un contributeur solo déclare « je l'ai fourni » avec la source `wallet_purchase` avant que la sync wallet ait vu toutes les unités. `ProjectContributionService` laisse la contribution `pending` (`awaitingQuantity > 0`). `IndustryProject::pendingContributionCountReviewableBy` la compte, donc le cockpit propose `review_contributions`, le projet repasse en mode groupe (onglets Contributions et Équipe) et ni l'approbation par ligne ni « Tout approuver » ne peuvent agir (`ContributionTable.vue` exclut `isAwaitingUnits`).

## Règles
R1 `IndustryProject::pendingContributionCountReviewableBy` ne compte pas une contribution `pending` dont `awaitingQuantity > 0`. Conséquences voulues : l'action suivante `review_contributions` du cockpit, `pendingContributionCount` de la ressource projet et le test « travaillé seul » du front l'ignorent.
R2 Dès que `awaitingQuantity` retombe à 0, la contribution `pending` redevient comptée (règle de relecture inchangée : `canReviewContribution`).
R3 Sur la ligne de BOM d'un projet solo, une contribution matériau modifiable par le spectateur avec `awaitingQuantity > 0` affiche « N units awaiting wallet sync » (clé `groupIndustry.reservation.awaitingWalletSync`, EN et FR) à côté de « Supplied ×N » ; sans attente, rien de plus.
R4 Inchangé : `ProjectSettlementCalculator::pendingContributionCount` (contrôle de clôture) compte toujours toutes les contributions `pending`, y compris en attente d'unités ; l'approbation côté service et la table des contributions ne bougent pas.

## Exemples chiffrés (tests rouges)
| Entrée | Sortie |
|---|---|
| propriétaire seul, 1 contribution `pending` propre, `awaitingQuantity = 40` | `pendingContributionCountReviewableBy(owner)` = 0 |
| même projet, 1 contribution `pending` à 0 attente + 1 à 40 | 1 |
| même contribution, `awaitingQuantity` passé de 40 à 0 | 1 |
| projet solo, in_progress, contribution en attente seule, matières couvertes ailleurs | `CockpitInput.pendingContributionCount` = 0 |
| ligne solo, contribution `awaitingQuantity = 40` | texte « 40 units awaiting wallet sync » présent |
| ligne solo, contribution `awaitingQuantity = 0` | texte absent |

## Cas limites
Contribution `approved` ou `rejected` avec attente résiduelle : ignorée comme avant (seul `pending` compte). Projet de groupe avec un membre en attente d'unités : même exclusion (l'approbation y est impossible aussi). Valeur `awaitingQuantity` absente côté front (`undefined`) : pas de libellé.

## Hors périmètre
Contrôle de clôture, badge des onglets (#1226), changement de la source de valeur, nouveau champ.

## Décisions
Le contrôle de clôture garde les contributions en attente : clore un projet avec des unités non confirmées par le wallet reste une décision à confirmer par Sylvain.
