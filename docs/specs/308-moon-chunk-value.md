# Spec #308 — Minage lunaire : valeur d'un chunk avant de le miner

**Statut : validée en autonomie en mode nuit le 2026-11-04, à relire par Sylvain.**

Lot `group:mining-ops`, circuit `circuit:m`, une migration (une table). Dépend de #274 (calendrier, dans main) et #268 (prix compressé équivalent par unité brute, dans main). Décision de Sylvain (commentaire du 2026-11-03) : débit d'extraction de 25 000 m³/h en constante facile à modifier, format du scan supposé de la maquette `mockups/grouped-mining-v4.html` (étapes 1 et 2), à vérifier en jeu ensuite.

## Contexte

L'ESI ne donne pas la composition d'une lune : le joueur la sonde en jeu une fois (Moon Survey Probe) et colle le résultat. La composition est enregistrée pour la lune de la corporation et réutilisée à chaque chunk. La valeur d'un chunk est une estimation avant l'opération ; le ledger réel la remplace ensuite.

## Règles métier

- **M1 — Volume d'un chunk.** `(chunkArrivalTime − extractionStartTime)` en heures (fractions comprises) × `MoonChunkVolumeEstimator::EXTRACTION_RATE_M3_PER_HOUR = 25000`. Constante unique, nommée, documentée par le commit.
- **M2 — Composition.** Une composition est une liste de `(typeId, share)` : `share` est la part du volume du chunk, entre 0 exclu et 1 inclus. Elle est enregistrée par `(corporationId, moonId)` avec l'utilisateur qui l'a collée et la date. Un nouveau collage pour la même `(corporationId, moonId)` remplace la composition (re-scan) ; l'historique n'est pas gardé.
- **M3 — Format du collage** (supposé, #308). Une ligne par minerai : le nom du minerai, un séparateur (tabulation ou deux espaces et plus), la part. La part s'écrit en fraction (`0.2581`, `0,2581`) ou en pourcentage (`25,81 %`, `25.81%`). Les lignes vides sont ignorées. Le nom est résolu dans le SDE sans tenir compte de la casse (type publié, catégorie 25). Un séparateur décimal virgule ou point est accepté.
- **M4 — Rejet du collage** (422, rien n'est enregistré, message nommant la ligne fautive) : ligne sans part lisible ; nom absent du SDE ; part ≤ 0 ou > 1 ; minerai répété ; aucune ligne ; somme des parts hors de `[0.99, 1.01]`. Bornes (400) : texte de 5 000 caractères au plus, 100 lignes au plus.
- **M5 — Valeur d'un chunk.** Pour chaque minerai de la composition : `unités = share × volume du chunk ÷ volume SDE de l'unité brute`, `valeur = unités × compressedEquivalentPrice` de `OreValueService::getBatchOrePrices(..., side: MarketSide::Buy)` sur Jita (prix compressé Jita buy, par unité brute, règles de #259). `valeur du chunk` = somme des valeurs. Avant booster, avant taxe.
- **M6 — Inconnu, jamais 0.** Pas de composition enregistrée : `status = unknown`, `reason = no_scan`. Composition présente mais un minerai sans prix compressé équivalent : `unknown`, `reason = no_price`. Volume SDE absent ou ≤ 0 pour un minerai : `unknown`, `reason = no_volume`. Un prix connu à 0 vaut 0, connu. Aucun défaut, aucune somme partielle présentée comme la valeur.
- **M7 — Part par participant.** `valeur du chunk ÷ n` pour n = 4, 6, 8 (constante `PARTICIPANT_COUNTS`), valeurs non arrondies côté API, arrondies à l'ISK à l'affichage. Inconnue quand la valeur l'est.
- **M8 — Chunks concernés.** La valeur est calculée pour les chunks `extracting` et `ready`. Un chunk `exploded` n'a pas de valeur estimée (`chunkValue: null`) : la taxe et le ledger relèvent de #273.
- **M9 — API de lecture.** `GET /api/mining/corporation-extractions` (existant) : chaque extraction gagne `chunkValue` : `{status: 'known'|'unknown', reason: null|'no_scan'|'no_price'|'no_volume', volumeM3, totalValue, perParticipant: [{participants, value}], lines: [{typeId, typeName, share, units, value}], scanRecordedAt}`. `volumeM3` toujours présent ; les autres champs `null` quand le statut est `unknown` (hors `scanRecordedAt` quand une composition existe). Un seul appel de prix groupé par réponse.
- **M10 — API d'enregistrement.** `POST /api/mining/moon-compositions` (ressource DTO, `ROLE_USER`), corps `{corporationId, moonId, scan}`. Autorisé si l'utilisateur a un personnage de cette corporation et qu'une extraction existe pour `(corporationId, moonId)` ; sinon 404 (aucune fuite d'existence). Réponse 201 : `{corporationId, moonId, recordedAt, lines: [{typeId, typeName, share}]}`. Un utilisateur ne lit que les compositions des corporations de ses personnages (via M9).
- **M11 — Pas d'async, pas de Mercure** : l'enregistrement est synchrone ; le front recharge les extractions après la réponse.
- **M12 — Front** (sous-onglet « Chunks lunaires », `MoonChunksTab.vue`). Chaque chunk `extracting`/`ready` affiche une ligne : « ≈ valeur · part / pers. à 6 » ou « valeur inconnue · coller le scan de la lune » (cause `no_price` : « valeur inconnue · prix manquant »). Le détail du chunk montre : valeur estimée (Jita buy compressé, avant booster), parts à 4, 6 et 8, tableau minerai / part / unités / valeur, formule « volume ≈ N h × 25 000 m³/h = V m³ », date de la composition. Sans composition : zone de collage (textarea, Ctrl+V, bouton « Enregistrer la composition »), erreurs 422 affichées ligne par ligne. Re-collage possible depuis une composition existante. HUD EVE, jamais la couleur seule, noms de lune longs testés, textes i18n `fr`/`en`.

## Exemples chiffrés

Prix Jita buy compressé équivalent par unité brute ; harnais SDE existant des tests minage.

| # | Cas | Attendu |
|---|---|---|
| E1 | Extraction 40 h ; composition Bitumens 0,5 et Chromite 0,5 (volume SDE 10, équivalents 100 et 300) | volume 1 000 000 m³ ; unités 50 000 et 50 000 ; valeurs 5 000 000 et 15 000 000 ; total 20 000 000 |
| E2 | Même chunk | par participant : 4 → 5 000 000 ; 6 → 3 333 333,33 ; 8 → 2 500 000 |
| E3 | Lundi 18:00 → jeudi 20:00 (74 h) | volume 1 850 000 m³ |
| E4 | Extraction de 90 minutes | volume 37 500 m³ (1,5 × 25 000) |
| E5 | Aucune composition pour la lune | `unknown`, `no_scan`, `volumeM3` présent, aucune valeur |
| E6 | Composition dont Chromite sans prix compressé | `unknown`, `no_price` ; Bitumens seul n'est pas présenté comme total |
| E7 | Chromite à prix 0 connu | valeur de la ligne 0, total = somme, `known` |
| E8 | Collage `Bitumens\t50 %\nChromite\t0,5` | deux lignes 0,5 et 0,5, enregistrées |
| E9 | Collage `bitumens  0.25\nChromite  0.25` | 422 (somme 0,5) ; rien enregistré |
| E10 | Collage avec `Unobtainium 1` / `Bitumens` sans part / minerai en double / part 1,2 | 422 chacun, message avec le numéro de ligne |
| E11 | Chunk `exploded` | `chunkValue` `null` |
| E12 | Re-collage pour la même lune | une seule composition, la nouvelle |
| E13 | POST pour une lune sans extraction dans la corporation, ou corporation étrangère | 404 |
| E14 | 101 lignes, ou texte de 5 001 caractères | 400 |
| E15 | Utilisateur d'une autre corporation | ne voit pas la composition ni sa valeur |
| E16 | Front : lune sans scan | « valeur inconnue · coller le scan de la lune », jamais « 0 ISK » |

## Cas limites

- Deux corporations avec la même lune (changement de propriétaire) : compositions séparées par `corporationId`.
- Chunk dont `chunkArrivalTime ≤ extractionStartTime` impossible ESI : volume 0, valeur connue 0 (pas d'erreur).
- Nom de minerai à variante (+5 %, +15 %) : résolu par son nom exact.
- Texte collé avec fins de ligne `\r\n` : accepté.

## Hors périmètre

- Valeur après booster, taxe lunaire, part réellement minée (#273) ; ledger réel.
- Format de scan confirmé en jeu (suite : ajuster `MoonScanParser`) ; un parseur de plusieurs lunes en un collage.
- Créer une opération depuis un chunk, notifications de chunk (suite de #274).
- Historique des compositions, édition manuelle ligne par ligne.
- Choix du marché (structure, Jita sell) : Jita buy compressé fixé par l'issue.

## Décisions ouvertes (prises en autonomie, à relire)

- Composition par `(corporationId, moonId)` et non par `moonId` seul : évite de lire ou écraser la donnée d'une autre corporation.
- Somme des parts tolérée dans `[0.99, 1.01]` : un scan partiel sous-estimerait la valeur sans le dire.
