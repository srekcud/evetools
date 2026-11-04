# Spec #371 — Minage groupé : fil des scans partagés et rappel de re-scan (front de #306)

Statut : validée en autonomie (mode nuit). Front seul, aucune migration, aucun changement d'API. Dépend de #366 (page d'un site, store `miningSite`, rappel F14) et de #306 (back dans main). Maquette : `mockups/grouped-mining-v4.html`, fonctions `scanFeed`, `lastScanInfo` et `renderSiteStrip` (sans la frise ni « Nouveau site » de #305).

Le front affiche ce que le serveur renvoie : numéro, auteur, `typeCount`, ETA de tendance sont lus tels quels, jamais recalculés. Aucun identifiant d'utilisateur n'est exposé par l'API : pas de marque « vous ».

## Fil des scans (page d'un site)

- **R1** Composant `MiningSiteScanFeed` (`frontend/src/components/mining-site/`) : les 5 scans les plus récents du site, du plus récent au plus ancien, un par ligne (`data-testid="scan-feed-row"`, `data-scan-number`). Ligne : `#number`, heure EVE (`formatEveClock`), nom de l'auteur, « N types » (`typeCount`, pluriel i18n : « 1 type »), ETA de tendance du scan (`trendEta`, `miningSite.eta.unknown` si null).
- **R2** Auteur null (compte supprimé, personnage principal absent) : libellé `miningSite.feed.unknownAuthor` en ambre, jamais un nom par défaut ni une ligne vide. Nom très long : tronqué avec `title` complet.
- **R3** Placement : section `eve-card` « Fil des scans » sur la page d'un site, au-dessus de l'évolution des ETA (F19 de #366, inchangée), visible dès qu'il existe un scan, pour un site privé comme pour un site d'opération (l'auteur d'un site privé est son propriétaire). Sous-titre `miningSite.feed.shared` (« partagé en direct avec la flotte ») uniquement si `site.operationId` n'est pas null.
- **R4** Les scans viennent de `store.scans` (déjà chargés par la page) : tout nouveau scan, local ou reçu en direct, apparaît en tête du fil sans recharger la page.

## Temps réel (page d'un site)

- **R5** Si `site.operationId` n'est pas null, la page observe `syncStore.getMiningOperationEvent(site.operationId)` (événements Mercure du topic `/user/{userId}/mining-operations`, déjà déclaré, aucun nouveau topic). Action `site_scan_recorded` avec `data.siteId` égal au site affiché : recharge les scans et le site (`fetchScans`, `fetchSite`) ; le fil, le rappel, l'ETA et la courbe se mettent à jour. Les autres actions, un autre `siteId` ou une charge sans `siteId` chaîne : ignorés.
- **R6** Un site sans `operationId` n'écoute rien. L'observation s'arrête avec la page (watcher lié au composant).
- **R7** Échec du rechargement déclenché par un événement (404 si l'accès a été perdu, réseau) : affiché dans le bandeau d'erreur existant de la page (`role="alert"`), les scans déjà affichés restent à l'écran, aucune boucle de reprise.

## Rappel de re-scan

- **R8** Ligne « dernier scan » (`data-testid="site-last-scan"`) sur la page d'un site **actif** qui a au moins un scan, quel que soit son auteur : « Dernier scan #`lastScanNumber` par `lastScanAuthorName`, il y a N min ». `lastScanAuthorName` null : `miningSite.feed.unknownAuthor`. Âge = maintenant − `lastScanAt`, arrondi à la minute inférieure, recalculé chaque seconde par le minuteur existant ; moins d'une minute : `miningSite.lastScan.justNow`. Absente d'un site clôturé et d'un site sans scan.
- **R9** La pastille « re-scanner » de F14 (délai réglable, 10 min par défaut, mémorisé en `localStorage`) reste telle quelle : la ligne R8 est permanente, la pastille n'apparaît que le délai dépassé. Un scan reçu en direct (R5) remet l'âge à « à l'instant » et fait disparaître la pastille.

## Sites d'une opération (page d'une opération)

- **R10** Store `miningSite` : `operationSites` (liste), `fetchOperationSites(operationId)` (`GET /mining-sites?operationId=`, récent d'abord, tous propriétaires confondus), `create` accepte `operationId` facultatif transmis tel quel à `POST /mining-sites`. Erreur du serveur (404, 400) dans `error`, jamais avalée ; `operationSites` vidée sur échec.
- **R11** `MiningOperationDetail` : section `MiningOperationSites` (`frontend/src/components/mining-operation/`) pour un participant accepté (`participates`). Carte par site : libellé, système (`solarSystemName`, omis si null), badge Actif ou Clôturé, dernier scan « #n par X, il y a N min » (même formulation et même repli que R8, « aucun scan » sans scan), lien vers la page du site (`mining-site-detail`). Liste vide : message `miningOperationSites.empty`. Échec de chargement : bandeau `role="alert"`.
- **R12** Bouton « Nouveau site » (ouvre `MiningSiteCreateModal`, qui reçoit une prop facultative `operationId` transmise à `store.create`) visible pour un participant accepté tant que l'opération n'est pas archivée (`settled`, `completed`) ; sans la prop, la fenêtre se comporte comme dans #366. Succès : navigation vers la page du site créé. Un refus 400 ou 404 du serveur s'affiche dans la fenêtre, qui reste ouverte.
- **R13** Temps réel : sur `site_scan_recorded` de l'opération affichée (watcher existant de `syncStore.getMiningOperationEvent(operationId)` de `MiningOperationDetail`), recharge `fetchOperationSites` ; un site créé par un autre participant n'a pas d'événement : il apparaît au prochain chargement de la page (hors périmètre : événement de création, relevé à #305).
- **R14** Chargement : `fetchOperationSites` part avec le chargement initial de la page, uniquement pour un participant accepté ; un participant en attente ne le déclenche pas (le serveur répondrait 404).

## Exemples chiffrés

Opération O, site S lié à O, trois scans : #1 Alpha 20:00 (1 type), #2 Bravo 20:10 (1 type), #3 Charlie 20:20 (1 type, `trendEta` 20:50:33). Maintenant = 20:24:30.

| # | Cas | Attendu |
|---|---|---|
| X1 | Fil de S | trois lignes, #3 en tête : « #3 · 20:20 · Charlie · 1 type · 20:50 » (heure à la minute), puis #2 Bravo, #1 Alpha |
| X2 | Six scans | cinq lignes, le plus ancien (#1) absent |
| X3 | Scan #2 sans auteur (`authorName` null) | ligne #2 avec « auteur inconnu » en ambre |
| X4 | Ligne R8 de S à 20:24:30 | « Dernier scan #3 par Charlie, il y a 4 min » ; à 20:20:20 : « à l'instant » |
| X5 | Délai 10 min, maintenant 20:31:00 | ligne R8 « il y a 11 min » et pastille « re-scanner » ; événement `site_scan_recorded` de S : scans rechargés, âge « à l'instant », pastille absente |
| X6 | Événement `site_scan_recorded` d'un autre site S2 de O | page de S : aucun rechargement ; page de O : liste rechargée |
| X7 | Site privé (`operationId` null) | fil visible, sous-titre « partagé » absent, aucun watcher Mercure |
| X8 | Site clôturé | fil visible, ligne R8 et pastille absentes |
| X9 | `lastScanAuthorName` null sur la carte de O | « Dernier scan #3 par auteur inconnu, il y a 4 min » |
| X10 | Création depuis O | `POST /mining-sites` avec `{label, solarSystemId, operationId: O}` ; O archivée : 400 affiché dans la fenêtre, bouton absent |

## Cas limites

- Deux événements rapprochés : deux rechargements, le dernier état gagne ; pas de file ni de dédoublonnage.
- Participant qui quitte l'opération pendant qu'il regarde le site : le prochain rechargement répond 404, bandeau R7.
- Site d'opération consulté sans connexion Mercure active : la page reste correcte, simplement sans mise à jour en direct (rechargement manuel).
- FR et EN : clés `miningSite.feed.*`, `miningSite.lastScan.*` et `miningOperationSites.*` dans les deux fichiers ; direction design HUD EVE (`eve-card`, police mono pour #n, heures et âges, ambre pour « inconnu »), lisible à 320 px.

## Décisions prises en autonomie

1. Le fil montre les 5 derniers scans (comme la maquette) ; l'historique complet reste dans le tableau d'évolution de #366.
2. Un événement déclenche un rechargement serveur plutôt qu'une construction depuis la charge : la charge ne porte ni les types ni les valeurs, et le serveur reste la seule source.
3. La page d'une opération liste les sites et en crée un ; la frise et « Nouveau site » qui clôt le site courant sont #305.

## Hors périmètre

Collage du texte et Ctrl+V (#365), compagnon (#304), frise et lien d'un site existant à une opération (#305), badge de compression (#367), suppression d'un scan (#372), notification d'épuisement (#271), événement de création de site.
