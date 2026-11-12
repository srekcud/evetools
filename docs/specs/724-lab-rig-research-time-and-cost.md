# Spec #724 : bonus de temps et de coût des rigs de laboratoire sur la recherche ME/TE

Suite de #720 (catalogue) et #523 (copie). Validée en autonomie (mode nuit), niveau 3 (code Industry, assertions de tests modifiées).

## Contexte
Le moteur de #523 lit les rigs de science par effet SDE (6830-6837, attributs 2593 temps et 2595 coût) et les applique à la copie. La recherche ME/TE ignore la structure pour sa durée, le coût d'installation TE n'est calculé nulle part, et le catalogue n'expose pas le bonus de coût des rigs.

## Constats SDE (base de dev, 2026-11-12)
- Un seul attribut de rôle de temps pour les complexes d'ingénierie : 2602 `strEngTimeBonus` (« Time bonus for Engineering Complexes Structures », Raitaru 0,85, Azbel 0,80, Sotiyo 0,70). Aucun attribut propre à la recherche ni à l'invention : la copie (#523) le lit déjà, la recherche fait de même. À vérifier en jeu (voir décisions ouvertes).
- Bonus de rig (valeurs entières, `value_int`) : I = 20 temps / 10 coût, II = 24 / 12. Accelerator : temps seul. Cost Optimization : coût seul. L-Set et XL-Set : les deux.
- L'application n'affiche aucune durée d'invention : l'invention reste hors périmètre (pas de modèle de durée, pas d'écran).

## Règles
1. `ProductionStructure::timeMultiplierFor(activity)` accepte toute activité de laboratoire (`isLaboratory()` : copie, invention, recherche ME, recherche TE) = `timeRoleBonus` × Π `timeBonus->multiplierIn(sécurité)` des `ScienceRig` de **cette** activité. Fabrication et réaction lèvent toujours `InvalidArgumentException`.
2. `SdeStructureBonusReader::timeMultiplierOf(typeId, activity)` lit l'attribut 2602 pour toute activité de laboratoire ; absent (Athanor, Tatara) = `null`. Fabrication (2602) et réaction (2721) inchangées.
3. `ResearchProfitability` : la durée ME utilise la structure choisie pour `ResearchMaterial`, la durée TE celle choisie pour `ResearchTime` (même sélecteur que le coût ME). Modificateur de structure = `timeMultiplierFor(activité)` ; aucune structure configurée = `Multiplier::one()` (station NPC). Un rig d'une autre activité n'a aucun effet (rig TE sans effet sur la durée ME, rig de copie ou d'invention sans effet sur la recherche).
4. Coût d'installation TE : `ResearchSuggestion::$teResearchCost` (`?Cost`, null quand le TE est à 20) = `InstallCost::forResearch(ResearchTime, …)` avec l'indice de coût `ResearchTime` du système et `installCostMultiplierFor(ResearchTime)` de la structure choisie pour `ResearchTime`. Indice absent : coût inconnu avec sa cause, jamais 0. Il n'entre ni dans le verdict ni dans `missingData` de la suggestion.
5. API `GET /industry/projects/{projectId}/research` : chaque ligne gagne `teResearchCost` = `null` (TE à 20) ou `{amount: ?float, missingData}` (montant arrondi à 2 décimales, comme `researchCost`).
6. Catalogue : `RigCatalogue` garde `timeBonus` et ajoute `costBonus` (pourcentage, comme `timeBonus`) sur les rigs de laboratoire qui portent un bonus de coût : Cost Optimization (M), L-Set, XL-Set. Valeurs SDE : I = 10, II = 12.
7. Écran de structure (`StructureConfig.vue`) : le bonus de coût s'affiche à côté du bonus de temps ; le bonus de temps n'est plus étiqueté « TE » (libellés `fr.json` et `en.json` : temps / time, coût / cost). Écran de recherche : le coût TE s'affiche près de la durée TE.

## Exemples chiffrés
Sécurité : high ×1,0, low ×1,9, null ×2,1. Rig II : temps 24, coût 12 ; nullsec : ×0,496 temps, ×0,748 coût.

| Cas | Attendu |
|---|---|
| Sotiyo (0,70) + XL Lab II, nullsec, `timeMultiplierFor(ResearchMaterial)` | 0,70 × 0,496 = 0,3472 |
| Idem, high / low | 0,532 / 0,3808 |
| Raitaru (0,85) + M ME Research Accelerator II, nullsec | ME 0,85 × 0,496 ; TE 0,85 ; copie 0,85 |
| Raitaru + M TE Research Accelerator II | TE 0,85 × 0,496 ; ME 0,85 |
| Rig de copie ou d'invention seul | recherche ME/TE = rôle seul |
| Rig de recherche seul | copie et invention = rôle seul |
| Raitaru + M TE Research Cost Optimization II, coût | `installCostMultiplierFor(ResearchTime)` = 0,97 × 0,748 ; ME = 0,97 |
| Durée ME : base 2 100 s, niveau 5, Metallurgy 5, Adv. Industry 4, sans structure | 44 352 s (inchangé) |
| Même durée dans un Raitaru nullsec avec M ME Accelerator II | round(44 352 × 0,85 × 0,496) = 18 699 s |
| Durée TE : base 2 800 s, niveau 4, Research 4, Adv. Industry 4, Raitaru + M TE Accelerator II | round(26 545 × 0,85 × 0,496) = 11 192 s |
| Catalogue | M ME Research Cost Optimization II : `costBonus` 12, pas de `timeBonus` ; M ME Research Accelerator II : `timeBonus` 24, pas de `costBonus` ; L TE Research Optimization I : 20 et 10 ; XL Lab II : 24 et 12 ; M Copy Accelerator I : 20 et pas de coût |

## Cas limites
- Structure sans rôle de temps lu : 1. Athanor et Tatara ne lancent pas de recherche (`canRun`) : jamais choisis.
- Durée inconnue (compétence, base de temps, chercheur) : inchangée, la structure ne la rend jamais connue.
- Plusieurs structures : `StructureSelector` garde le critère de #526 (taux d'installation le plus bas), pas la plus rapide.

## Hors périmètre
Durée d'invention (aucun écran), rigs de retraitement, de fabrication et de réaction, coût TE dans le verdict.

## Décisions ouvertes (Sylvain)
- Le rôle 2602 s'applique-t-il en jeu à la recherche ME/TE comme à la copie ? Le SDE ne distingue pas les activités.
- Sélection de la structure de recherche : taux d'installation le plus bas, pas la durée la plus courte.
