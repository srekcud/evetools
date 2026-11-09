# Spec #475 — Coût et durée d'un job de recherche ME/TE

Préalable de #410. Noyau pur (`src/Industry/Domain`), lecture des compétences de recherche, cost index de recherche. Rien n'est branché sur un écran ni sur le plan d'un projet (c'est #410).

## Sources et statut

- Table de temps par niveau, blueprint de rang 1 (blog CCP « Researching, the Future ») : 105, 250, 595, 1414, 3360, 8000, 19000, 45255, 107700, 256000 secondes pour les niveaux 1 à 10. Rang d'un blueprint = temps de base de recherche du SDE / 6 000 (même blog).
- Multiplicateur de coût d'un niveau = temps du niveau / temps du niveau 1 (développeur CCP cité sur le forum ; niveau 10 = 2 438,1, cohérent avec le calcul de coût de recherche cité sur le forum).
- Base de coût = 2 % de l'EIV de fabrication du blueprint × multiplicateur du niveau visé ; SCC de recherche 2 % (baisse de CCP du 2025-07-18, annonce « Exploration & Industry Balance Rework »).
- Compétences : Metallurgy (3409) −5 % par niveau sur la durée de recherche ME, Research (3403) −5 % par niveau sur la recherche TE, Advanced Industry (3388) −3 % par niveau sur les deux (descriptions de compétence, sources de la communauté).
- **[À CONFIRMER EN JEU]** : tout le modèle, jusqu'à la validation de Sylvain (une recherche ME et une recherche TE lancées en jeu, comparées au modèle). Points précis : un job = un niveau visé ; Advanced Industry sur la recherche ; rang = temps SDE / 6 000 ; base de coût sans rang.

## Règles

1. **R1 niveau visé.** Un job de recherche vise un niveau de 1 à 10. ME : niveau ME visé. TE : TE visé / 2 (TE 2 → niveau 1 … TE 20 → niveau 10). Hors de 1 à 10 : `InvalidArgumentException`.
2. **R2 activités.** `ActivityKind` gagne `ResearchMaterial` et `ResearchTime`. Cost index ESI : `researching_material_efficiency` et `researching_time_efficiency`. Une structure qui sait copier sait rechercher : même règle de choix que la copie (taux d'installation minimal).
3. **R3 coût d'installation.** `InstallCost::forResearch(ActivityKind, Cost $manufacturingEiv, int $targetLevel, ?float $systemCostIndex, Multiplier $structureCostModifier, float $facilityTaxRate, bool $alphaClone)`. `base = EIV × 0,02 × multiplicateur(niveau)` ; `coût_système = base × cost index × bonus de structure` ; taxe d'installation sur la base ; SCC = base × 2 % ; alpha = base × 0,25 % (clone alpha). Même détail que `forJob` (système, taxe, SCC, alpha, total). Une activité autre que la recherche : `InvalidArgumentException` ; `forJob` refuse la recherche.
4. **R4 inconnu.** Cost index absent ou EIV inconnue : coût inconnu avec sa raison (`MissingData`), jamais 0.
5. **R5 durée.** `ResearchJobDuration::of(ActivityKind, int $baseTimeSeconds, int $targetLevel, ?int $metallurgyOrResearchLevel, ?int $advancedIndustryLevel, Multiplier $structureTimeModifier)` : `round(baseTimeSeconds / 6000 × table[niveau] × (1 − 5 % × compétence de recherche) × (1 − 3 % × Advanced Industry) × bonus de structure)`, un seul arrondi. Compétence de recherche : Metallurgy pour ME, Research pour TE. Niveaux de compétence hors de 0 à 5 : `InvalidArgumentException`.
6. **R6 durée inconnue.** Une compétence de recherche ou Advanced Industry non synchronisée (`null`) rend la durée inconnue (`MissingData::researchSkill(typeId de la compétence)`), jamais celle d'un niveau 0 supposé. Un niveau 0 synchronisé est un vrai 0.
7. **R7 lecture des compétences.** Metallurgy et Research sont ajoutées à `CachedCharacterSkill::INDUSTRY_SKILL_IDS` (lues par la sync des compétences) et écrites même à 0 (comme Science), pour distinguer « 0 » de « pas encore synchronisé ». Elles se remplissent au prochain « sync compétences ».

## Exemples chiffrés (EIV 10 000 000, cost index 0,05, structure neutre, taxe 0 %, pas alpha)

| Cas | Attendu |
|---|---|
| ME niveau 1 | base 200 000 ; système 10 000 ; SCC 4 000 ; total 14 000 |
| ME niveau 10 | base 200 000 × 2 438,1 = 487 619 047 ; système 24 380 952 ; SCC 9 752 381 ; total 34 133 333 |
| TE 20 = niveau 10 | identique à ME niveau 10 |
| TE 2 = niveau 1 | identique à ME niveau 1 |
| Cost index absent | total inconnu (raison cost index) |
| ME niveau 1, base 6 000 s, skills 0, structure neutre | 105 s |
| ME niveau 10, base 6 000 s, Metallurgy V, AI V | 256 000 × 0,75 × 0,85 = 163 200 s |
| TE niveau 3, base 12 000 s, Research IV, AI 0 | 595 × 2 × 0,8 = 952 s |
| Metallurgy `null` | durée inconnue (`researchSkill(3409)`) |

## Hors périmètre

Brancher la recherche sur un projet, proposer la recherche rentable (#410), lire le temps de base SDE (activités 3 et 4) et l'EIV depuis les dépôts, bonus de structure ou de rig de recherche (le multiplicateur est fourni par l'appelant), plusieurs niveaux dans un même job, implants, écran.

## Décisions ouvertes

Les points « à confirmer en jeu » ci-dessus ; l'appelant (#410) fournit EIV, temps de base SDE et multiplicateurs de structure.
