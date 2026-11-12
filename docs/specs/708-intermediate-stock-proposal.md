# Spec #708 : proposer les intermédiaires trouvés dans les assets (projet solo)

Mode nuit (2026-11-11) : spec rédigée sans gate, empilée sur #706 (PR #710 : apport d'intermédiaire, `findIntermediateStockByTypeId`, `isCoveredByStock`). Décisions D1 à D3 du plan `.claude/tasks/intermediate-stock-plan.md` non rouvertes. Lot 3 du plan.

## Contexte

Déclarer à la main chaque intermédiaire déjà en hangar est fastidieux. Les assets du propriétaire (personnels, et container du projet) disent ce qu'il détient. Cette spec ne crée aucune règle de plan : la validation passe par l'apport d'intermédiaire existant (`POST /industry/projects/{projectId}/contributions`, type `material` sur une ligne de job), qui recalcule la BOM (#706). Rien n'est déduit tant que le propriétaire n'a pas validé.

## Règles

1. **Lecture seule, propriétaire seul** : `GET /industry/projects/{projectId}/intermediate-stock-proposal` (ressource `ProjectIntermediateStockProposal`, `id` = id du projet). `ProjectAccessChecker::assertOwner` : un autre membre reçoit 403, un projet inconnu 404. Aucune écriture, aucun nouvel évènement Mercure.
2. **État** (`state`) :
   - `not_solo` : `!$project->isSoloProjectOf($owner)` ou projet `Completed`. `lines = []`. Le front ne montre ni bandeau ni bouton.
   - `assets_unknown` : proposition impossible, `lines = []`, `unknownReason` renseigné (règle 3). Jamais de quantité 0 pour « inconnu ».
   - `ready` : proposition calculée, `lines` éventuellement vide (rien en stock : le front le dit, sans bouton de validation).
3. **Sources de stock** :
   - Personnel : somme par type des assets non corporatifs de tous les personnages du propriétaire (`CachedAssetRepository::getAggregatedQuantitiesByUser`). Jamais synchronisés (aucun asset personnel pour aucun personnage de l'utilisateur) : `assets_unknown`, `unknownReason = personal_assets_not_synced`.
   - Container du projet : seulement si `containerName` est renseigné. Contenu (sous-conteneurs compris) du ou des containers nommés dans les assets corporatifs visibles du propriétaire (logique de `ProjectContainerService`, nouvelle méthode publique `containerStockOf`). Introuvable ou non lisible : `assets_unknown`, `unknownReason` = la raison existante du service (`container_not_found`, `no_director`, `corporation_assets_not_synced`, `no_visible_division`, `owner_without_corporation`). Sans `containerName` : pas de container, pas d'état inconnu.
   - Stock d'un type = personnel + container (deux jeux d'assets disjoints).
4. **Lignes candidates** : lignes de job `isJob = true`, `jobGroup = component` (les intermédiaires du plan). Le produit final est exclu (c'est le produit du projet, pas un intermédiaire à déclarer). Une ligne sans reste à faire (`getRemainingQuantity() === 0`, dont `isCoveredByStock()`) est exclue.
5. **Quantité proposée** = `min(stockDisponible, reste de la ligne)`, `stockDisponible = stock du type − apports d'intermédiaire déjà approuvés du type` (`findIntermediateStockByTypeId` : ces unités sont toujours dans les assets, il ne faut pas les compter deux fois). Quantité proposée à 0 : la ligne n'est pas listée. Le reste de la ligne est `requiredQuantity − fulfilledQuantity` (production prévue après les apports déjà faits).
6. **Un seul apport `material` par membre et par ligne** (`validateAntiDuplicate`) : une ligne où le propriétaire a déjà un apport en attente ou approuvé n'est pas listée (`findContributedBomItemIds` n'est pas utilisable : il compte tous types, tous membres ; lire par `findByMemberBomItemAndType`).
7. **Plusieurs lignes d'un même type** (jobs scindés) : une seule proposition par type, sur la ligne de plus grand `requiredQuantity` (rang 0, cohérent avec la règle 7 de #706) ; limite connue.
8. **Ordre des lignes** : parents avant enfants, par niveau de `ChainManufacturingLevels::byTypeId` décroissant (les feuilles ont le niveau 0), puis `typeName` croissant. Les enfants se réduisent quand un parent est couvert : l'ordre limite les rejets à la validation.
9. **Contenu d'une ligne** : `bomItemId`, `typeId`, `typeName`, `demandQuantity` (= `requiredQuantity` de la ligne), `remainingQuantity`, `personalQuantity`, `containerQuantity` (`null` si pas de container), `stockQuantity` (disponible), `proposedQuantity`.
10. **Validation côté front** : la validation envoie un `POST` de contribution `material` par ligne cochée, dans l'ordre de la liste, quantité éditable (entier de 1 à `proposedQuantity`). Avant chaque `POST` après le premier, le front relit la proposition : une ligne qui n'y figure plus est sautée, une quantité supérieure au nouveau `proposedQuantity` est ramenée à celui-ci. Une erreur 400 (refus D3, valeur de marché absente…) arrête la validation, affiche le message du serveur et recharge la proposition et la BOM. Rien n'est créé pour les lignes non atteintes.
11. **Affichage** : bandeau « Utiliser mon stock » ouvert sur un projet `draft` solo dès que `lines` est non vide ; sur les autres statuts (`published`, `in_progress`, `selling`) le même panneau est replié derrière un bouton « Utiliser mon stock » (état `ready` seulement). `assets_unknown` : message explicite avec la raison traduite, pas de liste ; `not_solo` : rien ; `ready` sans ligne : message « aucun intermédiaire en stock ». Identité HUD EVE (voir `ContainerBanner.vue` / `ProjectSurplus.vue`), noms longs tronqués avec `title`.
12. **Valorisation** : inchangée (prix Jita du jour d'apport, lu par `submit`). Le panneau n'affiche aucun montant.

## Exemples chiffrés

| Cas | Entrée | Attendu |
|---|---|---|
| Personnel seul | demande (reste) 100 d'un composant, 30 en assets perso, pas de container | une ligne, `stockQuantity = 30`, `proposedQuantity = 30`, `containerQuantity = null` |
| Perso + container | 30 en perso, 50 dans le container, reste 100 | `stockQuantity = 80`, `proposedQuantity = 80` |
| Plafond par la demande | stock 150, reste 100 | `proposedQuantity = 100` |
| Apport déjà fait | apport approuvé de 30 (stock 80 dans les assets), reste 70 | pas de ligne (anti-doublon, règle 6) |
| Type absent des assets | aucun stock | pas de ligne, `state = ready` |
| Assets personnels jamais synchronisés | aucun asset perso | `assets_unknown`, `personal_assets_not_synced`, `lines = []` |
| Container nommé introuvable | `containerName` renseigné, absent des assets | `assets_unknown`, `container_not_found`, `lines = []` |
| Projet de groupe | membre accepté autre que le propriétaire | `not_solo`, `lines = []` |
| Membre non propriétaire | requête d'un participant | 403 |
| Ligne couverte | ligne à 0 run (`isCoveredByStock`) | pas de ligne |

## Cas limites

- Stock d'un type absent du plan : ignoré (seules les lignes de job du plan sont lues).
- Deux items du même type dans des sources différentes : additionnés (règle 3).
- Un asset en cours de vente ou déjà réservé ailleurs n'est pas déduit : la proposition est une lecture, le joueur valide.
- Fraîcheur : les assets lus sont ceux du dernier cache (`cached_assets`), sans synchronisation déclenchée par la lecture.

## Accès

Propriétaire seul, assets du propriétaire seuls (jamais ceux d'un autre membre). Container : assets corporatifs filtrés par `VisibleCorporationAssetsFinder` avec `$owner`, comme `verifyContainer`.

## Tests existants contredits ou modifiés

Aucun : `ProjectContainerService` gagne une méthode publique sans toucher `verifyContainer` ni `containerScopeOf` ; la liste des routes API n'est pas figée par un test exhaustif (à vérifier au rouge).

## Hors périmètre

Chaîne et container (#707), surplus d'intermédiaires (#709), annulation d'un apport, répartition du stock entre lignes scindées, proposition pour le produit final, stock d'autres membres, proposition en projet de groupe.

## Glossaire

- **Proposition de stock** (Stock proposal) : lecture des assets du propriétaire d'un projet solo, par intermédiaire du plan : quantité proposée = min(stock disponible, reste de la ligne). Rien n'est déduit avant la validation, qui crée des apports d'intermédiaire.
