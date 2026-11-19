# #53 Suppression de compte (RGPD) : brouillon de spec

Statut : à valider par Sylvain (4 questions en fin de §2). Lu dans `main` au 2026-10-22.

## 1. Inventaire (vérifié dans le code)

Constat de départ : aucun `remove($user)` dans `src/`, aucune opération sur `/me` hors `GET` (`src/ApiResource/Me/UserResource.php:17`), le main ne peut pas être retiré (`src/State/Processor/Me/DeleteCharacterProcessor.php:49`). La page légale promet l'effacement immédiat (`frontend/src/views/Legal.vue:139,160`).

### Racine
| Table | Lien | Effet d'un `remove($user)` aujourd'hui |
|---|---|---|
| `users` | `main_character_id` → characters `SET NULL` (`User.php:30-31`, migr. `Version20260125133709:38`) | cycle users ↔ characters, rompu par la FK nullable (Doctrine 3.6.2) : à prouver par test |
| `characters` | `user_id` **sans `ON DELETE`** (`Character.php:42-43`, migr. `Version20260125133709:36`) | ORM `cascade: remove` + `orphanRemoval` (`User.php:27`) les supprime ; un `DELETE FROM users` en SQL brut échouerait (FK) |
| `eve_tokens` | character `CASCADE` (`EveToken.php:22-23`) + ORM cascade (`Character.php:46`) | supprimé ; **refresh token jamais révoqué chez EVE SSO** (aucun appel `revoke` dans `src/`) |

### Données personnelles (suppression : la cascade suffit)
Via `characters` en `CASCADE` : `cached_assets` perso (`CachedAsset.php:62-63`), `cached_character_skills` (`:58-59`), `cached_industry_jobs` (`:29-30`), `cached_wallet_transactions` (`:26-27`, puis `industry_step_purchases.transaction` `SET NULL`, `IndustryStepPurchase.php:28-29`), `planetary_colonies` (`:26-27`) → pins/routes `CASCADE`.
Via `users` en `CASCADE` : `industry_projects` (`:27-28`) → steps → job matches/purchases ; `industry_structure_configs` (`:55-56`), `industry_user_settings`, `industry_bpc_prices`, `industry_scanner_favorites`, `industry_stockpile_targets`, `industry_stockpile_imports`, `market_favorites`, `market_price_alerts`, `mining_entries`, `pve_income`, `pve_expenses`, `user_pve_settings`, `user_ledger_settings`, `notifications`, `push_subscriptions` (`PushSubscription.php:23-24`), `user_notification_preferences`, `escalations` (`Escalation.php:29-30`, y compris celles partagées corpo/alliance/public via `visibility`, `:54-55` : éphémères, la suppression est acceptable).
Structures corpo partagées : chaque membre garde **sa propre copie** (glossaire, « Structure corpo partagée ») → la cascade ne touche pas les autres. Reste un orphelin : l'UUID du user dans `industry_structure_configs.hidden_by_users` (JSON) des lignes des autres (`IndustryStructureConfig.php:103`).

### Données partagées (la cascade actuelle détruit le travail des autres)
| Table | FK vers user | Effet aujourd'hui | Problème |
|---|---|---|---|
| `group_industry_projects.owner_id` | `CASCADE` (`GroupIndustryProject.php:27-28`) | projet + items, BOM, membres, contributions, ventes supprimés (`:71-87`) | tous les membres perdent le projet |
| `group_industry_project_members.user_id` | `CASCADE` (`GroupIndustryProjectMember.php:31-32`) | ligne membre supprimée → `group_industry_contributions.member_id` `CASCADE` (`GroupIndustryContribution.php:31-32`) | payouts des autres faussés ; contredit « Membre retiré » (glossaire : ligne conservée avec contributions et payout) |
| `group_industry_sales.recorded_by_id` | `CASCADE` (`GroupIndustrySale.php:48-49`) | vente supprimée | revenu du projet faussé (constat de l'issue) |
| `group_industry_contributions.reviewed_by_id` | `SET NULL` (`:60-61`) | déjà anonymisé | OK |
| `corp_asset_visibility.configured_by_id` | `CASCADE` (`CorpAssetVisibility.php:30-31`) | config de divisions visibles supprimée | les membres de la corpo perdent le partage ; la sync a déjà un repli (`AssetsSyncService.php:255-269`) |
| `shared_shopping_lists.created_by_id` | `SET NULL` (`SharedShoppingList.php:34-35`) | la liste publique survit sans auteur | le JSON `data` reste exposé par lien public jusqu'à expiration (1 semaine) |

### Hors base
- JWT : TTL 1 semaine (`lexik_jwt_authentication.yaml:6`), pas de refresh token applicatif ; provider entité par `id` (`security.yaml:6-10`) → un JWT d'un user supprimé ne charge plus d'utilisateur (401). Blocklist Redis existante (`EveOAuthController.php:158-171`, `JwtBlacklistListener`).
- Mercure : token abonné 1 h (`TokenProvider.php:79`), topics `/user/{id}/…` ; un EventSource ouvert reste branché jusqu'à expiration.
- Messenger : messages portant `characterId`/`userId` (`src/Message/*`) ; tous les handlers sortent proprement si l'entité n'existe plus (ex. `SyncCharacterAssetsHandler.php:31-35`, `SyncUserPveDataHandler.php:25-27`, `SyncAnsiblexGatesHandler.php:29-34`). Rien à purger dans RabbitMQ.
- Redis `esi.cache` : réponses ESI personnelles (wallet, assets…) sous `esi_<md5(endpoint)>_<characterUuid>` (`EsiClient.php:662-670`), copie de revalidation conservée **7 jours** (`:23`, `:624-627`). Non purgeable par tag aujourd'hui.
- `SyncTracker` (clé « triggered by » admin, TTL court) : non bloquant.
- Logs applicatifs (noms de personnages) : durée de rétention [À CONFIRMER].

## 2. Règles proposées

1. **Supprimé** : user, characters, tokens, toutes les tables personnelles ci-dessus (cascade DB), push subscriptions, listes partagées créées par le user (passer la FK en `CASCADE`), l'UUID retiré de `hidden_by_users`.
2. **Anonymisé** (FK `nullable` + `SET NULL`, l'affichage montre « Pilote supprimé », terme à ajouter au glossaire : *Compte supprimé / Deleted account*) :
   - membre de projet collaboratif : `user_id` NULL, statut `Removed` (comme un Membre retiré) ; contributions `approved` conservées ; `pending` supprimées ;
   - vente : `recorded_by_id` NULL (remplace le `?? 'Unknown'` de `RecordSaleProcessor.php:132` par un état explicite « auteur supprimé ») ;
   - `corp_asset_visibility.configured_by_id` NULL : le choix des divisions appartient à la corpo, la sync retombe sur un autre personnage ayant le scope.
3. **FK `characters.user_id`** : ajouter `ON DELETE CASCADE` pour que la base soit cohérente même hors ORM.
4. **Tokens ESI** : révocation best-effort chez EVE SSO avant suppression (selon Q4) ; un échec est journalisé et ne bloque pas l'effacement.
5. **JWT** : blocklister le token de la requête (même code que `logout`) ; les autres JWT tombent en 401 au chargement du user.
6. **Mercure / push** : le frontend ferme l'EventSource et désabonne le Service Worker avant l'appel ; côté serveur, rien (topics morts).
7. **Messenger** : rien, les handlers ignorent déjà les entités disparues.
8. **Non personnel, conservé** : SDE, marché, `ansiblex_jump_gates`, `cached_structure`, `structure_market_snapshots`, `market_price_history`.
9. **Transaction unique** : toute la suppression dans une transaction Doctrine ; la révocation SSO et la purge Redis après commit.

### Questions pour Sylvain
- **Q1 Propriétaire d'un projet collaboratif ayant d'autres membres acceptés.** A (recommandé) : transfert de propriété au plus ancien Admin accepté, sinon au plus ancien membre accepté ; suppression du projet seulement s'il n'y a personne. B : `409` tant que le user n'a pas supprimé ou transféré ses projets lui-même (pas de transfert existant aujourd'hui).
- **Q2 Payout d'un membre anonymisé.** A (recommandé) : la ligne reste dans la distribution sous « Pilote supprimé » (cohérent avec Membre retiré, totaux inchangés). B : ses contributions approuvées restent dans les coûts mais sa part de payout est exclue.
- **Q3 Cache ESI Redis (jusqu'à 7 jours).** A (recommandé) : passer `esi.cache` en pool tag-aware, taguer par personnage, `invalidateTags` à la suppression. B : laisser expirer et l'écrire dans `Legal.vue` (« copies techniques effacées sous 7 jours »).
- **Q4 Révocation EVE SSO.** A (recommandé) : appel au endpoint de révocation EVE SSO pour chaque refresh token (URL et format [À CONFIRMER] dans la doc SSO). B : suppression locale seule, `Legal.vue` renvoie l'utilisateur vers la page des applications autorisées EVE.

## 3. Forme API et frontend

- `ApiResource/Me/UserResource.php` : ajouter `new Delete(uriTemplate: '/me', provider: AccountDeleteProvider::class, processor: DeleteAccountProcessor::class)`, `security: is_granted('ROLE_USER')`. Réponse `204`. Le provider construit un `UserResource` léger depuis `Security` (ne pas réutiliser `UserProvider`, qui déchiffre et rafraîchit des tokens ESI, `UserProvider.php:88`).
- `DeleteAccountProcessor` (final) délègue à `App\Service\Account\AccountDeletionService::delete(User)` : transfert/anonymisation group industry, purge `hidden_by_users`, `remove($user)`, flush ; puis révocation SSO (Q4), invalidation cache (Q3), blocklist JWT.
- Migration Doctrine unique : `characters.user_id` CASCADE ; `group_industry_project_members.user_id`, `group_industry_sales.recorded_by_id`, `corp_asset_visibility.configured_by_id` nullable + SET NULL ; `shared_shopping_lists.created_by_id` CASCADE. Contrainte unique `(project_id, user_id)` : NULL distincts en PostgreSQL, OK.
- Call sites à rendre null-safe (`member->getUser()`, `getRecordedBy()`, `getConfiguredBy()`, 15 occurrences) : `GroupIndustryResourceMapper`, `GroupIndustryDistributionService`, `GroupSaleCollectionProvider`, `Kick/Leave/Submit/Review/RecordSale` processors, `CorpAssetVisibilityProvider`, `AssetsSyncService`.
- Frontend : zone « Danger zone » en bas de `frontend/src/views/Characters.vue` ; `ConfirmModal` (`components/common/ConfirmModal.vue`) avec saisie du nom du main pour activer le bouton, résumé de ce qui est supprimé / anonymisé. Après `204` : désabonnement push, fermeture Mercure (`stores/sync.ts`), `authStore.logout()`, redirection `/login` avec toast. Clés i18n `en.json`/`fr.json`. Maquette `/frontend-design` à produire une fois les règles validées.
- `Legal.vue` §7 et §9 : décrire suppression immédiate + anonymisation des contributions et ventes de projets collaboratifs + sort du cache (Q3) et des tokens (Q4), lien vers la zone de suppression.

## 4. Tests d'intégration (base `eve_app_test`, `IntegrationTestCase`)

`tests/Integration/Service/Account/AccountDeletionServiceTest.php` :
- `testDeletingAccountRemovesUserCharactersAndTokens` : user + 2 personnages + tokens → plus aucune ligne dans `users`, `characters`, `eve_tokens`.
- `testDeletingAccountLeavesNoRowReferencingTheUserInAnyTable` : un fixture par table de §1 ; requête `information_schema` sur toutes les colonnes FK vers `users`/`characters` → zéro ligne avec l'id supprimé (garde-fou contre une future table oubliée).
- `testDeletingAccountKeepsOtherUsersPersonalData` : un second user avec les mêmes tables reste intact.
- `testApprovedContributionOfDeletedMemberIsKeptWithoutAuthor` / `testPendingContributionOfDeletedMemberIsRemoved`.
- `testSaleRecordedByDeletedUserIsKeptWithoutAuthor` : revenu du projet inchangé.
- `testDistributionTotalsAreUnchangedAfterMemberDeletion` (selon Q2).
- `testOwnershipOfGroupProjectIsTransferredToOldestAcceptedAdmin` / `testGroupProjectWithoutOtherMemberIsDeleted` (selon Q1).
- `testCorpAssetVisibilitySurvivesItsDirectorDeletion`.
- `testHiddenByUsersNoLongerContainsDeletedUserId`.
- `testSharedShoppingListOfDeletedUserIsRemoved`.
- `testDeletionIsAtomicWhenAStepFails` : exception simulée après l'anonymisation → rien n'est supprimé.

`tests/Integration/State/Processor/Me/DeleteAccountProcessorTest.php` :
- `testRequestTokenIsBlocklistedAfterDeletion` ; `testSsoRevocationFailureDoesNotBlockDeletion` (client HTTP mocké, Q4) ; `testEsiCacheOfDeletedCharactersIsInvalidated` (Q3).
- `testSyncMessageForDeletedCharacterIsIgnored` : `SyncCharacterAssetsHandler` après suppression → aucun effet, pas d'exception.
