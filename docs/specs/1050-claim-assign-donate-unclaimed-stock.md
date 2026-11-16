# 1050 (#1006 lot 2) : réclamer, attribuer ou donner le stock non réclamé au prix de lancement

Lot 1 (#1053) fige le prix de lancement par ligne matériau et affiche le stock non réclamé en lecture seule. Ce lot le fait entrer dans le coût du projet. Coût inconnu, marge incomplète, répartition bloquée : #1051.

## Règles

1. **Deux nouvelles sources de valeur** (`ContributionValueSource`, colonne `value_source` varchar 20, aucune migration) : `launch_price` (apport valorisé au prix de lancement figé) et `donation` (apport sans valeur due).
2. **Réclamer** : un participant accepté dépose un apport matériau par `POST /industry/projects/{projectId}/contributions` avec `valueSource: launch_price` sur une ligne matériau. Valeur = quantité × `IndustryProjectBomLine::getLaunchPrice()`, `valuedAtJitaPrice` faux, aucune lecture de prix Jita, aucun rapprochement d'achats wallet. Statut et approbation comme tout apport (`pending`, approuvé d'office pour le propriétaire seul relecteur ou un projet solo). Le prix payé du wallet (#1009) reste la source `wallet_purchase`, inchangée.
3. **Garde du stock non réclamé** (`UnclaimedStockGuard::assertWithin`), appliquée à toute réclamation `launch_price`, attribution et don : `DomainException` (400) si le projet n'est ni `in_progress` ni `selling`, si le stock du container est inconnu (`containerQuantityOf` nul : jamais de repli sur 0), ou si la quantité dépasse `UnclaimedStock::quantityOf(container, requis, apports matériau pending et approved de la ligne)`.
4. **Prix de lancement absent** (`launchPrice` nul) : la réclamation et l'attribution échouent avec `DomainException` explicite, jamais de prix de repli (ni Jita du jour, ni 0). Le don n'en a pas besoin.
5. **Modifier** une réclamation `launch_price` en attente (`edit`) : la quantité est revalorisée au prix de lancement, plafonnée au non réclamé plus sa propre quantité. Une valeur saisie reste refusée.
6. **Attribuer ou donner** : `POST /industry/projects/{projectId}/contributions/unclaimed-stock`, propriétaire seul (`assertOwner`, 403 sinon), corps `{bomItemId, quantity, mode: assign|donate, participantId?}`.
   - `assign` : `participantId` obligatoire (participant `accepted` du projet, sinon 400). Apport matériau de ce participant, valorisé au prix de lancement, source `launch_price`.
   - `donate` : apport matériau du participant du propriétaire, valeur `0.0`, source `donation`, rien n'est dû à personne.
   - Dans les deux cas l'apport est `approved` d'office par le propriétaire, `fulfilledQuantity` de la ligne augmente (`ProjectContributionService::approve`), pas de contrôle anti-doublon (le plafond du non réclamé borne déjà le cumul). Ligne de job : 400.
   - Réponse 201 `ProjectContributionResource`. Événement Mercure `unclaimed_stock_allocated` (`contributionId`, `mode`, `bomItemName`, `quantity`) sur le topic du projet, déjà autorisé.
7. **La quantité non réclamée baisse seule** : elle est calculée en continu (lot 1), toute contribution matériau pending ou approved la réduit.
8. **Front** : `ContributeModal` propose la source « Stock non réclamé au prix de lancement » seulement si la ligne a `unclaimedQuantity > 0` et `launchPrice` connu ; elle envoie `valueSource: launch_price`. `UnclaimedStockBadge` affiche, pour le propriétaire (`myRole === 'owner'`), les boutons « Attribuer » et « Donner » qui ouvrent `AllocateUnclaimedStockModal` (quantité préremplie au non réclamé, choix du participant pour attribuer) ; l'événement `unclaimed_stock_allocated` rafraîchit BOM, apports, détail et cockpit. i18n FR et EN.

## Exemples

| Non réclamé | Prix de lancement | Action | Résultat |
|---|---|---|---|
| 80 | 12,5 | réclamer 50 | apport 625 ISK, non réclamé 30 |
| 80 | 12,5 | réclamer 90 | refus (dépasse 80) |
| 80 | inconnu | réclamer ou attribuer 10 | refus explicite |
| 80 | inconnu | donner 10 | apport approuvé à 0 ISK, non réclamé 70 |
| inconnu (container illisible) | 12,5 | réclamer | refus explicite |

## Hors périmètre

Coût du projet, coût unitaire, marge incomplète, répartition bloquée (#1051) ; ajout d'une nouvelle valeur « vérifié » ; rétroactivité sur les projets déjà en cours sans prix figé (restent « inconnu »).

## Décisions prises en autonomie

- Réclamer passe par le dépôt existant (source de valeur) : « Contribute » reste la porte d'entrée.
- Le prix payé du wallet (#1009) n'est pas mélangé au prix de lancement : l'utilisateur choisit l'une ou l'autre source.
- Don = apport à valeur 0 du propriétaire, qui fait baisser le non réclamé et avancer la ligne.
- Attribuer et donner : propriétaire seul (pas les administrateurs).
