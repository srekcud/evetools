# Spec #7 (lot L3c) — Taxe d'installation du Pivot Advisor et du scanner

## Contexte

Voir `.claude/tasks/7-design.md` §L3c. Après L2 et L3b, le Pivot Advisor et l'Industry Scanner calculent le coût d'installation par la formule R6, mais avec une taxe d'installation nulle : aucun des deux ne connaît de structure. Décision de Sylvain (2026-10-22, Q2) : taxe résolue comme Profit Margins. Buy vs Build passe déjà par le noyau (`ProductionCostCalculator`, `StructureSelector`) et a sa taxe : hors lot.

Profit Margins sur le noyau (`ProfitMarginService::costFunction`) : structures configurées par l'utilisateur (`UserProductionStructures::configuredBy`), système demandé comme système favori des deux activités (`new FavoriteSystems($solarSystemId, $solarSystemId)`), `StructureSelector::select()` ; sans structure capable de l'activité, station NPC à 0,25 % (`ProductionCostCalculator::NPC_STATION_FACILITY_TAX_RATE`).

## Règles métier

- **R1 — Résolveur.** Nouvelle classe `App\Service\Industry\FacilityTaxRateResolver` (dépend de `UserProductionStructures`) : `percentFor(?User $user, int $solarSystemId, ActivityKind $activity): float`. Elle sélectionne la structure avec `StructureSelector` (structures de l'utilisateur, `FavoriteSystems($solarSystemId, $solarSystemId)`, catégorie de produit `null`) et rend sa taxe en **pourcents** (fraction × 100) ; sans structure retenue ou sans utilisateur, 0,25.
- **R2 — Taxe NPC.** Une seule constante pour 0,25 % : celle de `ProductionCostCalculator` (rendue publique), pas de copie.
- **R3 — Scanner.** Taxe résolue une fois par activité (fabrication, réaction) avant la boucle des produits, passée à `JobInstallCostEstimator::estimate()`.
- **R4 — Pivot Advisor.** Taxe résolue une fois par activité avant le calcul des candidats et du produit source, passée à `estimateJobInstallCost()` (aujourd'hui `null`).
- **R5 — Bonus de rôle.** Toujours `Multiplier::one()` dans ces deux outils (hors #7, comme dans la note de design).

## Exemples chiffrés

Résolveur (`UserProductionStructures` simulé) :

| # | Cas | Attendu |
|---|---|---|
| E1 | Raitaru (complexe d'ingénierie) dans le système demandé, taxe 0,05 ; fabrication | 5.0 |
| E2 | Aucune structure ; fabrication | 0.25 |
| E3 | Utilisateur absent (`null`) | 0.25 |
| E4 | Seule une Raitaru, réaction (un complexe d'ingénierie ne fait pas de réaction) | 0.25 |
| E5 | Tatara (raffinerie) taxe 0,02 dans le système ; réaction | 2.0 |
| E6 | Raitaru taxe 0,05 dans un autre système, rien dans le système demandé ; fabrication | 5.0 (meilleure hors système favori, comme le noyau) |
| E7 | Deux structures dans le système demandé, taxes 0,05 et 0,01, configurées à T et T+1 | celle choisie par `StructureSelector` (la plus récemment configurée à multiplicateur égal) : 1.0 |

Scanner (`BatchProfitScanRulesTest`, utilisateur sans structure → NPC 0,25 %) :

| # | Cas | Attendu (aujourd'hui) |
|---|---|---|
| E8 | Shield Extender, EIV 85, index 0,0437 | install 85 × (0,0437 + 0,0025 + 0,04) = 7,327 (7,1145) ; marge, profit, ISK/jour recalculés |
| E9 | Ferrogel, EIV 190 000, index 0,0713 | 190 000 × 0,1138 = 21 622 (21 147) ; `profitPerUnit` −4,75 |
| E10 | Utilisateur avec une Raitaru taxe 0,05 dans le système | taxe 5 % passée à l'estimateur pour la fabrication |

Pivot Advisor (`EsiCostIndexService` mocké) :

| # | Cas | Attendu |
|---|---|---|
| E11 | Candidat fabrication, utilisateur sans structure | `estimateJobInstallCost(…, 'manufacturing', 0.25)` |
| E12 | Candidat réaction, Tatara taxe 0,02 dans le système | `estimateJobInstallCost(…, 'reaction', 2.0)` ; produit source idem selon son activité |

Montant de référence (formule, `EsiCostIndexServiceTest`) : NFB, index 0,05, taxe 0,25 % → 1 575,38452 (aujourd'hui Pivot 1 532,80656).

## Cas limites

- Le résolveur est appelé au plus deux fois par scan ou analyse (une par activité), jamais par produit.
- La catégorie de produit n'entre pas dans la sélection (une seule taxe par activité) : écart possible avec Profit Margins si plusieurs structures du système portent des rigs différents. Décision prise en autonomie (mode nuit), voir journal.

## Impact affiché

Pivot Advisor et Industry Scanner : coût d'installation augmenté de la taxe (0,25 % sans structure, taxe de la structure sinon), marges en légère baisse. À annoncer avec L2/L3b.

## Hors périmètre

Buy vs Build et Profit Margins (déjà sur le noyau), BPC Kit (taxe de l'étape racine, inchangée), bonus de rôle, copie (#219), L5.

## Glossaire

Termes existants : **Taxe d'installation**, **Coût d'installation**. Aucun ajout.
