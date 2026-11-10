# 575 - Tout projet démarre avec la blacklist des réglages

Mode nuit : spec validée en autonomie (circuit m, niveau 3 attendu : code Industry).

## Contexte
La blacklist des réglages (`User::getIndustryBlacklistGroupIds()` / `getIndustryBlacklistTypeIds()`, `PUT /industry/blacklist`) n'est reprise qu'en partie à la création d'un projet : le serveur ne lit que l'input, le formulaire ne coche que les catégories entièrement blacklistées, Buy vs Build et les modèles remplacent les listes. Le scanner et les marges (`IndustryBlacklistService::resolveBlacklistedTypeIds`) excluent alors des items que le projet fabrique.

## Règles
1. `CreateProjectProcessor::process` fait l'union, sans doublon, des groupes et des types des réglages de l'utilisateur avec ceux de l'input. Les groupes de l'input sont d'abord ceux de `IndustryBlacklistService::resolveGroupIds(blacklistGroupIds, blacklistCategoryKeys)` (catégories résolues comme aujourd'hui), puis ceux des réglages absents de cette liste, dans l'ordre des réglages. Même ordre pour les types : input d'abord, puis réglages absents.
2. L'union s'applique quelle que soit l'entrée : formulaire, modèle (`templateId`), Buy vs Build (`blacklistTypeIds` = `excludedTypeIds`), appel API direct. Aucune de ces entrées ne remplace les réglages.
3. Réglages vides : l'input seul, inchangé. Input vide : les réglages seuls.
4. Les projets existants ne sont pas modifiés (aucune migration). Le projet en brouillon garde `ProjectBlacklistEditor` et `UpdateProjectProcessor::changeTheBlacklist()` pour retirer une entrée : inchangés.
5. Formulaire `NewProjectModal.vue` : une catégorie cochée par les réglages (`blacklisted` dans `GET /industry/blacklist`) est cochée et désactivée (`disabled`, non décochable), avec la mention « depuis vos réglages » (clé i18n en et fr). Les autres catégories restent libres ; une catégorie partielle reste décochée et libre (ses groupes arrivent par l'union serveur).
6. Si `GET /industry/blacklist` renvoie au moins un item unitaire (`items`), le formulaire affiche « N item(s) exclu(s) par vos réglages » (pluriel i18n). Aucune mention à 0.
7. Réglages indisponibles (échec du chargement) : comportement actuel (avertissement, rien de coché ni de verrouillé) ; le serveur applique malgré tout les réglages.
8. L'envoi du formulaire garde `blacklistCategoryKeys` des catégories cochées (réglages comprises) : l'union serveur dédoublonne.

## Exemples chiffrés (tests rouges)
| Réglages (groupes / types) | Input (groupes / catégories / types) | Projet (groupes / types) |
|---|---|---|
| [1136] / [34] | [999] / – / [35] | [999, 1136] / [35, 34] |
| [1136] / [34] | [1136, 999] / – / [34] | [1136, 999] / [34] |
| [] / [] | [999] / fuel_blocks / [35] | [999, 1136] / [35] |
| [1136, 484] / [34, 35] | [] / – / [] | [1136, 484] / [34, 35] |
| [334] / [] | [] / biochem_reactions / [] | [661, 662, 334] / [] |

Front : réglages avec `fuel_blocks` et `complex_reactions` blacklistées, `items` de 3 entrées → ces deux cases cochées et `disabled`, les autres actives, mention « depuis vos réglages » présente, texte « 3 items » présent. `items` vide → pas de mention de nombre.

## Cas limites
- Une catégorie résolue côté input et déjà dans les réglages : un seul exemplaire.
- Le modèle porte une blacklist différente de celle des réglages : l'union des deux (le serveur reçoit les listes du modèle via l'input).
- Réglages avec un groupe hors des catégories prédéfinies : repris tel quel dans les groupes du projet.

## Accès
Aucun identifiant reçu d'un autre périmètre : les réglages sont ceux de l'utilisateur authentifié (`Security::getUser()`), jamais ceux du propriétaire d'un autre projet. Aucune donnée de corporation.

## Tests existants contredits
- `frontend/tests/components/group-industry/newProjectModalBlacklistDefaults.spec.ts` « creates the project without a category the user unchecked » : la catégorie des réglages n'est plus décochable. Remplacé par un test où la case est désactivée et reste dans `blacklistCategoryKeys` (changement de règle voulu, journalisé).
- Les tests de `CreateProjectProcessorTest` utilisent un utilisateur sans réglages : inchangés.

## Hors périmètre
- Recherche d'items à blacklister dans le formulaire ; blacklist des modèles (`CreateProjectTemplateProcessor`) ; `PUT /industry/blacklist` ; modification de projets existants.
- Aucun réglage, champ, route ou écran retiré.

## Glossaire
Aucun terme ajouté : « blacklist » et « réglages » existent.
