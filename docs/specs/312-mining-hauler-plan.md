# Spec #312 — Minage groupé : planning du hauler (remplissage du fleet hangar)

- **Statut** : rédigée en autonomie (mode nuit, reliquat) le 2026-11-10, à valider par Sylvain avant fusion.
- **Circuit** : `circuit:m`, sans note de câblage. **Dépend de** : #266 (fleet storage), #311 (coque, compresseurs), #267 et #305 (sites, scans, tendance). Toutes dans `origin/main` 775f52cf.
- **Maquette** : `mockups/grouped-mining-v4.html` (branche `docs/mining-specs`) : `haulerPlan()`, `hangarBar()`, `renderHaulerCard()`, bloc `cmp-hauler` du compagnon.
- **Périmètre interdit** : Industry, Group Industry, Stockpile.

## 1. Contexte

Le pilote de la coque de boost reçoit le minerai des mineurs dans son fleet hangar. Il doit savoir quand le vider et combien d'allers-retours prévoir avant la fin du site. evetools ne lit pas le hangar en direct (assets en cache) : le remplissage est une **estimation**, l'écran le dit.

Constat dans le code (lu le 2026-11-10) :

| Élément | Source |
|---|---|
| Débit par type, en unités par minute | `MiningSiteScan::getTypes()` du dernier scan du site : `trendRate` (null si inconnu), `status`, `typeId` ; ETA du site : `MiningSiteScan::getTrendEta()` |
| Site de l'opération | `MiningSiteRepository::findActiveOfOperation()` (tri par création) : le dernier est le site courant |
| Verdict de compression par type | `MiningOperationCompressionReport::of($operation, $typeIds)` (`types[].compressible`) |
| Destinataire, déclarations | `MiningOperation::getFleetStorageRecipient()`, `MiningOperationMember` (`fleetStorageDelivery`, `fleetStorageQuantities`) |
| Quantité minée en cours d'opération | **absente** : `MinedQuantityCalculator` reste `measuring` jusqu'à l'instantané de fin. Voir R5 |
| Capacités de la coque | SDE `sde_dgm_type_attributes` : 912 `fleetHangarCapacity` (Porpoise 5 000, Orca 40 000, Rorqual 40 000), 1556 `generalMiningHoldCapacity` (50 000, 150 000, 300 000), vérifiés en base |

## 2. Modèle

Une migration : `mining_operations` + `fleet_hangar_emptied_at` datetime_immutable nullable (lignes existantes : `null`). Aucun enum de plus en base. Enum `MiningHaulerPlanUnknownReason` (`src/Enum/`, backed string) : `operation_not_running`, `no_boost_ship`, `no_fleet_storage_recipient`, `no_active_site`, `site_rate_unknown`, `ledger_unknown`, `nothing_delivered`, `capacity_missing_from_sde`, `volume_missing`, `compression_unknown`.

## 3. Règles métier

- **R1 — Applicable.** Le planning n'existe que pour une opération qui n'est pas en mode `each_for_themselves`. Dans ce mode, la lecture répond `404` « An each-for-themselves operation has no hauler plan » et l'écran masque la carte et la barre.
- **R2 — Origine du remplissage.** `origine = fleetHangarEmptiedAt` s'il existe, sinon `startedAt` de l'opération (hangar supposé vide au démarrage, dit à l'écran). Le temps écoulé `Δ = max(0, maintenant − origine)` en minutes. Le bouton « Hangar vidé » (R9) pose `fleetHangarEmptiedAt = maintenant`.
- **R3 — Capacités.** `capacité du hangar` (attribut 912) et `capacité de la soute` (attribut 1556) de la coque de l'opération, lues dans le SDE, **sans bonus de compétences** (dit à l'écran). Attribut absent du SDE : `capacity_missing_from_sde`, jamais un défaut.
- **R4 — Débit du site.** Pour chaque type du dernier scan du site courant avec `status = present` et `trendRate > 0` : débit en unités par minute. Aucun type dans ce cas (un seul scan, quantités stables) : `site_rate_unknown`. Les types épuisés ou sans débit sont ignorés.
- **R5 — Part versée au fleet storage** `part = unités versées ÷ unités minées`, sur la quantité minée depuis le début de l'opération. Quantité minée en cours d'opération d'un personnage inscrit = Σ des `MiningEntry` du personnage (système de l'opération, date ≥ jour du démarrage) − lignes de son instantané de début (même date, type, système). Aucun appel ESI de plus : le ledger lu est celui de la sync horaire, donc la part peut retarder d'une heure. Par participant `accepted` hors destinataire : `none` verse 0 ; `all` verse tout ce qu'il a miné ; `partial` verse Σ par type de `min(quantité déclarée, quantité minée)`. Le destinataire compte dans le total, jamais dans le versé (R3 de #266). Un personnage dont l'instantané de début n'est pas `taken` : `ledger_unknown` pour tout le plan. Total miné nul : `ledger_unknown`. Versé nul : `nothing_delivered`.
- **R6 — Remplissage.** Pour chaque type du débit : `volume reçu = débit × part × volume brut d'une unité` (SDE `volume` du type brut). `entrée = Σ` (m³/min). `remplissage = min(capacité, Δ × entrée)`, `ratio = remplissage ÷ capacité`, `plein dans = (capacité − remplissage) ÷ entrée` minutes (0 si plein, `isFull`). Volume brut absent du SDE : `volume_missing`.
- **R7 — À transporter.** Volume d'une unité transportée : variante compressée (SDE `volume` du `compressedTypeId`, une unité compressée = une unité brute, #259) si le verdict de compression du type est `compressible = true`, brut si `false`. Verdict `null` (genre inconnu, pas de coque) : `compression_unknown`. `transport = Σ débit × part × volume transporté` (m³/min). `déjà dans le hangar = Δ × transport`. `à déplacer = déjà dans le hangar + max(0, ETA de tendance du site − maintenant, en minutes) × transport`. `allers-retours = max(1, ⌈à déplacer ÷ capacité de la soute⌉)`. ETA de tendance du site inconnue : `à déplacer` et `allers-retours` valent `null` (l'écran dit « inconnus »), la barre reste affichée. `types bruts` = types du débit non compressibles.
- **R8 — Ordre des inconnus** (premier motif rencontré) : `operation_not_running` (statut autre que `in_progress`), `no_boost_ship`, `no_fleet_storage_recipient`, `no_active_site`, `site_rate_unknown`, `ledger_unknown`, `nothing_delivered`, `capacity_missing_from_sde`, `volume_missing`, `compression_unknown`. Un plan inconnu garde coque, destinataire, origine et motif ; aucune valeur de repli.
- **R9 — Hangar vidé.** `POST /api/mining-operations/{operationId}/hauler-plan/emptied` (`input: EmptyInput`) : destinataire du fleet storage, Owner et Admins `accepted`. `400` « The operation is not running » hors `in_progress`. `403` « Only the fleet storage recipient, the operation owner or an admin can declare the hangar emptied » pour un autre participant ; sans accès : `403` « You do not have access to this operation ». Mode `each_for_themselves` : `404` (R1). Réponse `200`, le planning (R10).
- **R10 — Lecture.** `GET /api/mining-operations/{operationId}/hauler-plan` (accès participation, contrôle existant) : `operationId`, `state` (`known` | `unknown`), `unknownReason`, `boostShipName`, `haulerName` (destinataire), `emptiedAt`, `originIsStart` (true sans déclaration), `capacityM3`, `holdCapacityM3`, `share` (0 à 1), `inflowM3PerMinute`, `fillM3`, `fillRatio`, `fullInMinutes`, `isFull`, `transportM3PerMinute`, `toMoveM3`, `trips`, `siteEtaAt`, `rawTypeIds`, `contributorNames`. Champs de calcul `null` si `state = unknown`.
- **R11 — Temps réel.** `POST` R9 publie l'action `fleet_hangar_emptied` sur `/user/{id}/mining-operations` (audience existante `MiningOperationAudience::participantIdsOf`), via `publishMiningOperationEvent`. Aucun nouveau topic. Le front recharge aussi à `site_scan_recorded` et toutes les 60 s.

## 4. Exemples chiffrés (planificateur pur, R6 à R7)

Coque Orca (hangar 40 000, soute 150 000). Un type A : débit 1 000 u/min, volume brut 10 m³, compressé 0,1 m³. `part` 0,5.

| # | Cas | Attendu |
|---|---|---|
| E1 | A compressible, vidé il y a 4 min, ETA du site dans 60 min | entrée 5 000 m³/min ; remplissage 20 000 (0,5) ; plein dans 4 min ; transport 50 m³/min ; déjà 200 ; à déplacer 3 200 ; 1 aller-retour |
| E2 | A brut (`compressible = false`), mêmes temps | transport 5 000 ; déjà 20 000 ; à déplacer 320 000 ; 3 allers-retours ; `rawTypeIds` = [A] |
| E3 | vidé il y a 10 min | remplissage 40 000 (plafond), ratio 1, plein dans 0, `isFull` |
| E4 | ETA du site inconnue | `toMoveM3` et `trips` `null`, barre affichée |
| E5 | ETA du site déjà passée | à déplacer = déjà dans le hangar seul |
| E6 | verdict de compression `null` | `compression_unknown` |
| E7 | débit nul sur tous les types | `site_rate_unknown` |

Part (R5) : A `all` a miné 6 000 ; B `partial` {t : 1 000} a miné 3 000 de t ; C `none` a miné 1 000 ; le destinataire a miné 2 000. Total 12 000, versé 7 000, `part` = 7/12. B a déclaré 5 000 de t pour 3 000 minés : versé 3 000. C seul déposant `none` : `nothing_delivered`. Instantané de début d'un personnage en `failed` : `ledger_unknown`.

## 5. Front (HUD EVE, maquette retenue)

- **Page de l'opération** (`MiningOperationDetail`, opération `in_progress`, hors chacun pour soi) : carte « Hauler » : barre de remplissage colorée (rouge sous 3 min, ambre sous 8 min, cyan sinon), « n % · plein dans X min » ou « plein : à vider », ligne « ≈ N allers-retours avant HH:MM · M m³ dont brut : … », ligne de calcul (débit × part = entrée), bouton « Hangar vidé » (si R9 l'autorise), phrase « Estimation : evetools ne lit pas le hangar en direct ». Plan inconnu : « Fleet hangar de {hauler} : inconnu » + motif traduit.
- **Compagnon** (taille compacte et détail, site lié à une opération hors chacun pour soi) : même barre en version courte ; en détail, débit et heure du vidage + bouton « Hangar vidé ».
- Libellés i18n `en` et `fr`. Pas de commentaire dans le code.

## 6. Accès, données, prod

- Lecture : participants. Écriture : R9. Aucun identifiant reçu d'un autre périmètre ; `contributorNames` = noms de participants de la même opération. Aucune donnée de corporation lue.
- Action prod : migration (`make db-migrate`). Aucun seed, aucune commande console.

## 7. Décisions prises en autonomie

1. Option A du commentaire de l'issue (attendre #267 et #305) : levée, ces lots sont dans main.
2. Part versée mesurée sur le ledger synchronisé (R5) : le ledger ESI n'offre pas de lecture en cours d'opération, mais les `MiningEntry` du ledger sync horaire y suppléent sans appel de plus. La maquette lisait un « ledger lu à HH:MM ».
3. Origine par défaut = démarrage de l'opération (la maquette posait « vidé il y a 4 min » comme valeur de démo).
4. Remplissage au volume brut, transport au volume compressé : lecture de la maquette ; le texte de l'issue (« volume compressé pour les types compressibles, brut sinon ») s'applique au volume à transporter.

## 8. Hors périmètre

Bonus de compétences, lecture du hangar par les assets du personnage, portée, plusieurs sites actifs (le dernier créé fait foi), alerte « hangar plein » (notification), saison.
