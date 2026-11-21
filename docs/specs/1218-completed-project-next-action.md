# Spec — Prochaine action d'un projet clos : virements puis récapitulatif Discord

## Contexte

Une fois le projet clos (`completed`), la carte de prochaine action affiche « Projet terminé : plus rien à faire » (`ProjectCockpitCalculator::nextAction()`, code `project_completed`) alors que les **virements** restent à régler et que le récapitulatif Discord (`ProjectDiscordRecap`) reste à copier, tous deux dans l'onglet `distribution` (libellé « Finances » du parcours). L'onglet ne s'ouvre pas non plus après la clôture. Audit du 2026-11-21, issue #1218.

## Règles

- **R1.** Projet `completed`, au moins un virement du règlement non reçu : action `settle_transfers`, onglet `finances`, `quantity` = nombre de virements non reçus, pas de `targetStatus`.
- **R2.** Projet `completed`, aucun virement non reçu (tous reçus, aucun virement à faire, ou virements indisponibles, donc inconnus) : action `copy_recap`, onglet `finances`, sans `quantity`.
- **R3.** Les deux actions valent pour tout participant, gestionnaire ou non, et ne sont jamais bloquées ; les contributions en attente d'un gestionnaire ne les devancent pas sur un projet clos.
- **R4.** Le nombre de virements non reçus vient du règlement (`ProjectSettlement::$transfers`, `SettlementTransfer::isReceived()`), calculé uniquement pour un projet `completed` (`null` pour tout autre statut ou si les virements sont indisponibles : jamais 0).
- **R5.** La file d'actions inter-projets (`IndustryActionsProvider`) ignore `settle_transfers` et `copy_recap` comme elle ignore `project_completed` : `copy_recap` ne peut pas se « terminer » (rien ne dit qu'il a été copié), et la file ne doit pas lister tous les projets clos à vie.
- **R6.** Front : `settle_transfers` et `copy_recap` ont titre, description et bouton FR et EN ; le bouton ouvre l'onglet `distribution` (mapping `finances` existant).
- **R7.** Front : après la clôture confirmée (`advanceStatus()` vers `completed`), l'onglet `distribution` devient l'onglet actif et ses données sont chargées.

## Exemples

| # | Statut | Virements | Action attendue |
|---|---|---|---|
| E1 | completed | 3, dont 1 reçu | `settle_transfers`, `finances`, quantité 2 |
| E2 | completed | 2, aucun reçu, viewer non gestionnaire | `settle_transfers`, quantité 2 |
| E3 | completed | 2, tous reçus | `copy_recap`, `finances` |
| E4 | completed | aucun virement à faire (liste vide) | `copy_recap` |
| E5 | completed | indisponibles (`unknown_cost`) | `copy_recap` |
| E6 | completed, gestionnaire avec 3 contributions en attente, 1 virement non reçu | `settle_transfers` |
| E7 | selling | n'importe lequel | inchangé (pas de décompte) |

## Cas limites

- Un virement dont le reçu ne compte plus (montant changé) est non reçu : le règlement le dit déjà via `receivedAt`.
- Un projet solo clos n'a pas de virement : E4, donc `copy_recap`.

## Hors périmètre

- Retirer les clés i18n, le type TS ou la constante du code `project_completed` : rien ne les émet plus côté PHP, mais la suppression attend l'accord de Sylvain (décision du 2026-11-06). Le code reste dans `IndustryActionsProvider::NO_ACTION_CODES` et dans l'union TS.
- Résoudre #1211 (chargement de l'onglet Finances sur lien direct), #1220 (pas de virement en solo), #1217.

## Décisions

- Virements indisponibles = inconnus : on ne propose pas de les régler, on passe au récapitulatif (R2).
- Pas de nouveau champ ni d'endpoint : le cockpit lit le règlement déjà calculé par `ProjectSettlementCalculator`.
