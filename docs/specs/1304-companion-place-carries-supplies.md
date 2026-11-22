# #1304 — Le compagnon montre le lieu, les emports, les apports et les demandes d'adhésion

Branche `feat/industry-flow`, après #1300 (actions par lieu), #1295 (transferts entre stations), #1166 (stock perso par station) et #1301 (réservation de 48 h). Une seule ligne de backend (`solarSystemName` sur la position d'un personnage) ; tout le reste est du front dans la fenêtre détachée `GroupCompanion`, qui garde sa structure de #1171 (anneau, barres par activité, alertes, onglets À attribuer / À livrer / Mes jobs, lignes par activité). Prototype : `industry-prototype-ishtar-2026-11-21.html`, fonctions `companionRows`, `cmpRow`, `companion`.

## Données lues (le store `companion` les charge ; aucune n'est écrite par le compagnon sauf R9 et R10)
- `GET /industry/projects/{id}/place-actions` (`PlacedAction[]`), `GET /me/characters/locations`, `GET /industry/projects/{id}/station-transfers`, `GET /industry/projects/{id}/intermediate-stock-proposal`.
- Chaque lecture qui échoue laisse sa donnée à `null` (« inconnu ») et pose `error` ; jamais une liste vide à sa place.

## Règles
R1 **Position** : `GET /me/characters/locations` gagne `solarSystemName` (`?string`, nom SDE du système ; `null` si la position est illisible ou le système absent du SDE). `CompanionTasks.hereSystems: string[] | null` = noms distincts (ordre des personnages) ; `null` si positions non lues, liste vide ou aucun nom. En-tête (barre et colonne) : « Ici : A, B » (`data-testid="companion-here"`), « Ici : inconnu » (`data-unknown="true"`) si `null`.
R2 **Lieu d'une ligne** : `CompanionTasks.linePlaces: Record<bomLineId, { stationName, systemName, here }>` depuis les `PlacedAction` de code `launch_job` ou `source_material` dont `bomLineId` n'est pas nul et `place.kind = 'station'` (`here` repris tel quel, `null` si inconnu). Pas d'entrée pour `anywhere`, `unknown` ni sans `bomLineId`.
R3 **Badge « ici » et tri** : une ligne des listes À attribuer et À livrer (réservations) dont `linePlaces[id].here === true` porte `data-testid="companion-here-badge"` (« ici ») et passe avant les autres ; tri stable après l'ordre de chaîne, avant le plafond de 6 lignes libres. Aucune entrée ou `here` nul/faux : ni badge ni déplacement. Le lieu (`station · système`, infobulle = nom complet) s'écrit sous le nom (`companion-line-place`) quand il existe.
R4 **Emports** : `CompanionTasks.carries` = tronçons de transfert dont l'état n'est pas `arrived` et dont `carrier.characterId` est un `eveCharacterId` de l'utilisateur. Entrée : `{ id, state, from, to, fromSystem, toSystem, volume, lines: { typeName, quantity }[], expectedArrivalAt, here }`, `from`/`to` = `stationName ?? solarSystemName` (`null` si les deux manquent), `volume` repris tel quel (`null` = inconnu), `here` = un personnage est dans la station de départ (`stationId ?? structureId` = `from.locationId`), `null` si positions non lues ou départ sans `locationId`. Tri : `here` d'abord, puis ordre reçu. Section « À emporter » en tête du volet À livrer (`companion-carries`, une ligne `companion-carry` par tronçon) : nom du premier type (+ « +N » si plusieurs), trajet « départ → arrivée » (`companion-carry-route`, « inconnu » pour une extrémité nulle), volume en m³ (« inconnu » si nul), étiquette d'état (en attente de départ, en route, inconnu). Bouton « Arrivé » (`companion-carry-arrive`, émet `arrive` avec l'id) seulement pour l'état `in_transit`. Tronçon `in_transit` : temps restant avant `expectedArrivalAt` (« attendu » une fois passé, rien si illisible). `CompanionTasks.carriesUnknown` = vrai quand les transferts n'ont pas pu être lus : le volet écrit « inconnu » (`companion-carries-unknown`, `data-unknown`) et n'affiche pas « rien à livrer ».
R5 **Apports** : `CompanionTasks.supplies` = lignes de la proposition de stock (état `ready`) dont `proposedQuantity > 0` et dont `bomItemId` est une ligne de la BOM : `{ item, quantity, locationName, systemName, here }` repris de `stockByStation[0]` (`here` = `onSite`, `null` sans station). Tri `here` d'abord. Volet À livrer, après les emports : « Apport · <type> » (`companion-supply`), `×quantité`, lieu du stock, badge « ici » si `here`, bouton « Apporter » (`companion-supply-apply`, émet `supply` avec `bomItemId`). Proposition absente, `not_solo` ou `assets_unknown` : aucune ligne.
R6 **Réservation d'achat** : `LineReservation.expiresAt` (déjà dans l'API, #1301). `CompanionTasks.reservationExpiries: Record<bomLineId, number>` (ms) pour la réservation `isMine` des lignes de À livrer ; date illisible : pas d'entrée. La ligne À livrer écrit « expire dans HH:MM » (`companion-reservation-expiry`, format de `countdownPartsOf`, `J j HH h` au-delà de 24 h) ; échéance atteinte : « expirée ». « Je m'en charge » et « Libérer » restent ceux de #1171 ; la purge publie `line_reserved` et la ligne repart dans À attribuer au rafraîchissement.
R7 **Demande d'adhésion** (admin, propriétaire compris) : l'alerte `join_request` dit « <nom> demande à rejoindre » quand il y a une seule demande et que son `characterName` est connu, sinon « N demandes ». Elle porte un bouton « Examiner » (émet `open-members`, l'hôte ouvre l'onglet Membres du projet). Le compagnon n'accepte jamais une demande lui-même.
R8 **Onglet À livrer** : compteur = `mine.length + carries.length + supplies.length` ; chaud (vert) si ce total > 0. Sans donnée de flux, le total vaut `mine.length` comme avant.
R9 **Arrivée** : `arrive` → `POST /industry/projects/{id}/station-transfers/{id}/arrive` (corps `{}`) puis relecture des transferts. Refus serveur (403, 409) : message dans `error`, rien d'autre ne change.
R10 **Apporter** : `supply` → `POST /industry/projects/{id}/contributions` (`type: 'material'`, `bomItemId`, `quantity` = quantité proposée), puis relecture de la BOM et de la proposition. Échec : message dans `error`.
R11 **Fraîcheur** : `open` charge les quatre lectures avec les autres. `handleEvents` relit la BOM comme avant plus les lieux (place-actions) et la proposition ; la proposition n'est lue que pour le propriétaire du projet (l'API la réserve à lui : un membre ou un admin ne la lit pas et ne voit aucune erreur) ; les transferts seulement pour l'action `station_transfer_changed` ou la reconnexion. L'hôte relit position et lieux toutes les 60 s tant que la fenêtre est ouverte (`POSITION_REFRESH_MS`), jamais hors fenêtre. Mercure : aucun nouveau topic (`/group-project/{id}/events` porte déjà `station_transfer_changed`, `line_reserved`).
R12 **Inchangé** : libellés, actions et ordre de priorité des alertes de #1171, jobs (onglet Mes jobs), barre 1024×72 et colonne 360×640 sans défilement horizontal, repli des groupes, copie du nom de blueprint.

## Exemples chiffrés (tests rouges)
| Entrée | Sortie |
|---|---|
| positions : Jita, Jita, Perimeter | `hereSystems = ['Jita', 'Perimeter']` |
| positions `[]` ou `null` | `hereSystems = null`, « Ici : inconnu » |
| 3 lignes libres A, B, C ; action de C `here: true` | ordre C, A, B ; badge sur C seul |
| 9 lignes libres dont la 8e `here` | la 8e est dans les 6 affichées (R3 avant le plafond) |
| transfert `in_transit`, `carrier.characterId` = 2 112 000 001 (le mien), départ station 60003760, un personnage y est | un emport `here: true`, bouton « Arrivé » |
| transfert `arrived`, ou porteur d'un autre joueur, ou courier (contrat) | aucun emport |
| tronçon sans `stationName` ni `solarSystemName` au départ | `from = null`, « inconnu » |
| proposition `ready`, ligne `proposedQuantity` 300, `stockByStation[0].onSite` true | un apport `×300`, badge « ici » ; `proposedQuantity` 0 : aucun |
| réservation à moi, `expiresAt` = now + 41 h | « expire dans 1 j 17 h » |
| `expiresAt` = now − 1 min | « expirée » |
| 1 demande, `characterName` « Kaelen » ; 2 demandes ; 1 demande sans nom | « Kaelen demande à rejoindre » ; « 2 demandes » ; « 1 demande » |
| 1 réservation + 2 emports + 1 apport | compteur À livrer = 4, onglet chaud |

## Cas limites
Positions illisibles (`null`) : aucun badge, aucun tri, `here` nul partout, jamais « ici » par défaut. Une ligne de la BOM absente de la liste d'actions : pas de lieu. Un type d'emport inconnu de la BOM : le nom vient du tronçon. Tronçon à plusieurs types : nom du premier + « +N ». Proposition qui référence une ligne disparue : ignorée. Nom de 80 caractères : troncature et infobulle. Les emports et les apports ne sont pas rangés par activité : les sections « À emporter » puis « Apports » restent au-dessus des groupes d'activité des réservations.

## Accès
Les endpoints sont ceux de #1300, #1295, #1166 ; la position ne sort que des personnages de l'utilisateur. Le compagnon ne déclenche que des actions que l'API contrôle déjà (arrivée : déclarant, propriétaire ou admin ; apport : propriétaire d'un projet solo).

## Tests existants contredits
Aucun comportement retiré. `companionTasksOf` (fixture) gagne les champs de R1 à R6 ; `CharacterLocationCollectionProviderTest` et `CharacterLocationApiTest` gagnent `solarSystemName`.

## Hors périmètre
Sélecteur de projet et barres de #1305 ; apport d'un membre sur son stock (#1307 suivantes, la proposition reste réservée au propriétaire solo) ; alerte verte d'emport (le prototype en montre une, elle double la section) ; accepter une demande depuis le compagnon ; réservations des autres membres visibles dans À attribuer (le prototype les montre avec « Réservé » ; l'API ne renvoie que la quantité libre) ; barre de progression des jobs.

## Décisions (mode nuit, sur recommandation)
1. Section « À emporter » unique plutôt qu'un rangement par activité (l'activité n'est pas une propriété d'un transfert). 2. Bouton « Examiner » plutôt que « Accepter » (décision d'accès : le détail du demandeur est dans l'onglet Membres). 3. `solarSystemName` ajouté à l'API plutôt qu'une résolution de nom côté front. 4. Le compagnon charge ses propres lectures (état séparé des stores de pages : l'autre page lit les mêmes endpoints sans les écraser). 5. Les apports restent ceux de la proposition de stock (propriétaire, projet solo) tant que l'ouverture aux membres n'est pas faite.

## Glossaire
Ajouts : **Emport** (Carry), **Apport depuis mon stock** (Supply from my stock), **Ici** (Here, compagnon).
