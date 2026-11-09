# #50 lot 1 : structure de vente d'un projet

Décision de Sylvain (2026-11-09, option B) : chaque projet porte une **structure de vente**, choisie à la création et modifiable, portée par les modèles de projet. Défaut : structure de marché préférée du propriétaire. Ce lot livre le champ et son câblage. La prévision de vente de l'onglet Finances (lot 2) lira ce champ ; elle n'est pas dans ce lot.

## Règles

1. `IndustryProject` et `IndustryProjectTemplate` portent `sellStructureId` (bigint, nullable) et `sellStructureName` (string 255, nullable). Les deux sont nuls ensemble ou renseignés ensemble.
2. **Structures choisissables** d'un utilisateur : sa structure de marché préférée (`User::getPreferredMarketStructureId`, nom `getPreferredMarketStructureName`, repli `Structure <id>` si le nom manque) puis les entrées de `User::getMarketStructures()` (id, name), sans doublon d'id (la préférée gagne). Aucune autre valeur n'est acceptée.
3. Création (`POST /industry/projects`) : `sellStructureId` optionnel (entier strictement positif).
   - envoyé et choisissable pour le propriétaire : le projet le porte, avec le nom de la liste ;
   - envoyé et non choisissable : 400, rien n'est créé ;
   - absent : le projet prend la structure préférée du propriétaire, ou reste sans structure (nul) si le propriétaire n'en a pas.
4. Modification (`PATCH /industry/projects/{id}`, propriétaire ou administrateur du projet, `UpdateProjectProcessor`) : `sellStructureId` optionnel. Il est validé contre les structures choisissables **de la personne qui modifie** ; non choisissable : 400 ; projet `completed` : 400 `Cannot change the sell structure of a completed project`. Absent : inchangé (on ne peut pas effacer la structure, seulement la remplacer).
5. `IndustryProjectResource` expose `sellStructureId` et `sellStructureName` (nuls si aucun).
6. Un modèle enregistré depuis un projet (`CreateProjectTemplateProcessor`) copie `sellStructureId` et `sellStructureName` du projet. `IndustryProjectTemplateResource` les expose. Créer un projet depuis un modèle n'applique jamais la structure du modèle côté serveur : seul le champ envoyé par le client compte (règle 3). Le modèle ne se modifie pas (`UpdateProjectTemplateProcessor` inchangé).
7. Migration : ajoute les 4 colonnes (2 par table) ; les projets existants reçoivent la structure préférée de leur propriétaire (`UPDATE … FROM users`, nuls si le propriétaire n'en a pas) ; les modèles existants restent nuls ; `down()` irréversible.
8. Front, création (`NewProjectModal`) : un sélecteur « Structure de vente » liste les structures choisissables de l'utilisateur (lecture de `GET /me/settings` : `preferredMarketStructureId/Name`, `marketStructures`), présélectionne la préférée, et envoie `sellStructureId`. Appliquer un modèle présélectionne la structure du modèle seulement si elle est dans la liste ; sinon la sélection reste la préférée. Sans aucune structure : sélecteur remplacé par un avis « Aucune structure de marché configurée » (pas de blocage, le champ n'est pas envoyé).
9. Front, édition (modale d'édition de `GroupIndustryDetail`) : le même sélecteur ; la valeur initiale est celle du projet ; la structure actuelle du projet reste affichée même si elle n'est pas dans la liste de la personne ; `PATCH` n'envoie `sellStructureId` que si la sélection a changé. Désactivé quand le projet est `completed`.
10. Pas de valeur par défaut qui masque une donnée absente : un projet sans structure l'affiche comme « non définie », jamais « Jita ».

## Exemples

| Situation | Résultat |
|---|---|
| Propriétaire préféré 60003760 « Jita 4-4 », création sans `sellStructureId` | projet 60003760 / « Jita 4-4 » |
| Propriétaire sans préférée ni liste, création sans champ | nul / nul |
| Création avec 1042000000001, présent dans `marketStructures` (« Keepstar A ») | 1042000000001 / « Keepstar A » |
| Création avec 999, absent des deux sources | 400 |
| Préférée sans nom | nom `Structure 60003760` |
| PATCH par un administrateur avec une structure de l'administrateur | acceptée, nom de l'administrateur |
| PATCH sur un projet `completed` | 400 |
| Modèle enregistré depuis un projet sur 1042000000001 | modèle 1042000000001 / « Keepstar A » |

## Cas limites

- `sellStructureId` à 0 ou négatif : 422 (validation `Positive`).
- Préférée aussi présente dans `marketStructures` : une seule entrée.
- Projet existant migré sans structure : l'API renvoie nul/nul, le front affiche « non définie ».

## Hors périmètre

- La prévision de vente, `SellMarketComparison` par projet, le calcul de revenu net (lot 2, même issue).
- Modifier un modèle existant, effacer la structure, retirer un réglage existant.
- Mercure : aucune action asynchrone dans ce lot.

## Décisions prises en autonomie

- Structures choisissables = celles de la personne qui agit (création : propriétaire ; modification : l'administrateur ou le propriétaire), pas celles du propriétaire seul : les administrateurs n'ont pas accès à la liste du propriétaire.
- Migration avec rattrapage des projets existants depuis la structure préférée du propriétaire, en cohérence avec le défaut décidé.
- Nom de la structure persisté à côté de l'identifiant (comme `User::preferredMarketStructureName`) : l'affichage ne dépend pas d'une résolution ESI.
