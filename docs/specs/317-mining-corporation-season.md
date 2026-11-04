# Spec #317 — Minage groupé : saison de la corporation (lot partiel)

- **Maquette** : `mockups/grouped-mining-v4.html` (étape 20, `renderSeason`, `seasonCharRows`). Direction HUD EVE, noms longs à tester.
- **Dépend de** : #264 (fermée), #267 (ouverte, aucun site dans `src/`).
- **Lot** : agrégat de l'historique des opérations, aucune donnée ESI nouvelle, aucune migration, aucun Mercure (lecture seule, aucune action asynchrone).
- **Mode nuit** : spec validée en autonomie.

## 1. Hors lot (restent dans #317, ouverte)

- **Meilleurs sites par système** : les sites et leurs scans n'existent pas (#267, #305).
- **ISK/h par vaisseau** : le vaisseau de chaque personnage n'est lu ni stocké (spec 263 l'a écarté ; seule la coque du booster existe, #311). Le lire serait un nouvel appel ESI `GET /characters/{id}/ship/` (scope `esi-location.read_ship_type.v1` déjà demandé) à chaque instantané : décision structurelle, voir le Blocage du journal.
- Colonne « booster, ne mine pas », répartition au temps pondéré (#270).

## 2. Règles métier

- **R1 — Périmètre.** Les opérations de visibilité `corporation` dont `corporationId` est celui de l'utilisateur, au statut `closing`, `shares`, `settled` ou `completed`, dont `endedAt` est dans la période. Un utilisateur sans corporation obtient une saison vide (zéros, `iskPerHour` et `knownValue` `null`). Toute lecture : un membre de la corporation lit la saison de sa corporation, personne d'autre (aucun paramètre ne choisit la corporation).
- **R2 — Période.** `period` ∈ `30d` (défaut), `90d`, `all`. `30d`/`90d` : `endedAt ≥ maintenant − N jours` (horloge `ClockInterface`). Autre valeur : `400`.
- **R3 — Valeur connue.** Une opération a une valeur connue si ses `MiningFrozenMinedValue` existent (gel des parts fait, `sharesFrozenAt` non nul). Sa valeur G = somme des `minedValue` figés. Une opération `closing` ou sans gel est « inconnue » : ses heures sont comptées, jamais son ISK pour 0 (le gel refuse tout prix ou quantité manquant, #264).
- **R4 — Minutes d'une opération.** `endedAt − startedAt`, en minutes entières.
- **R5 — Présence d'un joueur.** Un joueur = un membre `accepted` (ou parti : `departedAt` non nul) d'une opération du périmètre ayant au moins un instantané de début lu (`takenAt` non nul). Début = le plus ancien `takenAt` des instantanés de début de ses personnages ; fin = `departedAt` s'il existe, sinon `endedAt` de l'opération ; minutes = fin − début, jamais négatif. Sans instantané de début lu, le membre n'est pas compté pour cette opération.
- **R6 — Joueurs.** Un joueur est un utilisateur ; ses minutes et ses opérations s'additionnent sur le périmètre. Sa valeur minée connue = somme de ses `minedValue` figés sur les opérations connues ; `null` si aucune opération connue ; `hasUnknownValue` vrai s'il a pris part à une opération inconnue. Nom = le personnage principal (nom figé `participantName` quand il existe, sinon le personnage principal de l'utilisateur). Aucun identifiant d'utilisateur dans la réponse.
- **R7 — Totaux.** `operationsCount` ; `unknownValueOperationsCount` ; `knownValue` = somme des G connues (`null` si aucune opération connue) ; `knownOperationMinutes` = somme des minutes des opérations connues ; `playedMinutes` = somme des minutes de tous les joueurs ; `iskPerHour` = `round(knownValue × 60 / knownOperationMinutes)` entier, arrondi à l'entier le plus proche (0,5 vers le haut), `null` si `knownOperationMinutes` vaut 0.
- **R8 — Ordre.** Joueurs triés par minutes décroissantes, puis nom croissant.
- **R9 — Calcul pur.** `MiningSeasonCalculator::summarise(list<MiningSeasonOperation>): MiningSeasonSummary`, sans Doctrine. `MiningSeasonOperation` : `{minutes, ?int value, list<MiningSeasonParticipant{userKey, name, minutes, ?int minedValue}>}`. Le service d'infrastructure qui lit les entités (période, périmètre, présence R5) est séparé.

## 3. Endpoint (API Platform)

`GET /api/mining-operations/season?period=` : `MiningSeasonResource` (sans identifiant, sans `$id`), provider dédié, `ROLE_USER`, déclaré avant `/mining-operations/{id}` comme `/archive`. Réponse : `{period, operationsCount, unknownValueOperationsCount, knownValue, knownOperationMinutes, playedMinutes, iskPerHour, players: [{name, operationsCount, minutes, minedValue, hasUnknownValue}]}`. Clés `null` présentes (pas omises).

## 4. Exemples chiffrés (G en ISK)

| # | Opérations du périmètre | Résultat |
|---|---|---|
| 1 | A : 120 min, G 120 M (P1 120 min 70 M, P2 90 min 50 M) ; B : 60 min, G 30 M (P1 60 min 30 M) | 2 ops, 0 inconnue, `knownValue` 150 M, `knownOperationMinutes` 180, `iskPerHour` 50 000 000, `playedMinutes` 270 ; P1 (2 ops, 180 min, 100 M), P2 (1 op, 90 min, 50 M) |
| 2 | Ex. 1 + C inconnue : 90 min (P1 90 min, P3 90 min) | 3 ops, 1 inconnue, `knownValue` 150 M, `iskPerHour` 50 000 000 (C exclue), `playedMinutes` 450 ; P1 270 min 100 M `hasUnknownValue` vrai ; P3 90 min, `minedValue` `null`, `hasUnknownValue` vrai |
| 3 | Aucune opération | tout à 0, `knownValue` `null`, `iskPerHour` `null`, `players` `[]` |
| 4 | Une seule opération inconnue (90 min, P3) | 1 op, 1 inconnue, `knownValue` `null`, `knownOperationMinutes` 0, `iskPerHour` `null`, `playedMinutes` 90 |
| 5 | Une opération connue de 7 min, G 1 000 000 | `iskPerHour` 8 571 429 (8 571 428,57 arrondi) |
| 6 | Deux joueurs à 60 min : « Zed » et « Abel » | Abel avant Zed |
| 7 | Membre sans instantané de début lu | n'apparaît pas dans les joueurs de cette opération ; l'opération compte quand même |
| 8 | Membre parti à 50 min d'une opération de 120 min | 50 min de présence (début = son instantané) |

Scénarios d'accès : membre de la corporation (voit) ; utilisateur d'une autre corporation (ne voit aucune opération de la première) ; opération de visibilité `private` ou `public`/lien de la même corporation (exclue) ; opération `in_progress` ou `planned` (exclue) ; `endedAt` à 31 jours avec `30d` (exclue) et avec `90d` (incluse) ; `period=7d` → 400 ; sans corporation → saison vide.

## 5. Écrans (front)

- 4ᵉ onglet « Saison » de `MiningOperationList.vue` (`LIST_TABS`, `useTabQuery`), composant `MiningSeasonPanel.vue`, store `fetchSeason(period)`. Rechargé à l'ouverture de l'onglet, au changement de période et sur les événements d'archivage déjà écoutés.
- Sélecteur de période 30 j / 90 j / Tout (défaut 30 j). Quatre KPI : Opérations (« N sans prix »), Valeur connue, Heures minées (joueurs, durée en h min), ISK/h moyen.
- Bandeau ambre « Valeur de la saison inconnue » si `unknownValueOperationsCount > 0` (heures comptées, pas l'ISK ; le total connu l'exclut). Valeur ou ISK/h `null` : « inconnu », jamais 0.
- Tableau « Heures minées par joueur » : barre proportionnelle aux minutes, valeur minée connue, mention « + inconnu » si `hasUnknownValue`. Noms longs à la ligne (`overflow-wrap:anywhere`). Saison vide : état vide.
- Pas de bloc « par vaisseau » ni « meilleurs sites » (R hors lot), pas de faux bloc vide. i18n `en.json` et `fr.json`, termes du glossaire.

## 6. Cas limites

- Opération `closing` terminée sans gel : inconnue (R3), heures comptées.
- Deux opérations de la même corporation se chevauchant : minutes d'un joueur additionnées (pas de dédoublonnage, présence par opération).
- Utilisateur ayant changé de corporation : la saison suit sa corporation actuelle.
- Opération supprimée ou récurrence stoppée : hors périmètre de la saison (suit les opérations existantes).

## 7. Décisions prises en autonomie

1. Lot partiel : sans vaisseau ni sites, issue laissée ouverte.
2. Opérations comptées dès `closing` (valeur inconnue tant que non gelées), pas seulement les archivées : c'est la seule façon de rendre « sans prix » visible, le gel refusant les prix manquants.
3. Heures d'un joueur = présence mesurée par ses instantanés de début, jamais l'activité en jeu (comme la maquette).
4. Aucun identifiant d'utilisateur exposé, noms issus des données figées ou du personnage principal.

## 8. Termes du glossaire (à ajouter)

| FR | EN | Définition |
|---|---|---|
| **Saison** | Season | Agrégat sur une période des opérations de minage de la corporation de l'utilisateur. |
| **Valeur connue** | Known value | Somme des valeurs figées des opérations dont les parts sont gelées. |
| **Heures minées** | Mined hours | Minutes de présence d'un joueur, du premier instantané de début lu à sa fin. |

## 9. Fichiers

`src/Service/MiningOperation/MiningSeasonCalculator.php`, `MiningSeasonOperation.php`, `MiningSeasonParticipant.php`, `MiningSeasonSummary.php`, un lecteur de périmètre, `src/ApiResource/MiningOperation/MiningSeasonResource.php`, `src/State/Provider/MiningOperation/MiningSeasonProvider.php` ; `frontend/src/views/MiningOperation/MiningSeasonPanel.vue`, `miningOperationSeason.ts` (format), store, `MiningOperationList.vue`, `en.json`, `fr.json`, `docs/glossary.md`.
