# #953 lot 2 — Confirmation de livraison par le container, wallet à la demande

Spec écrite en mode nuit, validée en autonomie. Suite du lot 1 (`docs/specs/953-count-contributions-on-delivery.md`, PR #959 fusionnée : proposition `pending` non vérifiée pour un achat lié en projet de groupe, prix payé au dépôt, réouverture). Le lot 2 fait compter la proposition quand les biens sont vus dans le container du projet.

## Périmètre

Dans ce lot : (A) confirmation automatique d'une proposition quand le stock du container la couvre, (B) actualisation du wallet à la demande depuis la page projet, (C) confirmation manuelle libellée « confirmer la livraison », apport confirmé à la main signalé non vérifié, (D) aide #947 et glossaire.

Hors lot (lot 3, issue laissée ouverte) : achats d'un membre rattachés d'eux-mêmes (exige un participant acheteur sur `IndustryProjectPurchase`, une migration et un apport dérivé par acheteur dans `DerivedContributionService`) ; parcours guidé (`docs/design/mockups/onboarding.html` n'existe pas dans le dépôt, rien à mettre à jour).

## Règles

- **R1 — Qui est confirmé.** Un apport est confirmable s'il est `pending`, `isAutoDetected`, de type `material`, sur une ligne qui n'est pas une ligne de job, `verifiedAt` vide, dans un projet ni terminé ni en vente. Les apports déposés à la main ne sont jamais confirmés automatiquement (leur auteur les déclare, un relecteur les valide).
- **R2 — Règle de couverture.** Stock = quantité du type dans le container (`ProjectContainerService::containerStockOf`, quantités par type, contenu des sous-containers exclu comme aujourd'hui). Par ligne, les apports confirmables sont pris du plus ancien (`createdAt`) au plus récent avec un cumul démarrant à la `fulfilledQuantity` de la ligne : un apport de quantité q est confirmé si stock ≥ cumul + q, puis cumul += q. Le premier qui ne passe pas arrête la ligne (ordre strict, pas de saut).
- **R3 — Stock inconnu.** `containerStockOf` renvoie une raison d'indisponibilité (aucun nom de container, pas de directeur, assets corpo non synchronisés, division masquée, container introuvable) : aucune confirmation, les apports restent `pending`. Jamais de repli sur zéro ni sur « livré ».
- **R4 — Effet d'une confirmation automatique.** Même effet que l'approbation du lot 1 (statut `approved`, `reviewedAt` maintenant, `fulfilledQuantity` de la ligne + quantité, règlement des réservations de ligne comme `ProjectContributionService::approve`), avec `reviewedBy` vide (aucun humain), `isVerified` vrai et `verifiedAt` maintenant (même état qu'un apport de relecteur unique). Une confirmation manuelle (revue existante) laisse `isVerified` faux.
- **R5 — Déclencheurs.** (1) À la fin de `AutomaticPurchaseLinker::linkNewPurchases`, pour les projets dont une ligne vient de recevoir un achat. (2) Après la synchro réussie des assets d'une corporation (`AssetsSyncService::syncCorporationAssets`, tous appelants), pour les projets ouverts dont le propriétaire a pour corporation (corporation du personnage principal) celle synchronisée. Pas de déclencheur sur une lecture GET.
- **R6 — Mercure.** Un événement de groupe `contribution_synced` par apport confirmé (`contributionId`, `bomItemId`), comme `DerivedContributionService::refreshLine`, un seul flush par projet.
- **R7 — Service.** Nouveau `App\Service\Industry\Project\ContainerDeliveryConfirmer` (méthodes `confirmProject(IndustryProject)` et `confirmOpenProjectsOfCorporation(int)`), qui renvoie le nombre d'apports confirmés ; `ProjectContributionService` porte l'effet (approbation sans relecteur humain), `IndustryProjectRepository` la requête de R5 (2).
- **R8 — Actualisation du wallet.** `POST /industry/projects/{projectId}/wallet-refresh` (API Platform, `input: EmptyInput::class`, `uriVariables` avec `Link`, membre accepté du projet, sinon 403). Pour chaque personnage de l'utilisateur appelant, ayant le scope wallet et un token valide, dont la lecture ESI est échue (`CharacterWalletReading::getNextReadAt() <= maintenant`, ou jamais lue), dispatche `SyncCharacterWalletTransactions`. Jamais un personnage d'un autre utilisateur. Réponse 200 : `{queuedCharacters: int, notDueCharacters: int}` (personnages dont le cache ESI n'est pas échu : aucun appel, la page affiche leur prochaine lecture). Aucun personnage éligible : 200 avec `queuedCharacters` 0, jamais d'erreur ni de valeur par défaut. Projet terminé : 400.
- **R9 — Mercure du wallet.** Aucun nouveau topic : la synchro d'un personnage publie déjà `wallet-transactions` (`syncStarted` / `syncCompleted`) et `refreshLine` publie `contribution_synced`. Le front recharge BOM et apports sur ces événements (mécanisme existant du store de projet), jamais de polling.
- **R10 — Front.** (a) Un bouton « Actualiser le wallet » (HUD EVE, `data-testid="wallet-refresh"`) près des puces `WalletReadingChips` de la page projet, visible d'un participant accepté, désactivé pendant l'appel et tant que `wallet-transactions` est en cours ; réponse avec `queuedCharacters` 0 : message « aucune lecture à lancer, prochaine lecture à HH:MM » à partir des lectures déjà chargées. (b) Dans `ContributionTable`, le bouton d'approbation d'un apport `pending`, `isAutoDetected` et matériau se lit « Confirm delivery » ; un apport `approved` dérivé d'un achat et non vérifié affiche « Delivery confirmed by hand » (libellés littéraux anglais, comme les autres boutons de ce tableau ; le bouton d'actualisation suit les clés i18n `groupIndustry.walletReading.*`). (c) Pas de nouvel écran ni de nouvel onglet.
- **R11 — Aide.** `calculations/group-contribution-and-distribution.md` (FR et EN) : un achat lié d'un projet de groupe est d'abord une proposition ; elle compte quand le container la couvre (règle R2, ordre du plus ancien), ou quand un administrateur confirme la livraison ; le bouton d'actualisation du wallet et le cache ESI ; le cas container illisible. Glossaire : **Proposition d'apport** (compte après confirmation, au container ou à la main), **Confirmation de livraison**.

## Exemples (vérifiables par test)

| Cas | Entrée | Résultat |
|---|---|---|
| Stock couvre | ligne `fulfilledQuantity` 0, proposition 100, container 100 | `approved`, vérifié, `reviewedBy` vide, ligne 100 |
| Stock insuffisant | proposition 100, container 60 | reste `pending`, ligne 0 |
| Cumul | propositions 60 (plus ancienne) et 50, container 100 | la plus ancienne confirmée (60), l'autre reste (cumul 60 + 50 > 100) |
| Ordre strict | plus ancienne 80, suivante 10, container 50 | aucune (80 ne passe pas, la suivante n'est pas essayée) |
| Ligne déjà remplie en partie | `fulfilledQuantity` 40, proposition 30, container 70 | confirmée (40 + 30 ≤ 70) |
| Stock inconnu | assets corpo non synchronisés | rien confirmé, aucune erreur |
| Dépôt manuel pending | apport déclaré 100, container 100 | reste `pending` |
| Ligne de job, projet en vente ou terminé | proposition présente | rien |
| Déjà vérifié | apport `pending` avec `verifiedAt` renseigné | rien |
| Événements | 2 apports confirmés | 2 `contribution_synced`, 1 flush |
| Wallet : un personnage échu, un non échu | 2 personnages avec scope | 1 message dispatché, `{1, 1}` |
| Wallet : personnage d'un autre membre | membre B appelle | seuls les personnages de B |
| Wallet : non participant | utilisateur étranger | 403 |
| Wallet : projet terminé | — | 400 |

## Cas limites

- Un apport confirmé par le container puis réouvert (R8 du lot 1) a `verifiedAt` renseigné : il n'est pas reconfirmé. Réouvert après une confirmation manuelle (`verifiedAt` vide) : il peut être reconfirmé au prochain déclencheur si le container le couvre ; accepté, rejeter reste possible.
- Les biens consommés par un job démarré ont quitté le container : la proposition reste en attente et se confirme à la main.
- Le propriétaire ne peut pas confirmer à la main son propre apport (lot 1) ; la confirmation par le container n'a pas cette limite, aucun humain n'intervient.
- Un seul déclencheur en échec (ESI, assets) ne bloque pas l'autre ; une exception du confirmeur après la synchro des assets est journalisée (`LoggerInterface`) et ne fait pas échouer la synchro.

## Accès

R8 : `ProjectAccessChecker::assertAcceptedMember`, personnages de l'utilisateur seulement. Aucune donnée de wallet n'est renvoyée, seulement deux compteurs.

## Migration, prod

Aucune migration, aucune commande console, aucun nouveau scope ESI. Le premier passage de synchro des assets après le déploiement confirme les propositions déjà couvertes (effet attendu).
