# Spec #409 — Écran Blueprints (bibliothèque personnelle et corpo)

Maquette : `industry-project-v3.html#p=30`. Circuit M, mode nuit, front seul : `GET /api/industry/blueprints`, `POST /api/industry/blueprints/refresh` et le type Mercure `blueprints` existent (#408, spec `357-blueprint-library.md`).

## Règles
R1. Onglet `blueprints` de l'écran Industrie (`/industry?tab=blueprints`), groupe Operations après Stockpile, entrée de navigation `industry.tabs.blueprints` (EN, FR).
R2. Table : nom (`unknown_type` affiché `common.unknownTypeName`, jamais le mot brut), type BPO/BPC (pile de BPO `count` > 1 : `×count`), ME / TE, runs (`null` d'un BPO affiché `∞`), « Où » (propriétaire, pastille corpo si `scope` = `corporation`, division `Division N`, lieu `#locationId`).
R3. Filtre Tous / Personnels / Corpo (`scope` `personal` / `corporation`), Tous par défaut, sans nouvel appel API.
R4. `corporationState` : `no_director` affiche un bandeau « blueprints corpo inconnus : l'ESI ne les donne qu'au jeton d'un directeur » ; `no_visibility` un bandeau « aucune division corpo visible » ; `known` aucun bandeau. La liste personnelle reste affichée dans les trois cas. Aucune ligne corpo n'est inventée.
R5. Bouton Rafraîchir : `POST /industry/blueprints/refresh`, désactivé pendant la synchronisation. Mercure `blueprints` : `started` / `in_progress` désactive le bouton, `completed` recharge la liste (et affiche `failedCharacters` par `CharacterSyncFailuresAlert`), `error` affiche le message par `SyncErrorAlert`.
R6. États : chargement, erreur de lecture avec bouton Réessayer (liste précédente conservée si elle existe), vide (aucun blueprint ; en `no_director`, le bandeau reste visible).

## Exemples
| Entrée | Rendu |
|---|---|
| BPO Drake ME 10 TE 20, `personal`, runs `null`, count 1 | BPO · ME 10 · TE 20 · ∞ |
| Pile de 3 BPO Rifter, count 3 | BPO ×3 |
| BPC Hammerhead II 50 runs, `corporation`, division 3 | BPC · 50 runs · pastille corpo · Division 3 |
| `corporationState` `no_director`, 2 personnels | bandeau + 2 lignes |
| Filtre Corpo avec 0 corpo | état vide du filtre, pas d'erreur |

## Hors périmètre
Cartes « Effet sur les projets » et « Recherche à lancer » de la maquette (pas de modèle de coût de recherche, Blocage #410), nom du lieu (l'API ne donne que `locationId`), état « utilisé par un job ».

## Décisions
Onglet de l'écran Industrie plutôt que route séparée : la navigation et le contrat des onglets existent. Rien retiré.
