# Spec #410 — Recherche ME proposée seulement quand elle est rentable

Décision de Sylvain (2026-11-07 et 2026-11-09, option A) : proposer la recherche seulement si le gain sur les runs prévus du projet dépasse le coût de la recherche (coût d'installation ESI, structure), sans run futur hypothétique. Prérequis livré : `InstallCost::forResearch` (#475, à valider en jeu). Gabarit suivi : la carte « Acheter ou fabriquer » (#343).

## Périmètre de cette tranche

ME seulement. La recherche TE n'a pas de gain en ISK : la comparer exige soit une durée de recherche (temps de base SDE, compétences Research et Advanced Industry du chercheur, personnage chercheur à choisir), soit une valeur du temps de slot. Question ouverte, voir « Décisions ouvertes ». La durée de la recherche ME n'est pas affichée non plus (même raison, elle n'entre pas dans le verdict).

## Règles

1. **R1 candidats.** Pour chaque produit fabriqué (activité Manufacturing, jamais une réaction) du plan dont le blueprint possédé est un **original** (`OwnedBlueprintUse::original`) avec ME < 10 : une suggestion = la recherche vers le niveau suivant (ME + 1). Un BPC, un ME 10, un produit sans blueprint possédé : aucune suggestion. Le niveau suivant seul est évalué : le coût d'un niveau croît (multiplicateur de `ResearchLevels`) alors que le gain par niveau reste du même ordre, donc si le niveau suivant ne paie pas, aucun suivant ne paie ; s'il paie, le suivant sera évalué une fois lancé.
2. **R2 runs du projet.** `runs` = somme des runs des jobs du plan pour ce produit (`Plan::jobsFor`). Le stock de départ et les surplus sont déjà dans le plan : rien à ajouter. Aucun run futur.
3. **R3 gain.** `materialSaving` = coût de production du produit sur ces runs à l'ME courant moins le même coût à ME + 1, par `IntermediateBuildCosts::totalCostOf` (matériaux du job au prix Jita pondéré, plus coût d'installation du job), mêmes structures et systèmes favoris, TE inchangé. Inconnu si l'un des deux coûts est inconnu, jamais 0.
4. **R4 coût.** `researchCost` = `InstallCost::forResearch(ResearchMaterial, EIV de fabrication d'1 run du produit, ME + 1, indice de coût de recherche du système, bonus de coût de la structure, taxe d'installation, clone alpha non)`. EIV = somme des matériaux de base du recipe × prix ajusté ESI (`EstimatedItemValue::of(new Runs(1), …)`, `EivMaterial`). Structure = `StructureSelector::select(ActivityKind::ResearchMaterial, null)` ; système = `FavoriteSystems::systemOrFavorite` ; sans structure, taxe NPC `ProductionCostCalculator::NPC_STATION_FACILITY_TAX_RATE` et bonus neutre. Indice de coût absent ou prix ajusté absent : coût inconnu avec sa raison (`MissingData`), jamais 0.
5. **R5 verdict.** `Worth` si gain et coût connus et gain **strictement supérieur** au coût ; `NotWorth` si gain et coût connus et gain ≤ coût ; `Unknown` sinon. `net` = gain − coût, absent si l'un est inconnu.
6. **R6 API.** `GET /industry/projects/{projectId}/research` (`ProjectResearchResource`, shortName `ProjectResearch`, `skip_null_values` à false), même accès que `GET …/buy-or-build` (`ProjectAccessChecker::assertAcceptedMember`, plan du propriétaire : ses blueprints, structures et systèmes favoris). Une ligne par suggestion (toutes, quel que soit le verdict) : `typeId`, `typeName`, `currentLevel`, `targetLevel`, `runs`, `materialSaving`, `researchCost`, `net` (arrondis à 2 décimales, `null` si inconnu), `verdict` (`worth`, `not_worth`, `unknown`), `missingData` (`MissingDataPresenter`). Plan impossible : 422 comme buy-or-build.
7. **R7 carte.** `ProjectResearch.vue` (store `stores/group-industry/research.ts`), montée sous `ProjectBuyOrBuild` dans l'onglet Chaîne. Elle **propose** uniquement les lignes `worth` (produit, ME courant → suivant, runs, gain, coût, net) ; les lignes `unknown` s'affichent avec « — » et la raison, jamais proposées ; les `not_worth` ne sont que comptées (« N recherches ne paient pas sur les runs du projet »). Sans aucune ligne (aucun original possédé) : la carte n'est pas rendue. Mention « à confirmer en jeu » tant que #475 n'est pas validée. Direction HUD EVE, pas de montant `?? 0`.

## Exemples chiffrés (tests rouges, `ResearchProfitabilityTest`)

Produit P, ME 0 original, plan : 1 job de 10 runs ; EIV d'1 run = 10 000 000 ; indice de recherche du système favori = 0,05 ; aucune structure configurée.

| Cas | Attendu |
|---|---|
| Coût de recherche ME 0 → 1 | système 10 000 + taxe NPC 500 + SCC 4 000 = 14 500 |
| Coût de production ME 0 = 1 000 000, ME 1 = 980 000 | gain 20 000 ; net 5 500 ; `Worth` |
| Coût ME 1 = 985 500 (gain 14 500, égal au coût) | `NotWorth`, net 0 |
| Coût ME 1 = 990 000 (gain 10 000) | `NotWorth`, net −4 500 |
| Coût de production ME 1 inconnu (prix de marché absent) | gain inconnu, `Unknown`, raison conservée |
| Indice de coût absent | coût inconnu (raison indice), `Unknown` |
| Prix ajusté d'un matériau absent | EIV inconnue, coût inconnu, `Unknown` |
| 2 jobs de P (6 et 4 runs) | `runs` = 10, un seul appel de coût par niveau avec 10 runs |
| Structure configurée (bonus 0,9, taxe 1 %) | système 9 000 + taxe 2 000 + SCC 4 000 = 15 000 |
| BPC, ME 10, produit sans blueprint possédé, réaction | aucune suggestion |
| Deux produits candidats | deux suggestions, dans l'ordre des jobs du plan |

## Cas limites

- Produit à plusieurs jobs de runs différents (limite de runs d'un BPC non concerné : un original n'a pas de plafond) : somme des runs.
- Gain négatif ou nul (arrondi du minimum d'1 par run) : `NotWorth`.
- Aucun système (ni structure, ni favori) : indice inconnu, `Unknown`.
- Recipe absent du catalogue pour un produit du plan : ignoré (le plan l'a déjà refusé en amont).

## Accès et fraîcheur

Même contrôle que buy-or-build : membre accepté du projet, données du propriétaire (blueprints visibles de ses personnages et de sa corporation par division, structures, systèmes favoris). Aucun identifiant reçu hors `projectId`. Fraîcheur : blueprints de la dernière sync (#408), prix Jita et prix ajustés en cache, calcul à la lecture. Les ME/TE d'un projet de groupe sont ceux du propriétaire, comme dans la BOM.

## Tests existants modifiés

Aucun attendu. `IndustryProjectService::ownedBlueprintsByProductOf` passe de privée à publique (`ownedBlueprintsOf`), sans changement de comportement. Les tests de `GroupIndustryDetail` qui rendent l'onglet Chaîne doivent répondre à la nouvelle requête sans changer leurs assertions.

## Hors périmètre

Recherche TE, durée de la recherche, plusieurs niveaux dans un même job, bonus de rig de recherche, écran Blueprints (#409 : carte « Recherche à lancer ? » de la maquette `#p=30` déplacée dans le projet), BPC, lancement du job depuis l'application.

## Décisions ouvertes (non implémentées, pas de `Closes`)

TE : option A (recommandée) gain de temps = secondes de fabrication économisées sur les runs du projet (compétences du personnage chercheur) comparé à la durée de recherche du même personnage ; option B valeur en ISK d'une heure de slot saisie par l'utilisateur. Durée ME : informative, mêmes prérequis (temps de base SDE activité 4, personnage chercheur).

## Glossaire

Ajouter « Recherche rentable » : recherche ME proposée quand le gain en matériaux sur les runs du projet dépasse son coût d'installation.
