# #583 — État « accès requis » des onglets Taxe lunaire et Chunks

Mode nuit : spec validée en autonomie. Circuit m, niveau de technicité 2 (nouvelle ressource API sur les personnages et rôles de l'utilisateur ; aucun nouvel endpoint ESI, aucune migration). Prérequis livrés : #581 (lecteur de rôles), #582 (corporations NPC écartées).

## Contexte

Sans le rôle exigé, Taxe lunaire affiche le message brut du 403 (tous les personnages, tous leurs rôles) et Chunks une phrase sans personnage ni marche à suivre. C'est une condition d'accès connue, pas une erreur.

## Règles back

1. `CorporationRoleFeature` (enum, `moon-tax` → rôle `Accountant`, `extractions` → rôle `Station_Manager`) donne le rôle exigé ; `Director` est toujours accepté (#581).
2. `CorporationRoleAccessInspector::inspect(User, corporationId, feature)` renvoie un `CorporationRoleAccessState` : `status` (`held`, `role_missing`, `reconnect`, `esi_error`, correspondance 1-1 avec `CorporationRoleStatus`), `requiredRole`, `characters` (personnages du compte dans cette corporation, principal d'abord : `characterId`, `name`, `isMain`, `state`, `roles`). Le filtre de corporation est la lecture ESI de l'affiliation, comme `MoonTaxManagerAccess` ; une affiliation illisible ajoute le personnage à l'état `unreadable` et l'état global devient `esi_error` si aucun n'a tenu le rôle.
3. `state` d'un personnage : `without_role` (rôles lus, `roles` = la liste lue, éventuellement vide), `reconnect` (token invalide ou scope manquant, `roles` = null), `unreadable` (erreur ESI à la lecture, `roles` = null), `unknown` (rien d'autre n'explique : l'ESI a refusé la lecture de ses rôles, `roles` = null). Jamais `[]` pour un rôle non lu. `held` renvoie `characters` vide.
4. `CorporationRoleReader::forget(corporationId, roles)` vide le personnage retenu pour ce couple ; `canRead` devient public.
5. Ressource `CorporationRoleAccess` : `GET /mining/corporation-role-access/{corporationId}?feature=` lit l'état ; `POST …/recheck?feature=` (`EmptyInput`) appelle `forget` puis relit et renvoie l'état. `feature` absent ou inconnu → 400. Le 403 de `assertManages` reste pour tous les autres endpoints de taxe lunaire (garde).

## Règles front

6. `useCorporationRoleAccess(feature)` charge l'état de plusieurs corporations (`loadAll`) et relance une lecture (`recheck`) ; une corporation dont l'état ne se charge pas n'est pas considérée comme bloquée.
7. `CorporationAccessRequired.vue` (sans `role="alert"`, `data-access-required`, `data-access-state`) :
   - `role_missing` : phrase « L'ESI exige le rôle X (ou Director) pour lire cette donnée. Aucun de tes personnages de cette corporation ne l'a. », portraits compacts de chaque personnage marqué sans le rôle, liste complète des rôles dans un `<details>` replié, marche à suivre (demander le rôle à un directeur, de préférence pour le personnage principal ; jusqu'à environ 1 h côté ESI), bouton « Revérifier » (`data-recheck`) ;
   - `reconnect` : lien (`data-reconnect-link`) vers la page Personnages, personnages à reconnecter en portraits ;
   - `esi_error` : message de lecture ESI impossible et « Revérifier ».
8. Taxe lunaire : l'état de chaque corporation est chargé après la liste. Sans aucune corporation gérable, la vue ouverte est « Ma taxe » et Manager n'affiche qu'un état vide `data-moon-tax-none-manageable` (« Aucune de tes corporations ne t'est gérable ») avec une ligne par corporation et sa raison, la carte complète dépliable. Avec au moins une corporation gérable, Manager reste par défaut et les corporations bloquées sont regroupées dans une ligne repliée en bas (`data-moon-tax-blocked`, « N corporation(s) sans le rôle Accountant »). `MoonTaxManagerPanel` ne s'affiche que pour une corporation gérable ; son bandeau `data-moon-tax-denied` n'affiche plus le message brut du backend.
9. Chunks : une corporation `denied` ou `reconnect` affiche la carte `CorporationAccessRequired` (`extractions`) à la place de la phrase ; sans extraction, sa section est repliée (`<details data-blocked-corporation>`) sous les corporations accessibles. `never_synced` et `error` gardent la phrase actuelle. Un « Revérifier » qui rend `held` relance la synchronisation des extractions.

## Exemples

| # | Situation | Résultat |
|---|---|---|
| E1 | Un personnage avec `Auditor`, aucun `Accountant`, `moon-tax` | `role_missing`, `requiredRole` `Accountant`, personnage `without_role`, `roles` = [`Auditor`] |
| E2 | Un personnage au token invalide, un sans rôle lu | `reconnect` si aucun rôle lu, sinon `role_missing` ; le token invalide est `reconnect`, `roles` null |
| E3 | Roles endpoint en 500 | `esi_error`, personnage `unreadable` |
| E4 | `held` | `characters` vide |
| E5 | Recheck après retrait du rôle du personnage retenu | mémoire vidée, `role_missing` |
| E6 | `feature=foo` | 400 |
| E7 | Aucune corporation gérable sur 2 | vue « Ma taxe », état vide à 2 lignes |
| E8 | 1 gérable sur 2 | Manager par défaut, 1 panneau, ligne repliée « 1 corporation sans le rôle Accountant » |

## Hors périmètre

Retrait du 403 ou de ses messages sur les endpoints de taxe lunaire ; `Junior_Accountant` ; suppression d'un réglage ou d'un écran existant.

## Décisions ouvertes

Le message brut du 403 reste dans l'API (garde) : à retirer seulement sur demande. `MoonTaxManagerAccess` et l'inspecteur dupliquent le filtre d'affiliation (quelques lignes) pour ne pas changer le constructeur couvert par ses tests.
