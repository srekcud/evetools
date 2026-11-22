# #1210 : un projet à usage personnel passe de Produire à Clôturer, sans phase Vendre

Mode nuit : spec validée en autonomie, décisions notées en fin de fichier.

## Contexte

Un projet à usage personnel (`IndustryProject::$personalUse`, glossaire « Usage personnel ») n'a pas de vente. Le cockpit l'ignore : il propose « Passer en vente », puis « Enregistrer les ventes » à perpétuité, la phase Vendre reste à 0/N, l'onglet Ventes reste visible, la répartition affiche « En attente de ventes » et la modale de clôture liste chaque produit comme « Produit invendu ».

## Règles métier

1. `ProjectCockpitInput` porte `personalUse` (faux par défaut), alimenté par `ProjectCockpitInputFactory` depuis `IndustryProject::isPersonalUse()`.
2. Le rail d'un projet à usage personnel n'a que quatre phases, dans l'ordre : `prepare`, `supply`, `produce`, `close`. Aucune phase `sell`, ni état ni compteur de vente. Le rail des autres projets ne change pas (cinq phases).
3. Phase courante d'un projet à usage personnel : brouillon `prepare`, publié `supply`, en cours `produce` (ou `supply` pendant l'approvisionnement, règle inchangée), vendre (statut hérité `selling`) `close`, terminé : toutes faites.
4. Prochaine action d'un projet à usage personnel en cours dont tout est livré (ni matière non couverte, ni achat à livrer, ni job non livré) : `complete_project` (cible `completed`), jamais `start_selling`. Un non-gestionnaire reçoit `nothing_to_do`, comme aujourd'hui.
5. Un projet à usage personnel au statut hérité `selling` reçoit `complete_project` (cible `completed`), jamais `record_sale`, quel que soit le nombre d'unités vendues.
6. Les autres actions (revue des demandes, revue des apports, sourcing, achats à livrer, lancement et attente de jobs) sont inchangées.
7. Transitions de statut (`UpdateProjectProcessor`) : un projet à usage personnel en cours peut passer à `completed` et ne peut plus passer à `selling` (400 « Cannot transition »). Un projet à usage personnel déjà en `selling` (données existantes) passe toujours à `completed`. Les autres projets gardent `in_progress` → `selling` → `completed`, sans passage direct.
8. La clôture d'un projet à usage personnel fait ce que fait déjà `completed` : date de clôture, libération des réservations, personnages libérés, événement `status_changed`.
9. `UnsoldProductsOfProject::of` renvoie une liste vide pour un projet à usage personnel : pas de produit invendu dans les contrôles de clôture (`ClosingChecks`), la répartition, ni la prévision de vente.
10. Front, page de détail d'un projet à usage personnel : l'onglet Ventes est masqué (une demande de l'onglet `sales` retombe sur `bom`) ; la répartition des membres (`DistributionTable`, dont le bandeau « En attente de ventes ») et les virements (`ProjectTransfers`) ne s'affichent pas ; la cascade financière (coûts, coût par unité) et le récap Discord restent.
11. Front, boutons de statut de l'en-tête : à usage personnel, la seule suite de `in_progress` est `completed` ; les autres projets gardent `selling`. `nextProjectStatuses` et `headerStatuses` reçoivent l'indicateur.
12. Front, contrôles de clôture : à usage personnel, seule la ligne « apports à valider » reste ; les lignes produits invendus, marge finale et virements sont retirées (aucune n'a de sens sans vente). Les autres projets gardent les quatre lignes.
13. Textes de `complete_project` (cartes cockpit et file d'actions, `en` et `fr`) : « Tout est vendu » devient une formulation neutre (« Close the project to freeze the accounts. » / « Clôturez le projet pour figer les comptes. »).

## Exemples chiffrés (tests rouges)

| # | Entrée | Sortie attendue |
|---|---|---|
| E1 | personnel, `in_progress`, 1 job final livré 1/1, matière couverte | phases `prepare,supply,produce,close` ; action `complete_project`, cible `completed` |
| E2 | idem, viewer non gestionnaire | action `nothing_to_do` |
| E3 | personnel, `in_progress`, job final non livré, aucun lancé | action `launch_job` (inchangé), pas de phase `sell` |
| E4 | personnel, `selling`, 5 unités à vendre, 0 vendue, jobs livrés | action `complete_project`, phase courante `close`, pas de `record_sale` |
| E5 | personnel, `draft` | phases `prepare` courante puis upcoming, quatre phases |
| E6 | personnel, `completed` | quatre phases `done`, action `copy_recap` |
| E7 | non personnel, `in_progress` tout livré | cinq phases, action `start_selling` (inchangé) |
| E8 | `ProjectCockpitInputFactory` sur un projet personnel / non personnel | `personalUse` vrai / faux dans l'entrée |
| E9 | processor : personnel `in_progress` → `completed` | statut `completed`, `completedAt` posé |
| E10 | processor : personnel `in_progress` → `selling` | `BadRequestHttpException` |
| E11 | processor : personnel `selling` → `completed` ; non personnel `in_progress` → `completed` | accepté / `BadRequestHttpException` |
| E12 | `UnsoldProductsOfProject`, projet personnel avec un produit final et aucune vente | `[]` (non personnel : un produit invendu) |
| E13 | `nextProjectStatuses('in_progress', true)` / `(…, false)` | `['completed']` / `['selling']` ; `headerStatuses` filtre la cible de la prochaine action comme avant |
| E14 | `buildClosingChecks` personnel / non personnel | une ligne `pendingContributions` / quatre lignes |
| E15 | `GroupIndustryDetail`, projet personnel | onglet Ventes absent, aucun `DistributionTable` ni `ProjectTransfers` sur l'onglet Répartition, aucun bouton « Move to selling » |

## Cas limites

- Un projet personnel qui a reçu des ventes avant ce correctif : les ventes restent en base et dans l'API (`/sales`), seul l'écran est masqué ; elles ne bloquent pas la clôture.
- `awaitingSales` de `ProjectDistributionService` n'est pas modifié : sans écran de répartition, plus rien ne l'affiche pour un projet personnel. L'atelier (`IndustryWorkshopProjectsProvider`) garde son comportement actuel de profit.
- Un projet qui devient personnel après sa création n'existe pas : l'indicateur est choisi à la création et n'est pas modifiable.

## Accès et fraîcheur

Aucun nouvel identifiant ni donnée de corporation. Les contrôles de rôle de `UpdateProjectProcessor` sont inchangés ; la table de transitions est choisie d'après le projet déjà chargé.

## Tests existants contredits ou modifiés

Aucun. `ProjectCockpitCalculatorTest` et `ProjectCockpitCalculatorValueAndCloseTest` construisent l'entrée sans `personalUse` (défaut faux). Les tests front de `nextProjectStatuses`, `headerStatuses` et `buildClosingChecks` sans le nouvel argument gardent le comportement actuel (argument optionnel).

## Hors périmètre

#1220 (aucune répartition sur un projet en solo non personnel), #1230 (actions de vente sur un projet terminé, liens des contrôles), #1227 (Clôturer devient la phase courante), refonte en flux (#1307). Aucune migration, aucun nouveau topic Mercure (le `status_changed` existant couvre la transition).

## Glossaire

« Usage personnel » : ajouter que le projet n'a pas de phase Vendre, que le rail va de Produire à Clôturer, que l'onglet Ventes, la répartition et les virements sont masqués et que les produits invendus sont hors des contrôles de clôture (#1210).

## Décisions

- D1 : `in_progress` → `selling` refusé pour un projet personnel (la phase Vendre disparaît, l'issue la supprime). Un projet personnel déjà en `selling` reste clôturable.
- D2 : texte de `complete_project` neutre plutôt qu'un nouveau code d'action : un seul code, une clé de moins par langue, `ActionQueueSorter` inchangé.
- D3 : à usage personnel, les contrôles marge finale et virements sont retirés avec les produits invendus (l'issue ne cite que les invendus ; les deux autres resteraient « ! » à jamais).
- D4 : la phase `produce` reste courante tant que le projet est `in_progress`, même tout livré ; « Clôturer devient la phase courante » est #1227.
