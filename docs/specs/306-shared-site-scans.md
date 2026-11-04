# Spec #306 — Minage groupé : scans partagés en direct à toute la flotte

Statut : validée en autonomie (mode nuit). Lot 1 = **back seul**. Dépend de #262 (opérations) et #267 lot 1 (sites, scans structurés, estimation), tous deux dans main. Maquette : `mockups/grouped-mining-v4.html`, étapes 7 et 8 (fonctions `scanFeed`, `lastScanInfo`, `addFreeScan`).

## Contexte

Aujourd'hui un site de minage (#267) appartient à un seul utilisateur, seul à voir ses scans. Dans une opération, un seul scan toutes les quelques minutes suffit pour toute la flotte : le site devient celui de l'opération, chaque participant peut le scanner, tous voient les mêmes scans et la même estimation, en direct.

## Règles métier

- **R1 — Site d'opération.** Un site peut être lié à une opération de minage, une fois pour toutes à sa création (champ facultatif `operationId`). Le lier à un site existant est le sujet de #305 (« Nouveau site », frise). Sans `operationId`, le site reste privé à son propriétaire, comportement de #267 inchangé.
- **R2 — Création.** Seul un participant de l'opération (propriétaire ou membre accepté, `MiningOperationAccess::canParticipate`) peut y créer un site. Opération archivée (`MiningOperationStatus::isArchived`) : refus 400. Utilisateur non participant ou opération inconnue : 404 (même message).
- **R3 — Accès.** Un site d'opération est lu et scanné par tous les participants de l'opération, quel que soit son propriétaire. Un site privé reste 404 pour tout autre que son propriétaire. Un utilisateur qui a quitté ou n'a jamais été accepté perd l'accès (relu à chaque requête). Le lecteur d'un site d'opération passe par la même résolution que celui d'un site privé (une seule règle d'accès au site, réutilisée par les providers et processors de site et de scan).
- **R4 — Scan par un participant.** Tout participant peut enregistrer un scan sur un site d'opération non fermé. Les règles de #267 restent identiques (horodatage serveur, même seconde 422, site fermé 400, bornes, verrou). Le scan porte son **auteur**, l'utilisateur connecté, y compris pour un site privé (le propriétaire).
- **R5 — Estimation commune.** L'estimation est calculée sur tous les scans du site, quel que soit leur auteur : deux participants qui alternent leurs scans produisent la même estimation que si un seul les avait faits (même résultat que #267 sur la même suite d'unités et d'horodatages).
- **R6 — Clôture.** Un site d'opération est clôturé à la main par son propriétaire ou par un administrateur de l'opération (`canAdminister`), et se clôt seul quand tous les types sont épuisés (R17 de #267). Un autre participant reçoit 403.
- **R7 — Fil des scans.** Chaque scan expose, en plus de #267 : `number` (rang chronologique du scan dans le site, à partir de 1), `authorName` (nom du personnage principal de l'auteur, voir « Décisions »), `typeCount` (nombre de types présents dans ce scan, les types épuisés exclus), et déjà `scannedAt` et `trendEta` (ETA de tendance annoncée à ce scan, inconnue = null). Aucun identifiant d'utilisateur n'est exposé. Auteur supprimé : `authorName` null (inconnu), jamais un nom par défaut.
- **R8 — Dernier scan du site.** La ressource site expose `lastScanNumber`, `lastScanAuthorName`, `lastScanAt` (déjà là) : de quoi afficher le rappel de re-scan (« dernier scan #n par X, il y a N min ») quel que soit l'auteur. Aucun scan : tous null.
- **R9 — Temps réel.** Après l'enregistrement d'un scan sur un site d'opération, l'événement `site_scan_recorded` est publié sur le topic Mercure `/user/{userId}/mining-operations` (déjà déclaré dans `MercurePublisherService::getTopicsForUser()`, aucun nouveau topic) pour le propriétaire et les membres acceptés de l'opération, via `publishMiningOperationEvent`, charge utile : `siteId`, `scanId`, `number`, `authorName`, `scannedAt`, `recentEta`, `trendEta`. Les membres en attente ne reçoivent rien. Aucune unité, aucun prix dans la charge. Un site privé ne publie rien. L'échec de publication ne fait pas échouer le scan (comportement du publisher).
- **R10 — Liste d'un site d'opération.** `GET /mining-sites?operationId=` renvoie les sites de l'opération (récent d'abord) à tout participant, tous propriétaires confondus ; non participant : 404. Sans `operationId`, la collection reste celle des sites du propriétaire, y compris ceux qu'il a liés à une opération.
- **R11 — Compte supprimé.** Propriétaire du site supprimé : le site et tous ses scans sont supprimés (R19 de #267, cascade existante), même ceux d'un autre auteur. Auteur d'un scan supprimé (propriétaire du site conservé) : le scan reste, auteur inconnu. Opération supprimée : le site reste à son propriétaire, délié (`operationId` null).
- **R12 — Historique existant.** Les scans créés avant ce lot ont pour auteur le propriétaire de leur site.

## Exemples chiffrés

Opération O, propriétaire Alpha, membres acceptés Bravo, Charlie (en attente : Delta). Site S d'Alpha lié à O, Veldspar. Valeurs de E2 (#267), horodatées 20:00, 20:10, 20:20.

| # | Cas | Attendu |
|---|---|---|
| S1 | Alpha scanne 30 000 à 20:00, Bravo 25 000 à 20:10, Charlie 18 000 à 20:20 | trois scans, `number` 1, 2, 3 ; auteurs Alpha, Bravo, Charlie ; au 3e : ETA récente 20:45:43, tendance 20:50:33 (E2), `trendEta` du fil = 20:50:33 |
| S2 | Même suite de trois scans par Alpha seul | estimations identiques à S1 (R5) |
| S3 | Delta (en attente) ou Echo (étranger) lit S ou la scanne | 404 |
| S4 | Bravo scanne S après qu'Alpha l'a clôturé | 400 (#267) |
| S5 | Bravo clôture S (il n'en est pas propriétaire, pas administrateur) | 403 |
| S6 | Bravo, administrateur de O, clôture S | 200, statut `closed` |
| S7 | Scan de Bravo sur S | Mercure : un événement `site_scan_recorded` par utilisateur parmi Alpha, Bravo, Charlie ; aucun pour Delta |
| S8 | Alpha crée un site privé, scanne | aucun événement ; Bravo reçoit 404 sur ce site |
| S9 | Bravo crée un site avec `operationId` = O archivée | 400 |
| S10 | Echo crée un site avec `operationId` = O | 404 |
| S11 | `GET /mining-sites?operationId=O` par Bravo | le site S d'Alpha, plus celui de Bravo lié à O ; Echo : 404 |
| S12 | Dernier scan de S : #3 par Charlie à 20:20 | `lastScanNumber` 3, `lastScanAuthorName` = nom du personnage principal de Charlie |
| S13 | Scan sans personnage principal pour l'auteur | `authorName` null (inconnu), pas d'erreur 500 |

## Cas limites

- Scan simultané de deux participants : verrou du site (#267) sérialise ; le second arrivé dans la même seconde reçoit 422 (même horodatage). Le front retente une seconde plus tard.
- Participant qui quitte l'opération (`left`) ou dont l'inscription est refusée : perd l'accès au prochain appel ; ses scans restent dans le fil.
- Chacun pour soi : les sites et scans restent partagés (maquette, étape « Chacun pour soi »), aucune règle propre.
- Le collage du texte (parseur) reste hors lot : l'API prend des lignes structurées {typeId, unités} (#365).

## Décisions prises en autonomie

1. Nom d'auteur = nom du personnage principal de l'utilisateur, le même affichage que `characterName` dans les ressources de l'opération.
2. Le lien site-opération est posé à la création seulement (`operationId` facultatif, immuable) ; #305 y ajoutera « Nouveau site » et la frise sans changer ce modèle.
3. Colonnes : `mining_sites.operation_id` (uuid NULL, FK `ON DELETE SET NULL`) et `mining_site_scans.author_id` (uuid NULL, FK `ON DELETE SET NULL`, rempli à la migration avec le propriétaire du site).

## Décision ouverte (rien d'implémenté)

- **Suppression d'un scan erroné d'un autre participant** (issue, « À décider »). Options : (A) auteur seul ; (B) auteur et propriétaire de l'opération ; (C) auteur, propriétaire et administrateurs (recommandé : un scan faux fausse l'ETA de toute la flotte, un administrateur doit pouvoir le retirer). Elle suppose de décider si l'estimation affichée est recalculée sans le scan retiré (R15 de #267 : les métriques enregistrées ne se recalculent jamais) ; hors lot, à trancher par Sylvain. L'issue reste ouverte.

## Hors périmètre

- Front (fil des scans, rappel de re-scan, compagnon) : dépend de l'écran d'estimation #366, du compagnon #304 et du parseur #365.
- « Nouveau site » et frise des sites d'une opération (#305) ; lier un site existant à une opération.
- Suppression d'un scan (décision ouverte ci-dessus). Notification d'épuisement proche (#271).
