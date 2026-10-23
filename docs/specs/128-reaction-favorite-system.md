# Spec #128 — Profit Margins, scanner, Pivot Advisor : système des réactions

## Contexte

Profit Margins (`ProfitMarginProvider:71-75`, `ProfitMarginService::costFunction` → `FavoriteSystems($solarSystemId, $solarSystemId)`), le scanner (`BatchScanProvider:69-71`, `BatchProfitScannerService:283-286`) et le Pivot Advisor (`PivotAdvisorProvider:54-56`, `PivotAdvisorService`) n'ont qu'un système, le favori de **fabrication** : le coût d'installation d'une réaction (indice et taxe) est calculé dans ce système. Buy vs Build a été corrigé par #99 (`BuyVsBuildProvider:62-67`), règle reprise ici. Buy vs Build n'est pas touché (thread en cours).

## Règles métier

- **R1 — Résolution.** Paramètre `solarSystemId` fourni : il vaut pour la fabrication et la réaction. Sinon : fabrication = favori de fabrication, sinon Jita ; réaction = favori de réaction, sinon le système de fabrication retenu. Une seule implémentation partagée par les trois providers (pas de copie par provider ; Buy vs Build garde la sienne, hors lot).
- **R2 — Profit Margins.** Le service reçoit le système de réaction ; le noyau reçoit `FavoriteSystems(fabrication, réaction)` : une étape de réaction sans structure prend l'indice du système de réaction.
- **R3 — Scanner.** Indice et taxe (`FacilityTaxResolver`) de réaction lus dans le système de réaction ; fabrication inchangée.
- **R4 — Pivot Advisor.** Candidats et produit source de réaction : `estimateJobInstallCost(…, système de réaction, 'reaction', taxe résolue dans ce système)`.

## Exemples

Réglages : favori fabrication Jita 30000142, favori réaction Perimeter 30000144.

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E1 | Résolution sans paramètre | fabrication 30000142, réaction 30000144 (30000142 pour les deux) |
| E2 | Paramètre `solarSystemId` = 30002187 | 30002187 pour les deux (inchangé) |
| E3 | Pas de favori de réaction | réaction = fabrication |
| E4 | Aucun réglage | Jita pour les deux (inchangé) |
| E5 | Profit Margins, produit de réaction sans structure | indice de réaction de Perimeter (Jita) |
| E6 | Scanner, Ferrogel (réaction) | `getCostIndex(30000144, 'reaction')`, taxe résolue pour 30000144 ; produit de fabrication : Jita (inchangé) |
| E7 | Pivot, candidat de réaction | `estimateJobInstallCost(…, 30000144, 'reaction', …)` ; candidat de fabrication : 30000142 |

## Hors périmètre

Buy vs Build, choix de la structure d'une étape (#133, « Blocages »).

## Glossaire

Termes existants : **Système favori**. Aucun ajout.
