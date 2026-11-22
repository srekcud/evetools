# #1359 — Onglet Transport du compagnon

Base `origin/main` (empilée sur #1370 : `GET /industry/projects/{id}/transport-legs`, spec #1364), circuit `l`, mode nuit, front seul. Décisions de Sylvain (commentaire du 2026-11-22) : (a) accès inconnu affiché avec mention, jamais masqué (le back omet déjà une course refusée partout), (b) départ depuis le stock perso : transfert déclaré seul (#1365 ajoute le renvoi vers « Utiliser mon stock »), (c) arrivée d'un achat = station du plan (déjà côté back). Le vocabulaire est celui du glossaire : **Course** (*haul leg*, une course = départ → arrivée), **tronçon**, **transfert entre stations**, **sorti**, **à apporter**.

## Contexte

Le compagnon (`GroupCompanion.vue`, panes `free`, `mine`, `jobs`) montre dans « À livrer » les transferts déclarés dont un de mes personnages est le porteur (`carriesOf`, #1304). Rien ne dit quoi porter ni d'où. L'onglet Transport liste les courses de l'endpoint, dit où est chaque course par rapport à moi, déclare le transfert et accueille les transferts en cours (déplacés depuis « À livrer »).

## Règles

R1. **Lecture.** Type `ProjectTransportLegs` (`stores/group-industry/types.ts`) calqué sur la ressource : `personalStockUnknownReason`, `unreadable[{typeId,typeName,locationId,reason}]`, `legs[{key, departure, arrival, access ('granted'|'unknown'), carrierCharacterIds, volume, lines[{typeId,typeName,quantity,state ('ready'|'in_production'|'in_transit'),source,readyAt,unitVolume,volume,unknownReason}]}]` avec `departure`/`arrival` = `{locationId, stationName, solarSystemId, solarSystemName}`. Le store `companion` garde `transportLegs: ProjectTransportLegs | null` (`null` = non chargé ou illisible) ; `loadTransportLegs` via `readFlow` ; chargé à `open`, rechargé à **chaque** `handleEvents` (les lectures dépendent du BOM, des conteneurs et des transferts) ; remis à `null` par `resetFlow`/`close`. Aucun nouveau topic Mercure : le `station_transfer_changed` publié par les processeurs de déclaration et d'arrivée, et les événements du projet, suffisent.

R2. **Calcul pur** (`companionTasks.ts` ou module voisin, testable sans montage) : `buildCompanionTasks` expose `legs: CompanionLeg[]`, `legsUnknown: boolean` (flux fourni et `transportLegs === null`), `legsUnreadableCount: number` (taille de `unreadable`), `readyLegCount: number`. `CompanionFlowInput` reçoit `transportLegs`. `CompanionLeg` : `key`, `from` (`stationName` sinon `solarSystemName`, sinon `null`), `to`, `fromSystem`, `toSystem`, `here` (`isAtStationOf(positions, departure.locationId)` : `true`/`false`, `null` si pas de `locationId` ou positions inconnues), `accessUnknown` (`access === 'unknown'`), `volume` (`null` = inconnu), `lines` (mêmes champs que l'endpoint), `declared`, `canDeclare`, `declaration` (charge utile de R4, ou `null`).

R3. **Ordre.** Courses de l'endpoint dans leur ordre, celles dont `here === true` d'abord (`hereFirst`, ordre stable). Lignes dans l'ordre de l'endpoint.

R4. **Déclarer.** Une course est `declared` quand un de mes transferts non arrivés (porteur = un de mes `myEveCharacterIds`, état `awaiting_departure`) a pour départ et arrivée les `locationId` de la course (non nuls) ; ceux en route sont déjà déduits par le back. `declaration` = `{fromSolarSystemId, fromLocationId, toSolarSystemId, toLocationId, lines, carrierCharacterId, contractId: null}` où `lines` = lignes `ready` à quantité non nulle (`{typeId, quantity}`), `carrierCharacterId` = premier de `carrierCharacterIds`, sinon (accès inconnu) mon premier personnage, `fromSolarSystemId`/`toSolarSystemId` = systèmes de la course. `declaration = null` (donc `canDeclare` faux) si un système est `null`, s'il n'y a aucune ligne prête ou aucun personnage, ou si `declared`. Le store expose `declareLeg(key)` : retrouve la course dans `transportLegs`, POST `/industry/projects/{id}/station-transfers` avec cette charge, puis recharge transferts et courses ; clé inconnue → `error = 'Unknown transport leg'` sans appel ; échec → `error` du message, sans exception. `declareLeg` recalcule la charge à partir de `transportLegs` et des personnages de l'utilisateur : le composant n'émet que la clé (`declare-leg`).

R5. **Compteur.** `readyLegCount` = courses ayant au moins une ligne `ready` à quantité non nulle et non `declared`. Onglet `transport` entre « À livrer » et « Mes jobs » : compteur = `readyLegCount`, `hot` si > 0. Grille de l'onglet à 4 colonnes quand les 4 onglets existent (projet solo : « À assigner » retiré, 3 colonnes).

R6. **Panneau** `data-testid="companion-pane-transport"`. Un bloc par course (`companion-leg`, `data-here`, `data-access`) : trajet `from → to` (noms de station) avec les systèmes, badge « ici » (`CompanionHereBadge`) si `here`, mention « accès inconnu » (`companion-leg-access-unknown`) si `accessUnknown`, volume en m³ ou marqueur inconnu `data-unknown="true"` quand `volume === null` (jamais « 0 m³ »). Chaque ligne (`companion-leg-line`, `data-state`) : nom du type, quantité (marqueur inconnu si `null`), état (« prêt à porter », « en fabrication » avec le décompte jusqu'à `readyAt`, « en route »), volume de la ligne (marqueur inconnu si `null`). En fabrication : `readyAt === null` → marqueur inconnu au lieu du décompte ; `readyAt` passé → « prêt ». Un départ sans station (achat au hub) s'affiche par son système.

R7. **Actions.** Course `canDeclare` : bouton `companion-leg-declare` qui émet `declare-leg` avec la clé ; course `declared` : badge `companion-leg-declared` sans bouton. Les transferts en cours (`carries`, bouton « Arrivé » inclus, message `carriesUnknown`) passent de « À livrer » à l'onglet Transport, section `companion-carries` **sous** les courses ; « À livrer » ne compte plus que réservations et fournitures (`toDeliverCount = mine + supplies`), son message « Rien à livrer » ne dépend plus des transferts.

R8. **États vides et erreurs.** `legsUnknown` : ligne `companion-legs-unknown` (`data-unknown="true"`), jamais « aucune course ». Lecture faite et `legs = []` : `companion-legs-empty`. `legsUnreadableCount > 0` : ligne `companion-legs-unreadable` avec le nombre de lectures manquantes.

R9. **Hôte.** `GroupCompanionHost` passe `companion.transportLegs` dans `flow` et branche `@declare-leg="companion.declareLeg"`.

R10. **i18n.** Clés `groupIndustry.companion.tabs.transport` et `groupIndustry.companion.transport.*` en FR et EN (le test de parité existant passe). Style HUD du compagnon (même gabarit que `companion-carry`), noms longs tronqués avec `title`.

## Exemples chiffrés (tests rouges)

| # | Entrée | Attendu |
|---|---|---|
| E1 | course A→B, X ready 40 (10 m³, volume 400), course volume 400 | bloc avec 40, « 400 m³ », bouton déclarer, `readyLegCount 1` |
| E2 | course dont `departure.locationId` = station de mon personnage, et une autre ailleurs | l'ici d'abord, `here true` / `false` |
| E3 | course sans `locationId` (achat au hub), positions connues | `here null`, pas de badge ici |
| E4 | `volume null`, ligne `volume null` | marqueurs inconnus, aucun « 0 » |
| E5 | `access 'unknown'`, `carrierCharacterIds []`, deux personnages | mention accès inconnu, bouton présent, porteur = mon premier personnage |
| E6 | ligne `in_production` `readyAt` dans 2 h / `null` / passé | décompte `02:00` / marqueur inconnu / « prêt » |
| E7 | lignes ready 15 + in_production 25 | charge de déclaration : seule la ligne ready, 15 |
| E8 | transfert à moi `awaiting_departure` A→B, course A→B | `declared`, badge, pas de bouton, hors compteur |
| E9 | transfert d'un autre joueur ou arrivé, même route | course non `declared` |
| E10 | `solarSystemId null` au départ | pas de bouton |
| E11 | `transportLegs null` / `legs []` / `unreadable` de 2 | `legsUnknown` / vide / ligne « 2 lectures manquantes » |
| E12 | un transfert en cours à moi | section `companion-carries` dans le panneau Transport, absente de « À livrer », `toDeliverCount` sans lui |
| E13 | clic sur déclarer | émet `declare-leg` avec la clé ; store : POST avec la charge R4, puis GET transferts et courses |
| E14 | `declareLeg('inconnue')` ; POST en échec | pas d'appel ; `error` posé, pas d'exception |
| E15 | événement `station_transfer_changed` | `handleEvents` recharge les courses |
| E16 | projet solo | trois onglets : mine, transport, jobs |

## Cas limites

- Deux lignes d'un même type dans une course (prêt projet + prêt stock perso) : la charge les envoie séparées, le déclarant additionne.
- Course déclarée puis transfert parti : le back déduit la quantité en route, la course se recalcule au rechargement.
- Transport hors compagnon (page projet) : inchangé.

## Tests existants contredits

`groupCompanionFlow.spec.ts` : « puts the carries then the supplies above the activity groups » (ordre dans « À livrer ») et « counts the reservations, the carries and the supplies in the to deliver tab » (compteur) : à réécrire pour R7, assertions de remplacement équivalentes dans le nouvel onglet. Les autres tests de carries (route, ETA, bouton arrivé) cherchent par `data-testid` et restent valides.

## Hors périmètre

Renvoi vers « Utiliser mon stock » pour un départ depuis le stock perso (#1365) ; contribution ou valorisation à la déclaration ; page projet ; optimisation des départs ; Discord ; tout changement back.

## Décisions (mode nuit)

- D1 Une course `declared` sort du compteur et perd son bouton (évite la double déclaration) ; seul l'état `awaiting_departure` compte, le reste est déduit par le back.
- D2 Porteur pour un accès inconnu : mon premier personnage (aucun autre porteur connu) ; la mention « accès inconnu » prévient le membre.
- D3 Les transferts en cours quittent « À livrer » (demande de l'issue), sans changer leur rendu.
