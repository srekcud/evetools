# #1079 — Overdue scan badges on the PVE revenue grid

Suite de #1065 (point 3 laissé de côté par #1074). Circuit M, mode nuit.

## Besoin

Rien ne mémorise quand un scan des ventes de loot ou des achats de munitions a tourné ; la grille PVE ne peut pas signaler un scan en retard.

## Règles

1. `UserLedgerSettings` porte `lastLootScanAt` et `lastExpenseScanAt` (`datetime_immutable`, nullables, migration, `null` = jamais lancé).
2. `POST /api/pve/scan-loot-sales` écrit `lastLootScanAt` à la fin du scan, sauf si aucun type de loot n'est configuré (le scan n'a rien lu : le badge doit rester, son bouton ouvre la configuration).
3. `POST /api/pve/scan-contracts` écrit `lastExpenseScanAt` à la fin du scan, toujours (le scan lit aussi les balises).
4. `POST /api/pve/scan-loot-contracts` n'écrit rien (le front l'appelle toujours avec le scan des ventes).
5. Un scan est en retard s'il n'a jamais tourné ou si son dernier passage date de plus de 7 jours (`PveScanOverdue::OVERDUE_AFTER_DAYS = 7`) ; exactement 7 jours n'est pas en retard.
6. `GET /api/pve/stats/grid` expose `scans.lootSales` et `scans.expenses`, chacun `{lastScanAt: ISO 8601|null, overdue: bool}`. Sans réglages en base : `lastScanAt = null`, `overdue = true`.
7. Front, grille PVE : un scan en retard pose un badge d'en-tête (`headerBadge`) sur la colonne Loot (`lootSales`) pour les ventes de loot, et sur la colonne Expenses (`expenses`) pour les achats de munitions.
8. Le badge porte le numéro d'un item du panneau « À corriger » ; la numérotation suit l'ordre du panneau : « jamais synchronisé » d'abord s'il est présent, puis scan du loot, puis scan des dépenses.
9. Chaque item de scan porte un texte (jamais lancé, ou « pas scanné depuis le {date} »), son effet (« les ventes / achats manquants faussent le net »), et un bouton qui lance le scan correspondant : `scanLoot` ou `scanExpenses` émis par `PveRevenueGrid`, câblés dans `PveTab` sur `scanLootSales` et `scanContracts`.
10. Après la fin d'un scan (import ou fermeture), la grille se recharge pour que le badge disparaisse.

## Exemples

| Dernier scan | Maintenant | `overdue` |
|---|---|---|
| `null` | quelconque | oui |
| il y a 6 j 23 h | 2026-11-19 12:00 | non |
| il y a exactement 7 j | 2026-11-19 12:00 | non |
| il y a 7 j 1 s | 2026-11-19 12:00 | oui |

| Grille | Panneau | Badges |
|---|---|---|
| jamais synchronisé, loot et dépenses en retard | 1 sync, 2 scan loot, 3 scan dépenses | Loot 2, Expenses 3 |
| synchronisé, loot en retard seul | 1 scan loot | Loot 1 |
| synchronisé, rien en retard | panneau absent | aucun |

## Cas limites

- Aucun type d'ammo configuré : le scan des dépenses écrit quand même (règle 3).
- Les badges ne changent pas `kind` ni `value` de la colonne.

## Hors périmètre

- Planification d'un scan automatique, notification d'un scan en retard, rappel par Mercure.
- Seuil de 7 jours réglable.
- Les endpoints de scan ne changent pas de forme de réponse (aucune route retirée ni ajoutée).

## Décisions

- Le « en retard » est calculé côté serveur (une seule règle, un seul seuil), le front ne compare pas de dates.
- Règle 2 : pas d'écriture sans type de loot configuré (décision de nuit, option la plus honnête, à confirmer par Sylvain).
- Pas d'écran ni de réglage retiré.

## Tests

PHPUnit : `PveScanOverdue` (4 lignes du tableau), entité (accesseurs), `StatsGridProvider` (`scans`), `ScanLootSalesProcessor` et `ScanContractsProcessor` (écriture / non-écriture), intégration migration via schéma. Vitest : util des colonnes (numérotation et badges), `PveRevenueGrid` (items, émissions).
