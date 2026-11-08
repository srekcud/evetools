# 445 - Modifier la blacklist d'un projet tant qu'il est en brouillon

## Règles
1. `PATCH /industry/projects/{id}` accepte `blacklistGroupIds`, `blacklistTypeIds`, `blacklistCategoryKeys` (tableaux, 200 entrées au plus chacun, comme à la création). `null`/absent = champ inchangé ; `[]` = vide.
2. Groupes de la blacklist : si `blacklistGroupIds` ou `blacklistCategoryKeys` est envoyé, la liste de groupes du projet est remplacée par `IndustryBlacklistService::resolveGroupIds(blacklistGroupIds ?? [], blacklistCategoryKeys ?? [])` (groupes explicites, puis groupes des catégories connues, sans doublon ; clé inconnue ignorée). Même résolution que `CreateProjectProcessor` (factorisée).
3. Types : si `blacklistTypeIds` est envoyé, la liste de types est remplacée.
4. Seul un projet en `draft` accepte le changement : sinon 400 « The blacklist of a project can only be changed while it is a draft », rien n'est modifié ni enregistré. Le statut pris en compte est celui d'avant le PATCH. Droit d'accès inchangé : propriétaire ou admin.
5. À l'enregistrement, `IndustryProjectService::buildBom($project, $project->getOwner())` est appelé après l'application de la nouvelle blacklist, avant le flush : les lignes devenues inutiles disparaissent, celles qui portent une contribution restent (comportement actuel de `reconcileBomLines`). Une `RuntimeException` du calcul (trop de lignes) devient un 400 avec son message.
6. Aucun champ de blacklist envoyé : pas de recalcul du BOM.

## Front (fiche projet, onglet Materials & Jobs)
- Composant `ProjectBlacklistEditor` : bouton « Edit blacklist » visible seulement si le projet est `draft` et si l'utilisateur est propriétaire ou admin.
- À l'ouverture, les catégories viennent de `GET /industry/blacklist` (clé, libellé, groupes) ; une catégorie est cochée si tous ses groupes sont dans `project.blacklistGroupIds`.
- Enregistrer envoie `PATCH` avec `blacklistCategoryKeys` (cochées), `blacklistGroupIds` (groupes du projet hors catégories, conservés), `blacklistTypeIds` (inchangés, conservés), puis recharge le BOM.
- Une erreur de l'API s'affiche dans l'éditeur, qui reste ouvert.

## Hors périmètre
- Recherche d'items à blacklister : la création n'en propose pas non plus ; les types déjà présents (modèle, préremplissage) sont conservés tels quels, sans nom affiché.
- Blacklist de l'utilisateur (`/industry/blacklist` PUT), modèles de projet, #139.
