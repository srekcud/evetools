# #305 — Plusieurs sites dans une opération : « Site suivant » et frise

Maquette : `grouped-mining-v4.html`, étapes 13 à 15 (`renderSiteStrip`, `newSite`). Mode nuit : spec écrite par le thread.

## Déjà sur main (non traité)

Site lié à l'opération (`operation_id`, #306), `GET /mining-sites?operationId=`, fil des scans et événement `site_scan_recorded` (#371), `MiningOperationSites` (cartes libellé, système, statut, dernier scan, création liée), clôture manuelle (`POST /mining-sites/{id}/close`) et automatique (tous les types épuisés), ledger et parts au niveau de l'opération (#263, #264).

## Règles

**Backend**
- **R1** `MiningSite.closedAt` (nullable, migration) : posé à l'instant (`ClockInterface`) lors d'une clôture manuelle, à la date du scan qui épuise le dernier type lors d'une clôture automatique. Une clôture d'un site déjà clos ne change pas `closedAt`. Un site actif a `closedAt` null ; un site clos avant la migration a `closedAt` null (fin inconnue, pas de rattrapage).
- **R2** `MiningSiteResource` expose `closedAt`, `scanCount` (nombre de scans visibles du site, 0 sans scan) et `extractedValue`.
- **R3** `extractedValue` = `firstValue` − `remainingValue` (premier et dernier scan du site). Null (jamais 0) si le site a moins de 2 scans ou si l'une des deux valeurs est inconnue.
- **R4** `POST /mining-operations/{operationId}/next-site` (`uriVariables` + `Link`), corps `{label, solarSystemId?}` (mêmes validations que la création : libellé non vide ≤ 100, système SDE connu sinon 422), réponse 201 = le nouveau site. Accès : participant accepté de l'opération (sinon 404 comme la création), opération non archivée (400).
- **R5** Effet, en un seul flush : tous les sites `active` de l'opération passent `closed` avec `closedAt` = maintenant, puis un site `active` est créé (propriétaire = l'appelant, `operationId` = l'opération). Si l'un des sites actifs n'appartient pas à l'appelant et que l'appelant n'est pas administrateur de l'opération (`canAdminister`) : 403 et rien n'est modifié ni créé. Sans site actif, l'action crée simplement le site.
- **R6** Mercure : événement `site_next_started` sur le topic de l'opération (`publishMiningOperationEvent`, audience = participants), données `siteId` (nouveau site), `closedSiteIds`. Aucun événement en cas d'erreur.

**Frontend**
- **R7** Type `MiningSite` : `closedAt`, `scanCount`, `extractedValue` (optionnels, null permis). Store `miningSite.startNextSite(operationId, {label, solarSystemId})` : POST de R4, puis recharge `operationSites` ; renvoie le nouveau site ; l'erreur du serveur (403, 400, 422) est stockée dans `error` et relancée, jamais avalée. **Nom pour #304** : action `startNextSite`, endpoint `POST /api/mining-operations/{operationId}/next-site`.
- **R8** `MiningOperationSites` : chaque carte ajoute (data-testid) `operation-site-start` (début : `createdAt`, heure EVE), `operation-site-end` (`closedAt`, ou « en cours » si actif, ou « fin inconnue » si clos sans `closedAt`), `operation-site-scans` (`scanCount`), `operation-site-extracted` (`extractedValue` formaté en ISK, ou « inconnue » si null — jamais 0 —, avec l'indication « mesuré entre le premier et le dernier scan » quand `scanCount` < 2). Les cartes, liens, badge et dernier scan existants sont inchangés.
- **R9** Bouton `operation-site-next` (« Site suivant ») dans l'en-tête, visible si `canCreate` et au moins un site actif ; le bouton « Nouveau site » existant (création liée sans clôture) reste. Il ouvre `MiningSiteCreateModal` avec la prop `closesActive` ; à la validation la modale appelle `startNextSite` au lieu de `create`, ferme, puis route vers `mining-site-detail` du nouveau site. Un texte de la modale dit que les sites actifs de l'opération seront clôturés.
- **R10** `MiningOperationDetail` : l'événement `site_next_started` recharge les sites (comme `site_scan_recorded`).
- **R11** i18n FR et EN : clés `miningOperationSites.*` (next, start, end, endOngoing, endUnknown, scans, extracted, extractedUnknown, extractedHint) et `miningSite.create.closesActive`. Direction design HUD EVE, noms longs tronqués.

## Exemples chiffrés

| # | Cas | Résultat |
|---|---|---|
| E1 | Site à 2 scans : valeur 1 000 000 puis restant 400 000 | `extractedValue` 600 000 |
| E2 | Site à 1 scan | `extractedValue` null, `scanCount` 1 |
| E3 | Restant inconnu (prix manquant) | `extractedValue` null |
| E4 | Clôture manuelle à 20:30 | `closedAt` 20:30 ; seconde clôture à 20:45 : reste 20:30 |
| E5 | Dernier type épuisé par un scan de 20:12 | `closedAt` 20:12 |
| E6 | Opération avec S1 et S2 actifs (propriétaire Alpha), Alpha appelle next-site | S1, S2 clos, S3 actif, 201 |
| E7 | Bravo (participant, non admin) appelle ; S1 appartient à Alpha | 403, S1 actif, aucun site créé, aucun événement |
| E8 | Bravo administrateur de l'opération | S1 clos, S3 créé au nom de Bravo |
| E9 | Opération sans site actif | S1 créé, rien à clore |
| E10 | Non-participant / opération archivée / libellé vide / système inconnu | 404 / 400 / 422 / 422 |

## Hors périmètre

Type de site (chunk, anomalie) : non modélisé, le libellé le porte. Compagnon et sa commande « Nouveau site » (#304, se branche sur `startNextSite` après fusion). Rattrapage de `closedAt` des sites clos avant la migration. ETA par site et parts sur le ledger de l'opération : déjà le cas.

## Décisions ouvertes

`extractedValue` peut être négatif si les prix montent entre deux scans : valeur brute, non bornée.
