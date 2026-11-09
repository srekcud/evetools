# Sécurité — PRIVÉ (ne jamais publier en issue publique avec détails)

Fichier local non versionné.

**Décision de Sylvain (2026-10-06) : pas de hotfix**, l'usage de l'outil est encore confidentiel. Ces constats sont traités en priorité à l'étape 5 de la Phase 1, en TDD (`test-writer`, puis `implementer`, jamais `backend-developer`), avec des commits aux titres neutres. À corriger via des correctifs directs ou une GitHub Security Advisory privée. En issue publique, seulement un titre neutre sans scénario.

| # | Sévérité | Constat | Preuve | Vérifié par moi |
|---|---|---|---|---|
| S1 ✅ corrigé (updates privées, commit « publish group project and corporation updates privately » ; reste : restreindre `cors_origins *` du hub) | critique | Hub Mercure en `anonymous` + `cors_origins *`. Les events de projets collaboratifs sont publiés sans `private` (défaut = public) ; les escalations corpo et alliance avec `private: false`. Un anonyme qui s'abonne à `*` reçoit tout. | `frankenphp/Caddyfile:19-20`, `MercurePublisherService.php:194-197`, `:247-251`, `proxy/Caddyfile:30` | ✅ |
| S2 ✅ corrigé (`eac4ae8`) | haute | IDOR link-job : un job ESI d'un autre utilisateur peut être lié (fuite du nom du personnage, du coût, de la station ; blocage du lien légitime). | `LinkJobProcessor.php:67`, `CachedIndustryJobRepository.php:23-26` | (2 audits concordants) |
| S3 | haute | `MERCURE_JWT_SECRET` a une valeur par défaut versionnée dans `docker-compose.yaml:38-40`, `:72-73`, non surchargée dans `docker-compose.prod.yml`. Si la prod n'a pas la variable : JWT forgeable. **État prod inconnu.** | idem | ✅ (défaut présent) |
| S4 ✅ corrigé (lecture par id ; reste : règles de la liste corp différentes de la lecture) | moyenne | Une escalation corpo ou alliance est lisible par id depuis une autre corpo (seul `Perso` est bloqué). | `EscalationProvider.php:51` | |
| S5 ✅ auto-approbation corrigée (`81da225`) ; reste : `estimatedValue` libre (plafond à décider) | moyenne | Auto-approbation d'une contribution + `estimatedValue` libre → captation de la répartition. | `ReviewContributionProcessor.php:62,71`, `SubmitContributionInput.php:22-23` | |
| S6 ✅ corrigé (`8a5408d`, liste blanche + contenus de containers) | moyenne (fail-open contraire à l'intention whitelist confirmée par Sylvain sur Discord ; flags hors hangar — livraisons, fourrière, service slots, wallet — stockés et renvoyés par `CachedAssetRepository.php:75-102`) | Assets corpo entièrement visibles tant que le Director n'a rien configuré (fail-open) ; contournement via le conteneur d'un projet collaboratif. | `CorporationAssetsProvider.php:63,67`, `GroupIndustryContainerService.php:59` | |
| S7 | moyenne | RGPD : la suppression de compte promise (`Legal.vue:139,160`) n'existe pas. | grep `remove($user)` vide | |
| S8 | basse | Admin désigné par le nom du perso principal, en clair dans le repo public (`config/services.yaml:4-5`), vérification copiée dans 8 fichiers ; état OAuth non comparé côté front (`Login.vue:68-81`) ni à usage unique (`EveOAuthController.php:70`) → CSRF de login ; `$e->getMessage()` renvoyé au client (`EveOAuthController.php:132-136`) ; IDOR mineurs via UUID (`CreatePurchaseProcessor.php:66`, `UpdateStepProcessor.php:91`) ; 3 règles d'accès différentes pour les projets collaboratifs ; liste partagée non bornée et indexable. | | |

Vérifié OK : HMAC de l'état OAuth ; refresh tokens chiffrés ; aucun secret réel dans git ; propriété vérifiée sur la plupart des écritures ; rôles des projets collaboratifs côté serveur ; Director vérifié via ESI ; pas de SQL concaténé ; pas de `v-html` ; topics `/user/{id}` privés.

## 2026-10-11 — audit notifications / admin (privé, ne pas publier)
- **SP-1 (moyenne)** Endpoint d'abonnement push non restreint : `PushSubscriptionProcessor.php:42` ne vérifie que `https://`, pas de liste blanche des services de push ni de plafond d'abonnements (`PushSubscription.php:14`). Un utilisateur authentifié peut faire émettre des requêtes sortantes par le worker vers l'hôte de son choix. Correctif : liste blanche des domaines des services de push, plafond, pas de suivi de redirection. Test : endpoint hors liste → 422.
- **SP-2 (moyenne, exploitation rare)** Pas de contrôle du `CharacterOwnerHash` au login SSO : `AuthenticationService::verifyToken()` ne renvoie que `CharacterID` (`:148-160`), `findOrCreateCharacter()` rattache au compte existant sur ce seul id (`:232-243`). Après transfert de personnage, le nouveau propriétaire accède à l'ancien compte, droits admin compris si c'est le principal d'un admin. Contredit `AdminChecker.php:10-11`. Test : même `CharacterID`, owner hash différent → nouveau compte ou refus.
- **SP-3 (basse, vie privée)** Abonnement push conservé après déconnexion (`stores/auth.ts:44-48`, aucun appelant de `unsubscribe()`).
- **SP-4 (basse)** JWT d'abonné Mercure dans l'URL (`stores/sync.ts:108`, paramètre `authorization`) alors que les access logs Caddy sont actifs (`proxy/Caddyfile:7-10`, `frankenphp/Caddyfile`) : à vérifier sur un log réel. Rappel : `cors_origins *` (reste de S1) et S3 toujours ouverts.
- Vérifié sans problème : topics Mercure utilisateur exacts sans joker (`TokenProvider.php:366-373`), publications privées ; propriété d'une notification vérifiée (`NotificationProvider.php:47-49`) ; `AdminChecker` strict ; ordre des rapports minishlink/web-push correct.

## 2026-10-19 — review #117 (privé, ne pas publier)

| # | Sévérité | Constat | Preuve | Vérifié par moi |
|---|---|---|---|---|
| S9 | moyenne | Liste partagée : `items`, `notFound`, `totals`, `structureName` sont stockés tels que le client les envoie puis servis sans authentification sur `/s/{token}`. Un utilisateur peut publier une page avec de faux prix, noms ou totaux (arnaque en jeu). Correction proposée : n'accepter que `typeId` + `quantity` (bornés), recalculer côté serveur noms, volumes, prix, totaux et `missingVolumeTypeIds`. Complète « liste partagée non bornée » de S8. | `ShareListInput.php:13-26`, `ShareListProcessor.php:35-42`, `SharedShoppingList.vue:33` | lecture (security-reviewer) |

## 2026-10-22 — audit moteur industrie (privé, ne pas publier)

| # | Sévérité | Constat | Preuve |
|---|---|---|---|
| I1 | basse (déjà dans S8, toujours présent ; issue publique neutre #285) | Rattacher un achat lit une transaction wallet d'un autre utilisateur (type, quantité, prix renvoyés). UUID v7 requis, exposé seulement au propriétaire. | `CreatePurchaseProcessor.php:66,72`, `PurchaseSuggestionProvider.php:110` |
| I2 | basse (déjà dans S8, toujours présent) | Une étape accepte la structure d'un autre utilisateur, même supprimée ; nom et bonus renvoyés. | `UpdateStepProcessor.php:91`, `IndustryResourceMapper.php:239-242` |
| I3 ✅ corrigé dans main 2026-10-22 (6dbadd7 + 3ca4a86, non poussé) | moyenne — **confirmé 2026-10-22** (S6 incomplet, voir security-i3-triage-2026-10-22.md) | La vérification par container d'un projet collaboratif lit les assets corpo du propriétaire sans appliquer la liste blanche des divisions ; paramètres du container modifiables par un rôle hors corporation. | `GroupIndustryContainerService.php`, `ContainerVerificationProvider.php`, `UpdateGroupProjectProcessor.php` ; comparer `CorporationAssetsProvider.php:53` |
| I4 | basse (rejoint S8 « 3 règles d'accès ») | Projet collaboratif : un membre de la corporation sans adhésion (ou retiré) lit la distribution nominative et les ventes, pas la liste des membres. Issue publique neutre « contrôle d'accès dupliqué ». | `GroupDistributionProvider.php:61-83`, `GroupSaleCollectionProvider.php:69-91` vs `GroupMemberCollectionProvider.php:58` |
| I5 | basse | Mutant survivant : `ProductionCostService.php:186` `findOneBy(['user' => $user])` → `findOneBy([])` ne fait échouer aucun test (réglages d'un autre utilisateur). | rapport test-skeptic 2026-10-22 |
