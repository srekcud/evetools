# #344 (suite) — Probabilité d'invention d'un personnage choisi

Décision de Sylvain (2026-11-05, 19:03) : un sélecteur de personnage dans l'analyse de marge. Suite de `344-decryptor-comparison.md`. Circuit M, mode nuit. Maquette : `industry-project-v3.html#p=24` (probabilité 34 % → 45,6 % avec les compétences).

## Constat

- Le noyau sait calculer la probabilité (`InventionChance::of`, 1/30 par niveau de science, 1/40 par niveau d'encryption) mais `ProfitMarginService` lui passe 0 partout (`INVENTION_SKILL_LEVEL_WITHOUT_SKILLS`).
- La recette (`InventionRecipe`) ne dit pas quelles compétences l'invention demande ; le SDE les porte (`sde_industry_activity_skills`, activité 8). Lu en base le 2026-11-05 : 1008 blueprints ont 3 compétences (2 sciences + 1 encryption), 94 en ont 4 (plus *Capital Ship Construction*, groupe Production), 6 n'ont que 2 sciences, 1 n'a que *Science*.
- La synchro des compétences (`SyncCharacterSkillsProcessor`) ne met en cache que les compétences des activités 1 et 11 : les compétences d'invention ne sont jamais connues, et une compétence à 0 n'est écrite que pour 3 ids (`SKILLS_WRITTEN_AT_ZERO`).

## Règles

1. **Compétences requises** : nouveau port `InventionSkillRequirements::of(t1BlueprintTypeId): InventionSkillRequirement` (sciences : 0 à 2 ids ; encryption : un id ou aucun), adaptateur SDE `SdeInventionSkillRequirements`. Science = compétence du groupe 270 hors encryption ; encryption = un des types `3408, 21790, 21791, 23087, 23121, 52308, 55025` (constantes nommées, vérifiées dans le SDE). Plus de 2 sciences ou plus d'une encryption : `UnexpectedValueException` (SDE incohérent).
2. **Niveaux de l'inventeur** : `InventorSkills::levelsFor(productTypeId, ?Character)` rend `InventionSkillLevels` (science 1, science 2, encryption, chacun `?int`).
   - Sans personnage : 0, 0, 0 (comportement actuel, « sans compétences »).
   - Avec personnage : le niveau en cache de chaque compétence requise ; une compétence requise sans ligne en cache = `null` (inconnu) ; un emplacement que la recette n'utilise pas (recette à 2 sciences, sans encryption) = 0.
3. **Synchro** : `SyncCharacterSkillsProcessor` ajoute les compétences de l'activité 8 aux compétences utiles et les écrit à 0 quand le personnage ne les a pas (même mécanisme que `SKILLS_WRITTEN_AT_ZERO`), pour distinguer « niveau 0 » de « jamais synchronisé ». Un personnage déjà synchronisé n'a pas ces lignes : ses compétences d'invention restent inconnues jusqu'à la prochaine synchro (bouton existant des réglages). Aucune commande console.
4. **Moteur** : `InventionSettings` accepte des niveaux `?int`. Un niveau `null` rend la probabilité inconnue : `InventionCost::$chance` `null`, coût d'invention et tentatives inconnus, jamais 0, avec la donnée manquante `MissingData::inventorSkills(t1BlueprintTypeId)` (raison `inventor_skills`). Une probabilité de base absente du SDE garde sa raison `invention_probability`.
5. **API** : `GET /industry/profit-margin/{typeId}` accepte `inventorCharacterId` (UUID d'un personnage). Le personnage doit appartenir à l'utilisateur connecté, sinon 404 `Character not found` (UUID invalide compris). Absent : sans compétences.
6. **Réponse** : `invention.inventor` vaut `null` sans personnage, sinon `{characterId, characterName, skillsKnown}` (`skillsKnown` faux si au moins une compétence requise est inconnue). Sans compétence inconnue, `probability` de chaque option est la probabilité du personnage (arrondie par l'affichage seulement). Avec une compétence inconnue : `probability`, `expectedAttempts`, `inventionCost`, `totalProductionCost`, `costPerUnit`, `costPerUnitDelta` et `bestMargin` `null`, `invention.unknownReason` = `missing_inventor_skills`.
7. **Front** : `InventionOptionsPanel` affiche un sélecteur : « Sans compétences » puis les personnages de l'utilisateur. Défaut : son personnage principal, sinon « Sans compétences ». Changer le choix relance l'analyse avec `inventorCharacterId` (omis pour « Sans compétences »). Le libellé « (sans compétences) » de la colonne probabilité ne s'affiche que pour « Sans compétences ». Compétences inconnues : probabilité et coûts en « Inconnu » (libellé déjà utilisé), message `industry.inventionUnknown.missing_inventor_skills` qui dit de synchroniser les compétences du personnage dans les réglages.

## Exemples chiffrés (probabilité de base 0,34)

| Personnage | Sciences | Encryption | Probabilité |
|---|---|---|---|
| aucun | 0 + 0 | 0 | 0,34 |
| A | 5 + 5 | 5 | 0,34 × (1 + 10/30 + 5/40) = 0,495833 |
| B | 4 + 3 | 2 | 0,34 × (1 + 7/30 + 2/40) = 0,43867 |
| C (une science jamais synchronisée) | 5 + ? | 5 | inconnue |

## Cas limites

- Recette à 2 sciences sans encryption : encryption comptée 0, jamais inconnue.
- Produit non inventé (T1) : `invention` reste `null`, le personnage est ignoré, aucune requête de compétences.
- Compétence saisie à la main (`isManual`) : lue comme une ligne en cache.

## Hors périmètre / décisions

- Colonne Jobs : #383. BPC kit du projet : #384. `BuyVsBuildService` garde 0 (pas de sélecteur).
- Décision prise seule : le défaut du sélecteur est le personnage principal (cohérent avec « personnage principal » de l'issue précédente) ; les personnages sans compétences synchronisées affichent « Inconnu » plutôt qu'une valeur supposée.
- Action utilisateur au déploiement : resynchroniser les compétences de chaque personnage (bouton des réglages) pour renseigner les compétences d'invention.
