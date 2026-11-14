# #953 — Compter les apports de matériaux à la livraison, au prix payé pour tous (lot 1)

Spec écrite en mode nuit, validée en autonomie. Elle remplace R1 et R3 de #363 pour les projets de groupe et l'attachement automatique de #750 (l'achat lié reste automatique, l'apport qu'il produit devient une proposition).

## Périmètre du lot 1

Dans ce lot : (A) l'apport dérivé d'un achat lié devient une **proposition** en projet de groupe, (B) l'apport matériau déposé à la main est valorisé au **prix payé** (wallet) avec repli sur le prix Jita du jour d'apport, (C) un apport matériau approuvé est **réouvrable** jusqu'à la phase de vente.

Hors lot (lot 2, issue laissée ouverte) : confirmation automatique par la vérification du container (`ProjectContainerService::verifyContainer`), actualisation du wallet à la demande depuis la page projet, achats de membres rattachés d'eux-mêmes, confirmation manuelle hors container avec indication « non vérifié » dans la file d'actions, aide #947, parcours guidé, maquette.

## Règles

- **R1 — Proposition en projet de groupe.** Quand le propriétaire n'est pas le seul relecteur possible (`IndustryProject::isSoleReviewingOwner` faux) et que la ligne n'est pas une ligne de job, `DerivedContributionService::refreshLine` crée l'apport matériau dérivé en `pending`, `isAutoDetected` vrai, `isVerified` faux, `reviewedBy` et `reviewedAt` vides. Sa valeur reste la somme des `totalPrice` des achats liés (prix payé). La `fulfilledQuantity` de la ligne ne bouge pas tant qu'il n'est pas approuvé.
- **R2 — Projet solo et relecteur unique inchangés.** Si le propriétaire est le seul relecteur possible (donc tout projet solo, #726), l'apport dérivé reste approuvé et vérifié d'office, la `fulfilledQuantity` bouge à la création (#363 R3). Les apports dérivés de job (R4 de #363), le courrier et le composant acheté d'une ligne de job (apport d'intermédiaire, qui passe par la BOM, #706) ne changent pas.
- **R3 — Confirmation manuelle.** Approuver un apport dérivé en attente passe par la revue existante (`PATCH /industry/projects/{projectId}/contributions/{id}`, `approved`) : un autre propriétaire ou admin que l'auteur, la `fulfilledQuantity` monte de la quantité, `isVerified` reste faux (livraison non vue dans un container). Le rejeter laisse la ligne courte.
- **R4 — Resynchronisation.** Quand les achats liés changent, l'apport dérivé existant prend la nouvelle quantité et la nouvelle valeur. La `fulfilledQuantity` suit la différence seulement si l'apport est `approved` ; un apport `pending` ou `rejected` garde son statut et ne touche pas la ligne. Plus aucun achat lié : l'apport dérivé est supprimé, la `fulfilledQuantity` baisse de sa quantité seulement s'il était approuvé.
- **R5 — Prix payé pour tout dépôt matériau.** `ProjectContributionService::submit` d'un matériau (ligne de matériau ou de composant à acheter, jamais une ligne de job) lit dans le wallet les achats (`CachedWalletTransaction::isBuy`) du type de la ligne faits depuis la création du projet par les personnages de l'utilisateur du membre, hors transactions déjà liées à un achat de ce projet. Prix payé moyen pondéré = somme des prix ÷ somme des quantités achetées. Unités au prix payé = min(quantité déposée, quantité achetée) ; les autres unités sont au prix Jita du jour d'apport (percentile 5 %, `JitaMarketService::getCheapestPercentilePrices`). Valeur = prix payé moyen × unités payées + prix Jita × unités restantes, figée au dépôt.
- **R6 — Repli et refus.** Aucun achat trouvé : toute la quantité au prix Jita (comportement actuel). Prix Jita absent ou ≤ 0 alors qu'il faut le lire : 400, aucun repli, jamais 0. Si les achats couvrent toute la quantité, le prix Jita n'est pas lu. Une valeur saisie reste refusée (R2 de #363).
- **R7 — Marqueur de repli.** Nouvelle colonne `valued_at_jita_price` (booléen, faux par défaut) sur `industry_project_contributions` : vrai quand le prix Jita du jour d'apport est entré dans la valeur (aucun achat, ou unités restantes). Exposée par `ProjectContributionResource::$valuedAtJitaPrice`. `estimatedValueSource` (percentile Jita) n'est renseigné que si ce booléen est vrai ; un apport dérivé de matériau, jamais.
- **R8 — Réouverture.** `PATCH` avec `status: pending` sur un apport `approved` : propriétaire ou admin, jamais sur son propre apport sauf relecteur unique (même règle que la revue), projet ni en vente (`selling`) ni terminé (400 « the project reached the selling phase »). Réservé aux apports matériau d'une ligne qui n'est pas une ligne de job (400 sinon) ; `IndustryProjectContribution::isReopenable()` porte la règle et la ressource l'expose (`reopenable`). Effet : statut `pending`, `reviewedBy` et `reviewedAt` vides, `fulfilledQuantity` de la ligne baisse de la quantité (plancher 0), jamais le statut d'un apport `pending` ou `rejected` (400). Événement Mercure `contribution_synced` (rechargement BOM, apports, répartition).
- **R9 — Front.** Le tableau des apports montre un bouton « Reopen » sur un apport `reopenable` (administrateur) ; l'étiquette de méthode d'un matériau déposé est « prix payé (wallet) » sauf si `valuedAtJitaPrice` (alors « prix Jita du jour d'apport ») ; l'étiquette « Unverified » s'affiche aussi sur un apport dérivé non vérifié ; la note de la modale de dépôt dit « prix payé lu dans le wallet, sinon prix Jita du jour d'apport ».
- **R10 — Migration.** Colonne `valued_at_jita_price` ajoutée à faux ; les apports matériau déposés à la main existants (`is_auto_detected` faux, `type` = `material`) passent à vrai (ils étaient valorisés au prix Jita). `down()` retire la colonne.

## Exemples (vérifiables par test)

| Cas | Entrée | Résultat |
|---|---|---|
| Groupe, achat lié | admin Y accepté, propriétaire X achète 100 Tritanium pour 500 ISK | apport dérivé de X : 100, 500 ISK, `pending`, non vérifié ; `fulfilledQuantity` 0 |
| Confirmation | Y approuve | `approved`, `fulfilledQuantity` 100, `isVerified` faux |
| Solo | X seul relecteur, même achat | `approved`, vérifié, `fulfilledQuantity` 100 |
| Achat réduit après approbation | 100 → 60 achetés, apport approuvé | quantité 60, `fulfilledQuantity` −40 |
| Achat réduit en attente | idem, apport `pending` | quantité 60, `fulfilledQuantity` inchangée |
| Dépôt membre payé | M a acheté 100 à 5 ISK et dépose 100 | valeur 500, `valuedAtJitaPrice` faux, aucune lecture Jita |
| Dépôt mixte | M a acheté 60 à 5 ISK, dépose 100, Jita 7 | 60 × 5 + 40 × 7 = 580, `valuedAtJitaPrice` vrai |
| Pondéré | 50 à 4 et 50 à 6, dépose 100 | 500, prix payé moyen 5 |
| Sans achat | rien dans le wallet, Jita 7, dépose 10 | 70, `valuedAtJitaPrice` vrai |
| Mixte sans Jita | 60 achetés, dépose 100, pas de prix Jita | 400, rien enregistré |
| Réouverture | apport matériau approuvé de 100, projet en cours | `pending`, ligne −100 |
| Réouverture refusée | projet en vente ; ou apport de job_install ; ou sur une ligne de job | 400 |

## Cas limites

- Achat avant la création du projet : ignoré (règle #750). Transaction vendue ou autre type : ignorée.
- Le membre n'a aucun personnage : aucun achat, repli Jita.
- Un apport dérivé rejeté puis un achat supplémentaire : il reste `rejected`, valeur et quantité à jour, la ligne ne bouge pas.
- Le propriétaire qui dépose à la main reçoit le même prix payé (R5) ; l'anti-doublon de #510 (apport déclaré approuvé doublé par un apport dérivé) ne change pas.
- Transfert de propriété, suppression de compte : inchangés (les achats liés partent, l'apport dérivé est supprimé selon R4).

## Accès

Réouverture : même contrôle d'accès que la revue (`assertAdminOrOwner`). La lecture du wallet du membre ne sort pas du serveur : seule la valeur résultante est exposée.

## Tests existants contredits

Les tests de `DerivedContributionService` qui créent un apport approuvé dans un projet avec un autre relecteur attendent désormais `pending` ; les tests de valorisation Jita de `ProjectContributionServiceTest` gardent le repli sans achat.

## Glossaire

Mettre à jour **Contribution**, **Apport dérivé** et **Prix du jour d'apport** ; ajouter **Proposition d'apport** (apport dérivé `pending` d'un projet de groupe, compté après confirmation).
