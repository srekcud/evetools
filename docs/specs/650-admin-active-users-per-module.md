# #650 — Utilisateurs actifs par module (admin)

Mode nuit : spec validée en autonomie. Décision de Sylvain (issue, 2026-11-11) : aucun nouveau stockage, un utilisateur est actif sur un module s'il a des données dans ce module et que `lastLoginAt` date de moins de N jours.

## Contexte

L'onglet Statistiques de l'admin ne dit pas quels modules les joueurs utilisent. On ajoute un tableau « utilisateurs actifs par module » sur 7 et 30 jours, alimenté par `GET /api/admin/stats` (clé `moduleActiveUsers`), sans table ni événement nouveau.

## Règles métier

1. Fenêtres : 7 jours et 30 jours glissants, `users.last_login_at >= now - N jours` (même calcul que `UserStatsDto::activeLastWeek` / `activeLastMonth`). Un utilisateur sans connexion n'est jamais actif.
2. Un utilisateur est compté une fois par module, quel que soit le nombre de ses lignes. Il peut figurer dans plusieurs modules : les lignes ne s'additionnent pas.
3. « A des données dans le module » :

| Module (clé) | Données de l'utilisateur |
|---|---|
| `industry` | propriétaire d'au moins un projet industriel (`industry_projects.user_id`) |
| `groupIndustry` | participant `accepted` d'un projet qui a au moins un autre participant `accepted` |
| `mining` | une entrée de minage, ou propriétaire ou membre d'une opération de minage |
| `planetary` | au moins une colonie planétaire d'un de ses personnages |
| `market` | au moins un favori ou une alerte de prix de marché |
| `pve` | au moins un revenu ou une dépense PVE |
| `contracts` | inconnu : les contrats sont lus en direct depuis l'ESI, rien n'est stocké par utilisateur |

4. `contracts` renvoie `null` aux deux fenêtres (jamais 0 : un zéro serait une valeur plausible fausse). L'interface affiche « — » et une mention « mesure indisponible ».
5. Ordre fixe : industry, groupIndustry, mining, planetary, market, pve, contracts. Les 7 lignes sont toujours présentes, même à 0.
6. Un utilisateur actif sur 30 jours l'est aussi sur 7 jours seulement s'il s'est connecté dans les 7 jours : `activeLast7Days <= activeLast30Days` pour chaque module.
7. Interface : tableau sur l'onglet Statistiques, une ligne par module, deux colonnes (7 j, 30 j) et une barre proportionnelle au total d'utilisateurs actifs sur 30 jours (`users.activeLastMonth`), style HUD de l'admin.

## Exemples chiffrés (tests rouges)

| Cas | 7 j | 30 j |
|---|---|---|
| Propriétaire d'un projet, connecté il y a 2 jours | industry 1 | industry 1 |
| Même utilisateur avec 3 projets | industry 1 | industry 1 |
| Propriétaire d'un projet, connecté il y a 20 jours | 0 | 1 |
| Propriétaire d'un projet, connecté il y a 45 jours | 0 | 0 |
| Propriétaire d'un projet, jamais connecté | 0 | 0 |
| Projet à un seul participant `accepted` | groupIndustry 0 | 0 |
| Projet avec propriétaire et un membre `accepted` (tous deux connectés récemment) | groupIndustry 2 | 2 |
| Membre `pending` ou `removed` | pas compté en groupIndustry | |
| Entrée de minage seule ; opération possédée seule ; membre d'opération seul | mining 1 chacun | |
| Colonie sur un alt, utilisateur connecté | planetary 1 | |
| Favori seul ; alerte seule | market 1 chacun | |
| Revenu seul ; dépense seule | pve 1 chacun | |
| Aucune donnée | 7 lignes à 0, contracts à `null` | |

## Cas limites

- Base vide : 6 modules à 0, `contracts` à `null`.
- Participant dont `user_id` est `null` (utilisateur supprimé) : ignoré.
- Un utilisateur actif sans aucune donnée de module n'apparaît dans aucune ligne.

## Accès et fraîcheur

- Même route et même contrôle que `/admin/stats` (`AdminChecker`, 403 pour un non-admin) : aucune nouvelle route, aucun nouvel accès.
- Les données sont des comptes agrégés : aucun nom de joueur, personnage, corporation ou alliance (case Anonymisation de l'issue).
- Calcul à chaque appel, sans cache (comme les autres compteurs de `AdminService::getStats()`).

## Tests existants modifiés

Aucun : `AdminService` et `AdminStatsProvider` gardent leur constructeur ; `getStats()` gagne une clé.

## Hors périmètre

- Statistiques tierces, événement d'usage par route (option écartée par la décision).
- Mesure du module Contrats : décision produit ouverte, voir l'issue (commentaire).
- Graphique chart.js : un tableau à barres CSS suffit.

## Glossaire

Aucun terme nouveau : « Utilisateur actif » est déjà porté par `UserStatsDto`.
