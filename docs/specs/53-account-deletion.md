# Spec — Suppression de compte (RGPD) (#53)

- **Statut** : validée en autonomie (mode nuit) le 2026-10-22, sur la base de la note de design `53-design.md` et des réponses de Sylvain aux questions Q1 à Q4 (2026-10-22).
- **État du code de référence** : commit `e937f9c`

## 1. Contexte

La page légale promet l'effacement des données, mais aucune opération ne supprime un utilisateur : le personnage principal ne peut pas être retiré, il n'existe aucun `remove($user)`, et `/api/me` n'expose que `GET`. Plusieurs relations en `ON DELETE CASCADE` détruiraient par ailleurs le travail des autres membres d'un projet collaboratif (projet, contributions, ventes) si l'on supprimait l'utilisateur tel quel.

## 2. Règles métier

### R1 — Opération
`DELETE /api/me` (API Platform, provider qui renvoie la ressource de l'utilisateur courant, sans déchiffrer ni rafraîchir de token ESI) supprime le compte de l'utilisateur authentifié. Réponse `204`. Sans authentification : `401`.

### R2 — Supprimé
L'utilisateur, tous ses personnages, leurs tokens ESI et toutes les données personnelles qui en dépendent (assets, compétences, jobs, transactions, colonies PI, projets industriels perso, réglages, favoris, alertes, minage, PVE, notifications, push subscriptions, escalations) disparaissent. La FK `characters.user_id` passe en `ON DELETE CASCADE`. Les **listes d'achats partagées** créées par l'utilisateur sont supprimées (FK `shared_shopping_lists.created_by_id` en `CASCADE`). L'id de l'utilisateur est retiré de `industry_structure_configs.hidden_by_users` de toutes les lignes.

### R3 — Projet collaboratif dont l'utilisateur est propriétaire (Q1)
- La propriété est transférée au plus ancien (`joinedAt`) membre `accepted` de rôle `admin` ; à défaut au plus ancien membre `accepted`. Le nouveau propriétaire prend le rôle `owner` et devient `project.owner`.
- Si le projet n'a aucun autre membre `accepted`, il est supprimé (avec ses items, BOM, membres, contributions et ventes).

### R4 — Membership de l'utilisateur dans un projet qu'il ne possède pas, ou dont il a transféré la propriété (Q2)
- Une demande `pending` (membre non encore accepté) est supprimée.
- Une ligne `accepted` ou `removed` est conservée et anonymisée : `user` à `NULL`, statut `removed`, rôle `member` (comme un **Membre retiré**).
- Ses contributions `approved` sont conservées sans leur note (texte libre, mise à `null`) ; ses contributions `pending` et `rejected` sont supprimées.
- Le **payout** des autres membres et les totaux de la distribution sont inchangés ; la ligne anonymisée reste dans la distribution sous **Compte supprimé** (« Pilote supprimé »).

### R5 — Données partagées anonymisées
- Vente : `group_industry_sales.recorded_by_id` nullable + `SET NULL`. La vente et le revenu du projet sont conservés.
- `corp_asset_visibility.configured_by_id` nullable + `SET NULL` : le choix des divisions visibles appartient à la corporation et reste en place ; la sync des assets corpo retombe sur un autre personnage ayant le scope.
- Dans les ressources API, le nom (et l'id de personnage, la corporation) d'un compte supprimé vaut `null`, jamais `'Unknown'` ni `0`. Le frontend affiche « Pilote supprimé » / « Deleted pilot ».

### R6 — Hors base, après commit
- **Cache ESI (Q3)** : le pool `esi.cache` devient tag-aware ; chaque réponse authentifiée est taguée `esi_character_<uuid du personnage>` ; à la suppression, `invalidateTags` sur les tags de tous les personnages du compte.
- **EVE SSO (Q4)** : pour chaque refresh token, `POST https://login.eveonline.com/v2/oauth/revoke` (`revocation_endpoint` des métadonnées `/.well-known/oauth-authorization-server`), auth `client_secret_basic`, corps `token_type_hint=refresh_token&token=<refresh token déchiffré>`. Best-effort : un échec (statut ≠ 2xx, erreur réseau, token indéchiffrable) est journalisé en `warning` sans le token ni aucune donnée personnelle hormis l'id de personnage EVE, et n'empêche ni la suppression ni les autres révocations.
- **JWT** : le token de la requête est ajouté à la blocklist (même mécanisme que `POST /api/auth/logout`, logique partagée) ; les autres JWT du compte tombent en `401` faute d'utilisateur. Un échec de la blocklist après suppression est journalisé et ne fait pas échouer la requête (le JWT tombe en `401` de toute façon).

### R7 — Atomicité
Toute la partie base (transferts, anonymisations, suppressions) se fait dans une seule transaction : si une étape échoue, rien n'est modifié. La révocation SSO, l'invalidation du cache et la blocklist JWT ont lieu après le commit.

### R8 — Frontend
- Zone « Danger zone » en bas de la page Personnages : bouton « Supprimer mon compte » ; confirmation par `ConfirmModal` avec saisie du nom du personnage principal pour activer le bouton, et résumé (supprimé / anonymisé).
- Après `204` : désabonnement push, fermeture des EventSource Mercure, déconnexion locale (sans appel `logout` serveur), redirection `/login` avec un toast. Erreur : toast d'erreur, l'utilisateur reste connecté.
- Les vues collaboratives affichent « Pilote supprimé » quand le nom vaut `null`.
- `Legal.vue` (§7 et §9) décrit la suppression immédiate, l'anonymisation des contributions et ventes de projets collaboratifs, la purge du cache ESI et la révocation des tokens EVE SSO, et pointe vers la page Personnages.
- Toutes les chaînes en i18n FR et EN.

### R9 — Inchangé
Messenger : les handlers ignorent déjà un personnage ou un utilisateur disparu. Données non personnelles conservées (SDE, marché, Ansiblex, structures, historiques de prix).

## 3. Exemples (futurs tests)

Utilisateurs : **A** (supprimé), **B**, **C**, **D**. Projet collaboratif P.

| # | Situation | Après `delete(A)` |
|---|---|---|
| E1 | A a 2 personnages avec tokens | 0 ligne `users`/`characters`/`eve_tokens` pour A |
| E2 | Une fixture par table personnelle de §2 R2 pour A et pour B | toutes les colonnes FK vers `users` / `characters` (lues dans `information_schema`) ne référencent plus A ; les lignes de B intactes |
| E3 | P possédé par A ; B admin accepté rejoint le 2026-01-03 ; C admin accepté rejoint le 2026-01-02 ; D membre accepté rejoint le 2026-01-01 | owner = C, membre C rôle `owner` ; B et D inchangés |
| E4 | P possédé par A ; B membre accepté rejoint le 2026-01-02 ; C membre accepté le 2026-01-01 ; D `pending` le 2025-12-31 | owner = C |
| E5 | P possédé par A ; seul autre membre D `pending` | P supprimé (items, membres, contributions, ventes) |
| E6 | A membre accepté de P (possédé par B) ; contributions de A : 1 `approved` 1 000 000 ISK, 1 `pending`, 1 `rejected` | ligne membre conservée : user `NULL`, statut `removed`, rôle `member` ; seule la contribution `approved` reste |
| E7 | E6 + B a apporté 3 000 000 ISK approuvés, marge 10 % | distribution identique avant/après : payout A 1 100 000, B 3 300 000 ; nom de A `null` |
| E8 | A a une demande `pending` sur P (possédé par B) | la ligne membre de A est supprimée |
| E9 | A a enregistré une vente de 5 000 000 ISK sur P (possédé par B) | vente conservée, `recordedBy` `NULL`, revenu de P = 5 000 000 ; ressource : `recordedByCharacterName` `null` |
| E10 | A a configuré la visibilité des assets de sa corpo (divisions 1 et 3) | la ligne reste avec divisions [1, 3], `configuredBy` `NULL` ; `configuredByName` `null` ; `getCorpAssetsCharacter()` renvoie un personnage de B dans la corpo avec le scope |
| E11 | Une structure partagée de B a `hiddenByUsers` = [A, C] | [C] |
| E12 | A a créé une liste d'achats partagée, B aussi | celle de A supprimée, celle de B intacte |
| E13 | Exception simulée après l'anonymisation | aucune ligne modifiée (A, ses personnages, P et sa membership intacts) |
| E14 | `DELETE /api/me` | `204` ; le JWT de la requête est dans la blocklist |
| E15 | Le endpoint de révocation répond 500 pour le 1er token, 200 pour le 2e | compte supprimé, 2 appels de révocation, 1 warning journalisé |
| E16 | Réponse ESI authentifiée de A en cache (fraîche et copie de revalidation), réponse de B en cache | après suppression, celles de A absentes, celle de B présente |
| E17 | `SyncCharacterAssetsHandler` reçoit un message pour un personnage de A après suppression | aucun effet, aucune exception |
| E18 | Requête de révocation | `POST` sur l'URL ci-dessus, Basic auth `client_id:client_secret`, corps `token_type_hint=refresh_token&token=<token déchiffré>` |

## 4. Cas limites
- A n'a qu'un personnage, ou n'a pas de token : la suppression réussit sans appel de révocation.
- A est propriétaire et membre `removed` d'autres projets : chaque cas suit R3 ou R4 indépendamment.
- Contrainte unique `(project_id, user_id)` : plusieurs `NULL` sont distincts en PostgreSQL, deux comptes supprimés dans un même projet coexistent.
- Un EventSource Mercure déjà ouvert d'un autre appareil reste branché jusqu'à expiration de son token (1 h) : accepté, les topics de l'utilisateur ne reçoivent plus rien.
- Les réponses ESI mises en cache avant le déploiement ne sont pas taguées : elles expirent d'elles-mêmes (7 jours au plus).

## 5. Hors périmètre
- Export des données (portabilité).
- Rétention des logs applicatifs.
- Suppression d'un compte par un admin.
- Révocation des tokens Mercure déjà émis.

## 6. Décisions prises en autonomie (mode nuit)
- D1 : seuls les membres `accepted` sont candidats au transfert (Q1) ; un projet dont il ne reste que des demandes `pending` ou des membres `removed` est supprimé.
- D2 : les contributions `rejected` du compte supprimé sont supprimées comme les `pending` (aucun effet sur le payout).
- D3 : les règles de la note de design non couvertes par Q1-Q4 (listes partagées supprimées, visibilité corpo anonymisée, `hidden_by_users`, FK `characters.user_id` en `CASCADE`) sont reprises telles quelles.
- D4 : un nom de compte supprimé est `null` dans l'API, le libellé « Pilote supprimé » est porté par le frontend (i18n).

## 7. Glossaire
Ajout : **Compte supprimé** / Deleted account.
