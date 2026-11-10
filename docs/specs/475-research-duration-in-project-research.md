# Spec #475 (suite) — Durée d'une recherche ME et TE dans la carte « Recherche »

Suite de #481 (noyau coût et durée) et de #410 (carte `ProjectResearch`). L'issue #475 reste ouverte : validation en jeu par Sylvain. Cette tranche lit le temps de base du SDE et affiche la durée d'une recherche ME et TE du BPO possédé. Elle n'entre dans aucun verdict.

## Constat vérifié (décision D1)

- Import SDE : `SdeBlueprintImporter` écrit déjà le temps des activités 3 (TE) et 4 (ME) dans `sde_industry_activities`. Aucun import à relancer. Réserve : un champ `time` absent est écrit 0 (`?? 0` préexistant, hors périmètre) ; la lecture traite 0 comme inconnu.
- **Le diviseur de `ResearchJobDuration::of` est faux.** #481 calcule `temps SDE / 6000 × temps du niveau`. Base locale (SDE importé) : Rifter (691) = 2 100 s, blueprint 681 = 210 s ; sur 4 338 blueprints, 3 863 ont un temps multiple de 105 (le niveau 1 du rang 1), 140 seulement un multiple de 6 000 (6 000 est le temps de fabrication d'un rang 1, pas sa recherche). Avec 6 000, la recherche ME 0→1 du Rifter durerait 36,75 s. Le rang est `temps SDE / 105` : durée de base = temps SDE × `ResearchLevels::costMultiplier(niveau)` (table / 105).
- Ce point reste **[À CONFIRMER EN JEU]** (point « rang » de #481), mais la table SDE tranche déjà contre `/ 6000`.

## Règles

1. **R1 formule.** `ResearchJobDuration::of` : `round(tempsSde × costMultiplier(niveau visé) × (1 − 5 % × compétence de recherche) × (1 − 3 % × Advanced Industry) × bonus de structure)`, un seul arrondi. Signature inchangée ; `RANK_ONE_BASE_TIME_SECONDS` disparaît. Niveau visé, compétences, bornes et erreurs : inchangés (#475 R1, R5).
2. **R2 temps de base.** Port `ResearchBaseTimes::of(int $productTypeId, ActivityKind): ?int` (Application), implémenté en Infrastructure/Sde : blueprint de fabrication du produit (`findBlueprintForProduct`, activité 1), puis ligne `sde_industry_activities` de l'activité 4 (ME) ou 3 (TE). Ligne absente ou temps 0 : inconnu, `MissingData::researchBaseTime(typeId du produit)` (580 blueprints fabricables n'ont pas de recherche ME dans le SDE local).
3. **R3 chercheur.** `OwnedBlueprintUse` gagne `?int $researcherEveCharacterId`, l'id du personnage qui possède l'original (`OwnedBlueprint::getCharacter()`), null pour un original de corporation. À efficacité égale, le BPO personnel passe avant celui de la corporation (ordre actuel de `visibleBlueprintsOf`, inchangé). Chercheur null : durée inconnue, `MissingData::researcher()`, jamais le personnage principal supposé (décision D4).
4. **R4 compétences.** Port `CharacterSkillLevels::levelOf(int $characterId, int $skillTypeId): ?int` (Application), implémenté sur `cached_character_skills`. Ligne absente : `null` donc durée inconnue (`researchSkill(typeId)`). ME : Metallurgy (3409) + Advanced Industry (3388). TE : Research (3403) + Advanced Industry. Advanced Industry rejoint `SyncCharacterSkillsProcessor::SKILLS_WRITTEN_AT_ZERO` : la sync écrit son vrai niveau, 0 compris (l'ESI ne renvoie pas les compétences non entraînées) : il se remplit au prochain « sync compétences » (décision D2).
5. **R5 structure.** Bonus de temps neutre (`Multiplier::one()`) : `ProductionStructure` ne porte aucun bonus de temps de recherche. La carte l'écrit : « sans bonus de structure » (décision D3).
6. **R6 niveaux visés.** ME : niveau `ME courant + 1` du job (la suggestion existante, ME < 10). TE : `TE courant / 2 + 1` (TE 0 → niveau 1 … TE 18 → niveau 10) ; TE du job, comme le ME. TE ≥ 20 : pas de recherche TE, la valeur est `null` (pas inconnue).
7. **R7 API.** Chaque ligne de `GET /industry/projects/{projectId}/research` gagne `meResearchDuration` et `teResearchDuration` : `{targetLevel, seconds, missingData}` (`seconds` null si inconnue ; `missingData` via `MissingDataPresenter`) ou `null` pour le TE à 20. Les `missingData` de la durée restent **séparés** de ceux du verdict : une durée inconnue ne rend jamais une ligne `unknown`. Même accès et même fraîcheur que la ligne (compétences du chercheur lues à la requête, pas de cache nouveau).
8. **R8 carte.** `ProjectResearch.vue` affiche sur chaque ligne proposée « Durée ME » (vers le niveau suivant) et « Durée TE », en jours/heures/minutes (`formatDuration`). Inconnue : « — » et la raison (clés `groupIndustry.research.reason.*`, ajouter `research_base_time` et `researcher`). Mention « informative, hors verdict ». Les lignes `not_worth` restent seulement comptées.

## Exemples chiffrés (tests rouges)

Blueprint de base 2 100 s (Rifter) sauf mention ; structure neutre.

| Cas | Attendu |
|---|---|
| ME 0→1, Metallurgy 0, AI 0 | 2 100 s |
| Blueprint de base 210 s, ME 0→1, compétences 0 | 210 s |
| ME 4→5 (niveau 5), Metallurgy V, AI IV | 2 100 × 32 × 0,75 × 0,88 = 44 352 s |
| ME 9→10, Metallurgy V, AI V | 2 100 × 2 438,095 × 0,6375 = 3 264 000 s |
| TE 4→6 (niveau 3), Research IV, AI III | 2 100 × 5,667 × 0,8 × 0,91 = 8 663 s |
| TE 20 | `teResearchDuration` = null |
| TE 18→20 (niveau 10), compétences 0 | 2 100 × 2 438,095 = 5 120 000 s |
| Metallurgy absente, Research présente | ME inconnue (`research_skill` 3409), TE calculée |
| AI absente | ME et TE inconnues (`research_skill` 3388) |
| Original de corporation | ME et TE inconnues (`researcher`) |
| Pas de ligne activité 4 / temps 0 | ME inconnue (`research_base_time`, produit) ; TE indépendante |
| Durée ME inconnue, gain 20 000 > coût 14 500 | verdict `worth`, inchangé |

## Cas limites

- Deux originaux du même produit chez deux personnages : celui de la meilleure efficacité (`isBetterThan`) fournit le chercheur ; à égalité, le premier lu.
- ME 10 : pas de ligne (R1 de #410), donc pas de durée.
- Durée très longue (millions de secondes) : `int`, aucun plafond ; la carte l'affiche en jours.
- TE impair (impossible côté ESI) : `InvalidArgumentException` de `ResearchLevels`/R6, pas d'arrondi silencieux.

## Accès et fraîcheur

Inchangés : `assertAcceptedMember`, plan et blueprints du propriétaire du projet. Les compétences lues sont celles du personnage du propriétaire qui possède l'original ; un original de corporation n'a pas de chercheur (R3). Aucun identifiant reçu hors `projectId`. Fraîcheur : dernière sync des compétences ; un personnage jamais resynchronisé depuis #481 donne « inconnu » jusqu'à sa prochaine sync.

## Option A du verdict TE (#410) : la durée suffit-elle ?

Oui pour son second terme. `teResearchDuration.seconds` est la durée de recherche du personnage possesseur, avec ses compétences ; il reste à calculer les secondes de fabrication économisées (durée du job du plan à TE + 2 contre TE, × runs du projet, déjà modélisée par les spec #126/#134). Rien n'est codé : le choix A ou B reste à Sylvain.

## Tests existants modifiés

- `ResearchJobDurationTest` : exemples de #475 (6 000 s) recalculés avec R1 ; le diviseur change, pas la structure du test.
- `ResearchProfitabilityTest`, `ResearchRowPresenterTest`, `ProjectResearchApiTest`, `OwnedBlueprintUseTest` : nouveaux champs, assertions existantes inchangées.
- `SyncCharacterSkillsProcessorTest` : les cartes attendues incluent Advanced Industry à 0 (si D2).

## Hors périmètre

Verdict TE (options A et B), bonus de temps de structure ou de rig de recherche, implants, choix d'un autre personnage chercheur, plusieurs niveaux dans un job, correction du `?? 0` de l'importer, lancement du job, coût de la recherche TE.

## Décisions ouvertes (pour Sylvain)

- **D1** (bloquante) : corriger le diviseur de #481 (`/ 6000` → `/ 105`). Recommandé : oui, preuve SDE ci-dessus. Sinon la carte afficherait des durées 57 fois trop courtes.
- **D2** : écrire Advanced Industry à 0 dans la sync (recommandé, même principe que #481 ; touche les cartes de `SyncCharacterSkillsProcessorTest`) ou traiter une ligne absente comme 0.
- **D3** : bonus de structure neutre et affiché comme tel (recommandé) ou modéliser les bonus de temps de recherche des Engineering Complex (donnée non vérifiée, hors lot).
- **D4** : original de corporation = durée inconnue (recommandé, conforme au brief) ou meilleur personnage du propriétaire.

## Glossaire

Modifier « Job de recherche » : durée = `temps SDE × temps du niveau / 105 …` (rang = temps SDE / 105), durée branchée sur la carte (#475 suite). Ajouter « Chercheur » : personnage qui possède le BPO ; ses compétences font la durée.

## Prod

Aucune migration. Au déploiement : lancer « sync compétences » (Advanced Industry à 0, Metallurgy, Research) ; pas de réimport SDE.
