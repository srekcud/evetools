# Spec #267 (reliquat) — ETA approximative d'un site à un seul scan, depuis le ledger

- **Statut** : rédigée en autonomie (mode nuit, reliquat) le 2026-11-10 sur demande de Sylvain (« lance la puce manquante »), à valider avant fusion.
- **Circuit** : `circuit:m`, sans note de câblage. **Dépend de** : #267 lot 1, #263 (instantanés du ledger), #305 (sites d'une opération), #312 (lecture du miné depuis le début). Toutes dans `origin/main`.
- **Périmètre interdit** : Industry, Group Industry, Stockpile.

## 1. Contexte

L'issue #267 demande : « lié à une opération de minage, avec un seul scan, ETA proposée depuis le débit du ledger, marquée approximative (coups critiques et residue faussent ce débit) ». La spec du lot 1 l'a mise hors périmètre en attendant #263. Sans cette ETA, un site d'opération à un seul scan affiche « inconnue : collez un 2e scan ».

Constat dans le code (lu le 2026-11-10) :

| Élément | Source |
|---|---|
| Miné depuis le début par personnage inscrit | `MiningHaulerPlanReader::minedByCharacterSinceStart` (privé) : `MiningEntry` du personnage, système de l'opération, jour ≥ jour du démarrage, moins les lignes de l'instantané de début `taken` ; `null` si l'instantané de début n'est pas `taken` ou si une quantité baisse |
| Fraîcheur du ledger | `MiningEntry::syncedAt`, posé à la création et rafraîchi seulement quand la quantité change (`MiningSyncService`, sync horaire) |
| Scan | `MiningSiteScanRepository::countVisibleOf`, `findLastOf` (`getTypes()` : `typeId`, `status`, `units`) |
| Sites d'une opération | `MiningSiteRepository::findOfOperation` |

## 2. Modèle

Aucune migration, aucune écriture. L'ETA est calculée à la lecture (jamais enregistrée : R15 du lot 1 fige les métriques de scan). Enum `MiningSiteLedgerEtaUnknownReason` (`src/Enum/`, backed string) : `operation_not_running`, `later_site`, `ledger_unknown`, `nothing_mined`. `MiningSiteResource` gagne `ledgerEta` (`?DateTimeImmutable`) et `ledgerEtaUnknownReason` (`?string`), remplis par `GET /api/mining-sites/{id}` seulement (pas la liste, pas les scans).

## 3. Règles métier

- **R1 — Applicable.** Le calcul n'a lieu que pour un site `active` lié à une opération, avec exactement **un** scan visible. Sinon `ledgerEta` et `ledgerEtaUnknownReason` valent `null` (rien à afficher : site libre, plusieurs scans, site fermé).
- **R2 — Motifs inconnus** (premier rencontré) : `operation_not_running` si l'opération n'est pas `in_progress` ; `later_site` si le site n'est pas le premier site créé de l'opération (le ledger agrège par jour, le miné d'un site précédent ne peut pas être retranché) ; `ledger_unknown` si pour un personnage inscrit d'un participant accepté le miné depuis le début est inconnu (instantané de début non `taken`, quantité qui baisse) ; `nothing_mined` si aucun type du scan n'a de débit positif (R3). Aucune valeur de repli.
- **R3 — Débit du ledger.** Miné par type depuis le début = somme, sur tous les personnages inscrits des participants acceptés, du miné depuis le début (même calcul que #312 R5). `fraîcheur` = le plus récent `syncedAt` des entrées dont le miné est positif. `durée` = `fraîcheur − startedAt` en minutes. Pour chaque type présent du scan : `débit = miné ÷ durée` (unités par minute). Un type sans miné, ou une durée ≤ 0, n'a pas de débit.
- **R4 — ETA.** Pour chaque type du scan avec un débit : `ETA = horodatage du scan + unités ÷ débit`. L'ETA du site est la plus tardive de ces ETA (comme R9 du lot 1). Les types sans débit sont ignorés.
- **R5 — Marquée approximative.** Une ETA du ledger est toujours présentée « approximative » : le ledger agrège par jour, arrive avec la sync horaire, ne compte que les personnages inscrits, et les coups critiques et le residue faussent le lien entre miné et épuisé. Elle ne remplace jamais une ETA de scan : dès un 2e scan visible, `ledgerEta` repasse à `null`.

## 4. Exemples chiffrés (calculateur pur, R3 et R4)

Démarrage 20:00. Scan à 20:10. Veldspar 60 000 u, Scordite 20 000 u.

| # | Cas | Attendu |
|---|---|---|
| E1 | Veldspar miné 12 000, fraîcheur 20:30 | durée 30 min, débit 400 u/min, ETA 20:10 + 150 min = 22:40:00 |
| E2 | E1 + Scordite minée 1 000, même fraîcheur | Scordite 33,33 u/min, ETA 20:10 + 600 min = 06:10:00 le lendemain ; ETA du site = la plus tardive |
| E3 | Rien miné | `nothing_mined` |
| E4 | Fraîcheur ≤ démarrage | `nothing_mined` |
| E5 | Type miné absent du scan (Tritanium) | ignoré, ETA des types du scan seuls |

Calcul E1 : `12 000 ÷ 30 = 400`, `60 000 ÷ 400 = 150 min`. E2 : `1 000 ÷ 30 = 33,33`, `20 000 ÷ 33,33 = 600 min`.

## 5. Accès et fraîcheur

Lecture existante de `GET /api/mining-sites/{id}` (`MiningSiteRequest::siteOf`) : propriétaire du site et participants de l'opération. L'ETA agrège les entrées ledger des personnages inscrits de l'opération ; aucune quantité par personnage n'est exposée. Aucun identifiant d'un autre périmètre n'est reçu. Fraîcheur : sync horaire (la première heure d'une opération donne souvent `nothing_mined`, dit à l'écran).

## 6. Front

Page du site (`MiningSiteDetail`) : quand `ledgerEta` est non nul et que le site n'a qu'un scan, la zone ETA de tendance affiche l'heure avec un badge « approximative » et une note « estimée depuis le ledger de la flotte » ; quand `ledgerEtaUnknownReason` est non nul, la note « collez un 2e scan » reste et le motif est traduit. Libellés `en` et `fr`. Pas de Mercure : lecture seule. Le compagnon n'est pas touché.

## 7. Décisions prises en autonomie

1. Calcul à la lecture, pas enregistré : l'historique figé (R15) ne montre pas d'ETA du ledger.
2. Fraîcheur = dernier `syncedAt` d'une entrée qui a bougé, plutôt que « maintenant » : la sync ne rafraîchit pas les entrées inchangées, et « maintenant » sous-estimerait le débit jusqu'à une heure.
3. Premier site seulement (`later_site`) : le ledger étant agrégé par jour, le miné d'un site précédent ne se retranche pas.
4. Pas d'ETA par type affichée : l'ETA du site seule, la plus tardive.

## 8. Tests existants modifiés

Aucun : `MiningHaulerPlanReaderTest` reste vert, l'extraction de la lecture du miné ne change pas son comportement.

## 9. Hors périmètre

Compagnon, liste des sites, notifications (#271), débit par personnage (#313), correction des coups critiques.

## 10. Glossaire

**ETA du ledger** (Ledger ETA) : ETA d'un site d'opération à un seul scan, déduite du miné de la flotte depuis le début de l'opération, toujours approximative (spec #267, reliquat). `LedgerSiteEtaEstimator`, `MiningSiteLedgerEtaReader`.
