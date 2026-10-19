# Spec #3 — Un job matché compte comme avancement, il ne change pas le plan de l'étape

## Contexte

Dès qu'au moins un job ESI est matché sur une étape, le matcher (`IndustryJobMatcher::matchEsiJobs()`, `adaptStepRuns()`) ramène les runs et la quantité de l'étape au total des runs matchés, puis lance `IndustryStepCalculator::recalculateStepQuantities()`. Le rattachement manuel (`LinkJobProcessor`) duplique cette adaptation.

Conséquences observées (caractérisation, `IndustryJobMatcherTest`) :

- étape racine de 10 runs, un job de 5 runs : l'étape passe à 5 runs et le reste, même après une nouvelle passe du matcher ;
- jobs de 8 puis 5 runs sur une étape de 10 : l'étape passe à 13 runs ;
- étape enfant : la réduction est annulée en silence par le recalcul depuis le parent ;
- groupe découpé (2 × 5 runs, un job de 3) : le recalcul redistribue 4 / 6, la seconde étape gagne un run sans aucun job.

Décision de Sylvain (2026-10-19) : un job partiel ne modifie ni les runs de l'étape ni ceux de ses sœurs, il compte comme avancement.

## Règles métier

- **R1 — Plan inchangé.** Rattacher des jobs à une étape (matcher automatique ou rattachement manuel) ne modifie jamais ses runs, sa quantité ni le `totalGroupRuns` de son groupe découpé, que les runs matchés soient inférieurs, égaux ou supérieurs aux runs prévus.
- **R2 — Avancement.** Les runs matchés sont l'avancement de l'étape (affiché `runs matchés / runs prévus`, déjà en place dans `StepTree.vue`) ; un avancement peut dépasser 100 % (13 / 10).
- **R3 — Sélection des jobs inchangée.** L'algorithme de sélection reste le même : jobs qui tiennent dans les runs restants, puis au plus un job plus grand en repli (il est rattaché, l'étape reste à ses runs prévus).
- **R4 — Sœurs intactes.** Un job rattaché à une étape d'un groupe découpé ne change ni ses runs ni ceux des autres étapes du groupe.
- **R5 — Aucun recalcul dû aux runs matchés.** Le recalcul des quantités (`recalculateStepQuantities`) n'est plus déclenché par le total des runs matchés. Il reste déclenché par la correction automatique de structure (job installé dans une autre structure connue), inchangée.
- **R6 — Même règle en manuel.** `LinkJobProcessor` applique R1, R4 et R5.

## Exemples chiffrés

Harnais de `IndustryJobMatcherTest` (mocks, et vrai `IndustryStepCalculator` pour E6–E7) ; 1 unité par run sauf mention.

| # | Situation | Attendu |
|---|---|---|
| E1 | étape de 10 runs, un job de 5 runs | job rattaché ; étape à 10 runs, quantité 10 ; aucun recalcul |
| E2 | étape de 10 runs, jobs de 8 puis 5 (repli) | les deux jobs rattachés (13 runs matchés) ; étape à 10 runs |
| E3 | étape de 10 runs, un job de 12 (repli seul) | job rattaché ; étape à 10 runs |
| E4 | étape de 10 runs, jobs 6 + 4 | deux jobs rattachés ; étape à 10 (garde-fou, inchangé) |
| E5 | matcher lancé deux fois, étape de 10, un job de 5 | après la seconde passe : étape à 10, un seul match de 5 |
| E6 | étape enfant de 10 runs (calculateur réel), un job de 5 | étape enfant à 10 runs / 10 unités, job rattaché |
| E7 | groupe découpé 2 × 5 (calculateur réel), un job de 3 sur la première | première à 5, seconde à 5, `totalGroupRuns` 10 pour les deux ; job sur la première |
| E8 | réaction de 10 runs (200 unités par run), un job de 4 | étape à 10 runs, quantité 2 000 |
| E9 | job installé dans une autre structure connue | structure corrigée et recalcul lancé (garde-fou, inchangé) ; runs de l'étape inchangés |
| E10 | `LinkJobProcessor` : étape de 10, lien manuel d'un job de 5 | match créé ; étape à 10 runs, quantité 10 ; pas de recalcul |
| E11 | `LinkJobProcessor` : second lien manuel, total 13 | étape à 10 runs |

## Cas limites

- Étape sans produit SDE (sortie par run inconnue) : sans objet, la quantité n'est plus recalculée par le matcher.
- Étape achetée ou en mode manuel : inchangé (#59, garde ses jobs matchés).

## Hors périmètre

- Réparation des projets déjà réduits en prod (étape racine ramenée aux runs matchés) : l'utilisateur corrige les runs à la main.
- La redistribution proportionnelle d'un groupe découpé quand le besoin du parent change (`IndustryStepCalculator`) : elle n'est plus déclenchée par un match, comportement inchangé sinon.
- Affichage de l'avancement : déjà en place.

## Glossaire

Ajout proposé : **Avancement** (*Progress*) : runs des jobs matchés d'une étape rapportés à ses runs prévus ; il ne modifie jamais le plan de l'étape et peut dépasser 100 % (#3). Symboles : `IndustryProjectStep::getJobMatches()`, `esiJobsTotalRuns` (front).

## Tests impactés

Tests de caractérisation de `IndustryJobMatcherTest` remplacés :

- `testStepRunsShrinkToMatchedRunsWhenJobsCoverFewerRunsThanPlanned` → E1
- `testFallbackAddsOversizedJobOnTopOfPartialMatchSoStepRunsExceedPlannedRuns` → E2
- `testOversizedJobIsMatchedAsFallbackAndStepRunsGrowToItsRuns` → E3
- `testMatchingTwiceKeepsStepRunsShrunkByFirstRun` → E5
- `testAdaptedChildStepRunsAfterRecalculation` → E6
- `testSplitGroupAfterMatch`, `testSplitStepAdaptsOnlyItsOwnRunsAndLeavesTotalGroupRunsAndSiblingUnchanged` → E7
- `testReactionStepAdaptationUsesReactionOutputPerRun` → E8
- `testStepAdaptationAssumesOneUnitPerRunWhenSdeProductIsMissing` → supprimé (sans objet)
