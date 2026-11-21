# #1159 — Corriger un « J'ai fourni » sur un projet solo

Circuit M, mode nuit (gates validés en autonomie). Niveau de technicité 3 (code Industry, règle d'accès de modification et de retrait d'une contribution).

## Problème

En projet solo, « J'ai fourni » crée une contribution approuvée d'office. Une quantité ou une valeur fausse ne se corrige pas : l'onglet Contributions est masqué, `LineReservation` cache ses boutons une fois la ligne remplie, `edit` et `withdraw` n'acceptent qu'une contribution en attente (`IndustryProjectContribution::isEditableByItsContributor()`), `reopen` ignore les lignes de job (`isReopenable()`).

## Décisions

- **Point d'entrée** (tranché par Sylvain) : une action sur la ligne elle-même (`LineReservation`), pas d'onglet Contributions en solo.
- **Lignes de job** (apport d'intermédiaire) : édition directe en solo, sans aller-retour par l'état en attente. `reopen` reste inchangé (matière uniquement, `reopenable`). Raison : un apport d'intermédiaire change le stock de départ du plan, donc la BOM est recalculée (`recalculateBomOf`) ; une édition directe recalcule une seule fois, à la fin, alors qu'un `reopen` étendu suivi d'une édition puis d'une ré-approbation ferait trois appels et laisserait la ligne non remplie entre deux.
- **Aucune nouvelle route** : `PATCH .../contributions/{id}/details` (edit) et `DELETE .../contributions/{id}` (withdraw) acceptent aussi, en solo, une contribution approuvée. Leurs processeurs publient déjà `contribution_updated` et `contribution_withdrawn` sur le topic du projet (Mercure) ; `groupProjectRefreshes.ts` rafraîchit déjà `bom`, `contributions`, `projectDetail` et `cockpit` sur ces événements.

## Règles

1. **Contribution révisable en solo** : approuvée, de type `material`, saisie à la main (`isAutoDetected` faux), rattachée à une ligne, sur un projet que son contributeur mène seul (`IndustryProject::isSoloProjectOf`), tant que le projet n'est ni en vente (`Selling`) ni terminé (`Completed`). C'est la même fenêtre que `isReopenable()`.
2. `IndustryProjectContribution::isEditableByItsContributor()` vaut vrai pour une contribution en attente (règle actuelle, inchangée) ou révisable en solo (règle 1). Le champ `editableByViewer` de la ressource suit donc, sans changement de mapper.
3. **Modifier** une contribution révisable (`ProjectContributionService::edit`) : les contrôles sont ceux d'une contribution en attente (quantité au plus égale au reste de la ligne, plus l'ancienne quantité de la contribution ; matière sans valeur saisie ; rien à changer refusé), tous faits avant la moindre écriture. La contribution reste approuvée et la quantité remplie de la ligne bouge de l'écart entre la nouvelle et l'ancienne quantité, en une transaction.
   - Ligne de job : la BOM est recalculée une seule fois, après l'enregistrement (`recalculateBomOf`, le même recalcul qu'une approbation d'apport d'intermédiaire).
   - Si la valeur recalculée laisse des unités en attente d'une transaction du wallet (`awaitingQuantity > 0`, matière hors job), la contribution repasse en attente et la ligne perd l'ancienne quantité, comme à la soumission.
   - Un refus (`DomainException`, dont le refus de recalcul de la BOM) annule la transaction.
4. **Retirer** une contribution révisable (`withdraw`) : en une transaction, la quantité remplie de la ligne est diminuée de la quantité de la contribution, les achats du wallet rattachés à elle sont détachés de la ligne, la contribution est supprimée ; sur une ligne de job la BOM est recalculée après la suppression.
5. Une contribution en attente garde exactement son comportement actuel (edit, withdraw, contributeur seul).
6. Hors solo (projet avec au moins un autre membre accepté) : une contribution approuvée reste ni modifiable ni retirable par son contributeur ; le chemin « réouvrir puis modifier » de l'onglet Contributions est inchangé.
7. Accès inchangé : seul le contributeur (`assertContributionOf`) modifie ou retire sa contribution ; une contribution automatiquement détectée ou d'un autre type (`job_install`, `bpc`, `line_rental`, `courier`) approuvée n'est pas révisable.

## Front (LineReservation, projet solo)

8. Sous la quantité fournie d'une ligne, pour chaque contribution de la ligne (`useGroupContributionStore().contributions`, `bomItemId` égal à la ligne, `type` `material`, `editableByViewer` vrai) : une pastille « Fourni ×N » et un bouton « Corriger ». Visible même quand la ligne est entièrement remplie (le bouton « J'ai fourni » reste réservé aux lignes ouvertes).
9. « Corriger » ouvre un petit éditeur en ligne : champ quantité (1 à la quantité de la contribution plus le reste de la ligne), « Enregistrer » (`editContribution`, seulement `quantity` si elle change), « Retirer » avec confirmation (`withdrawContribution`), « Annuler ». Pas de champ de valeur : une matière est valorisée automatiquement (règle 3).
10. Après un enregistrement ou un retrait réussi, la ligne recharge la BOM (`projectStore.fetchBom`) ; un échec affiche le message d'erreur de la ligne (`role="alert"`) sans fermer l'éditeur.
11. Hors projet solo, `LineReservation` ne change pas.
12. Clés i18n FR et EN sous `groupIndustry.reservation` ; direction HUD EVE, tailles et couleurs des boutons voisins.

## Exemples chiffrés

| Cas | Avant | Action | Après |
|---|---|---|---|
| Ligne Nocxium 100 requis, 100 remplis par une contribution de 100 | remplie | quantité 80 | remplie 80, reste 20, ligne rouverte, valeur = 80 × prix Jita du jour de dépôt |
| Même ligne | remplie 100 | retirer | remplie 0, contribution supprimée |
| Ligne de job Hydrogen Fuel Block, apport de 40 | requis recalculé avec stock de départ 40 | quantité 10 | BOM recalculée avec stock de départ 10, remplie 10 |
| Quantité 101 sur ligne de 100 | remplie 100 | quantité 101 | refus, rien ne change |

## Cas limites

- Édition sans champ à changer : refusée (« Nothing to change »), avant toute écriture.
- Projet qui devient de groupe après coup : règle 6, la contribution approuvée n'est plus révisable par son contributeur.
- Contribution adossée à du stock non réclamé (`drawsOnUnclaimedStock`) : le garde `UnclaimedStockGuard` du processeur d'édition continue de s'appliquer.

## Hors périmètre

- Étendre `reopen` aux lignes de job ; corriger les contributions `job_install`, `bpc`, `line_rental` ; afficher l'onglet Contributions en solo ; changer la valeur d'une matière à la main.

## Tests

- Unité service : modification approuvée (matière, job avec recalcul unique, unités en attente), retrait approuvé (matière, job), refus de dépassement sans écriture, garde de la contribution en attente ; entité : `isEditableByItsContributor` (solo approuvé, groupe approuvé, vente, auto-détecté).
- Vitest : `LineReservation` en solo (action visible ligne remplie, édition, retrait confirmé, échec, absence hors solo).
- Intégration : `PATCH details` et `DELETE` d'une contribution approuvée solo, refus en groupe.

## Décisions ouvertes

Aucune.
