# #1286 — Courier hub in import and export costs

Circuit annoncé `s`, traité en `m` (migration, montants, plus de cinq fichiers de production). Mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype.

## Contexte

Les couriers de la corporation vont toujours de Jita à la capitale (hub de courier), puis les marchandises descendent à l'atelier par pont (Ansiblex). Aujourd'hui l'import d'un projet vaut `volume × sauts(Jita → atelier) × 150 ISK` (`JitaPurchase::importCostOf`), le devis courier vise l'atelier (`CourierQuoteCalculator`), et le scanner de rentabilité applique un tarif unique `exportCostPerM3` Jita ↔ site.

## Règles

R1. Deux réglages sur `IndustryUserSettings` (tarif Jita vers hub inchangé : `exportCostPerM3`) :
- `courierHubSolarSystemId` (int, défaut `EveConstants::DEFAULT_COURIER_HUB_SOLAR_SYSTEM_ID` = 30000772, C-J6MT) ;
- `jumpBridgeCostPerM3` (float ≥ 0, défaut `EveConstants::DEFAULT_JUMP_BRIDGE_COST_PER_M3` = 0.0 : les courriers du hub à l'atelier sont des contrats privés, sans tarif).
Colonnes `courier_hub_solar_system_id` et `jump_bridge_cost_per_m3` avec valeur par défaut en base : les lignes existantes prennent les défauts. Le tarif Jita → hub est `exportCostPerM3` : son défaut (`EveConstants::DEFAULT_EXPORT_COST_PER_M3`, colonne `export_cost_per_m3`) passe de 1 200 à 800 ISK/m³, et la migration remet toutes les lignes existantes à 800 (décision de Sylvain, aucun autre export n'est prévu). `down()` remet le DEFAULT à 1 200 mais ne restaure pas les valeurs saisies. Migration réversible.

R2. Exposés par `GET/PATCH /industry/settings` : `courierHubSolarSystemId`, `courierHubSolarSystemName` (lu dans le SDE, comme les systèmes favoris), `jumpBridgeCostPerM3`. Le PATCH refuse (422) un système absent du SDE (même contrôle que les systèmes favoris), un identifiant ≤ 0, un tarif négatif. Utilisateur sans ligne de réglages : GET renvoie les défauts.

R3. Nouveau VO `CourierHub` (Application) : `solarSystemId`, `iskPerCubicMeterFromJita`, `jumpBridgeIskPerCubicMeter`. Méthode `transportCostOf(float $cubicMeters, ?int $siteSolarSystemId): Cost` :
- site inconnu (`null`) : `Cost::unknown(MissingData::jumpRoute())`, jamais 0 ;
- site = Jita (30000142) : coût connu 0 ;
- site = hub : `m³ × iskPerCubicMeterFromJita` ;
- autre site : `m³ × (iskPerCubicMeterFromJita + jumpBridgeIskPerCubicMeter)`.
Même formule dans les deux sens (aller Jita → hub → site, retour site → hub → Jita).

R4. `FavoriteSystems` porte un troisième champ optionnel `?CourierHub $courierHub = null` (les `new FavoriteSystems(a, b)` existants restent valides). `IndustryProjectService::favoriteSystemsOf` le remplit toujours : depuis les réglages, ou depuis les défauts de R1 si l'utilisateur n'a pas de ligne. `FavoriteSystems::none()` garde `null`.

R5. Import d'un projet : `JitaPurchase::importCostOf(typeId, quantity, volumePerUnit, ?int $destinationSolarSystemId, ?CourierHub $hub)` remplace le paramètre `$jumps`. Volume manquant : `MissingData::itemVolume($typeId)` ; hub `null` : nouveau `MissingData::courierHub()` ; destination inconnue : `jumpRoute`. Plusieurs causes : toutes listées. `MaterialSourcingComparator` et `IntermediateBuyOrBuildComparator` passent le système favori de l'activité et `$favoriteSystems->courierHub`. Les champs `jumps` de `MaterialSourcing` et `IntermediateComparison` restent (affichage, `JumpDistances` Jita → atelier inchangé) ; l'import n'en dépend plus. `ProjectMaterialSourcingResource` et `ProjectBuyOrBuildResource` exposent en plus `courierIskPerCubicMeter`, `jumpBridgeIskPerCubicMeter`, `courierHubSolarSystemName` (null sans hub).

R6. Devis courier : le courier va de Jita au hub, pas à l'atelier. `CourierQuoteCalculator::quote` reçoit le hub et le nombre de sauts Jita → hub ; récompense = `round(volume × iskPerCubicMeterFromJita)` ; `jumps` = sauts Jita → hub. Les statuts existants restent (`no_purchases`, `volume_unknown`, `workshop_unknown` tant que l'atelier n'est pas défini, `route_unknown` si les sauts Jita → hub manquent). `CourierOverview` expose le hub (id et nom) comme destination du courier ; le nom de l'atelier reste fourni. Le contrat réel reste apparié par volume et récompense (`CourierContractReader::matchesQuote` inchangé).

R7. Scanner de rentabilité (`BatchProfitScannerService::scan`) : le paramètre `float $exportCostPerM3` devient `CourierHub $courierHub`. Coût d'import = `courierHub->transportCostOf(volume matières, site de production)` ; coût d'export (vente hors structure et hors contrats) = `transportCostOf(volume produit, site de production)`. Site = système de fabrication, ou système de réactions pour une réaction. Les deux restent `null` quand le volume est inconnu ; les deux tarifs à 0 donnent 0 même sans volume (comportement actuel de `transportCost`). `BatchScanProvider` et `ProductionOpportunities` construisent le hub depuis les réglages (ou les défauts).

R8. Front : `FeesConfig.vue` ajoute le champ « tarif du pont (ISK/m³) » ; le hub se choisit avec le sélecteur de système de `FavoriteSystemsConfig` (même composant, un troisième sélecteur) ; `useFavoriteSystems` et les types `userSettings` suivent. Les libellés `importRate` (carte des sources d'achat et achat ou fabrication) affichent le tarif Jita → hub et le tarif du pont à la place du tarif par saut ; `importDetail` affiche `{volume} m³`. Clés FR et EN. Le panneau courrier affiche le hub comme destination.

## Exemples chiffrés (tests rouges)

Réglages (valeurs de test, pas les défauts) : `exportCostPerM3` = 1 200, pont = 50, hub = C-J6MT (30000772), Jita = 30000142.

| Cas | Volume (m³) | Site | Coût attendu |
|---|---|---|---|
| Site = hub | 100 | 30000772 | 120 000 |
| Site ≠ hub | 100 | 0UBC-R (id quelconque) | 100 × (1 200 + 50) = 125 000 |
| Site = Jita | 100 | 30000142 | 0 |
| Site inconnu | 100 | null | inconnu (`jump_route`) |
| Pont à 0 (défaut) | 100 | autre | 120 000 |
| Volume 0 | 0 | autre | 0 |
| Import projet, 10 u × 2,5 m³ | 25 | atelier ≠ hub | 31 250 |
| Import projet, volume SDE manquant | n/a | atelier ≠ hub | inconnu (`item_volume`) |
| Import projet, hub `null` | 25 | atelier ≠ hub | inconnu (`courier_hub`) |
| Import projet, hub `null` et volume manquant | n/a | atelier | inconnu, deux causes |
| Devis courier 25 m³, hub à 4 sauts de Jita | 25 | hub | récompense 30 000, `jumps` 4, statut `ready` |
| Devis, sauts Jita → hub inconnus | 25 | hub | statut `route_unknown` |
| Scanner, matières 40 m³ + produit 10 m³, site ≠ hub | 40 / 10 | fabrication | import 50 000, export 12 500 |
| Scanner, site = hub | 40 / 10 | fabrication | import 48 000, export 12 000 |
| Scanner, réaction, site ≠ hub | 40 / 10 | système de réactions | même formule, site = réactions |

## Cas limites

- Atelier non défini : import inconnu (`jump_route`), comme aujourd'hui ; le devis reste `workshop_unknown`.
- Utilisateur sans ligne de réglages : défauts de R1, import calculable dès qu'un atelier est défini.
- `sellVenue` structure ou contrats : pas d'export (inchangé).
- Hub = atelier : aucun pont, un seul tarif.

## Accès et fraîcheur

Réglages de l'utilisateur connecté uniquement (`UserSettingsProvider` et processor lisent `findOneBy(['user' => $user])`) ; le projet lit les réglages de son propriétaire (`favoriteSystemsOf($owner)`), comme les systèmes favoris. Aucune nouvelle ressource, aucun nouvel identifiant reçu d'un autre périmètre.

## Tests existants modifiés par la spec

Tout test qui attend un import `volume × sauts × 150` (`MaterialSourcingComparator`, `IntermediateBuyOrBuildComparator`, `JitaPurchase`), un devis `volume × sauts × 150` (`CourierQuoteCalculator`, `CourierOverviewBuilder`), l'ancienne signature `scan(... float $exportCostPerM3 ...)`, ou les résumés `UserSettingsResource` : valeur ou signature mise à jour dans la phase rouge.

## Hors périmètre

- `StructureTotalCostComparator::TRANSPORT_ISK_PER_CUBIC_METER_JUMP` (transport entre structures candidates) et le glossaire « Transport (d'une structure) » : inchangés.
- Liste d'achats (`ParseProcessor`, tarif saisi par requête).
- Coût d'import par ligne multi-marché (#1287), transfert entre stations (issues du groupe `flow-location`).
- Réglage au niveau de la corporation.

## Décisions

- Réglages sur l'utilisateur (le tarif `exportCostPerM3` y est déjà) ; la corporation n'a pas de table de réglages Industrie.
- Hub par système (le pont se mesure en système) ; défaut 30000772 lu dans `sde_map_solar_systems`.
- Défaut du pont : 0 ISK/m³, décision de Sylvain (contrats privés de la capitale à l'atelier) ; le réglage reste pour qui veut le renseigner.
- Défaut du tarif Jita → hub : 800 ISK/m³ pour tous, lignes existantes comprises (décision de Sylvain).
- Le hub passe par `FavoriteSystems` pour éviter de changer la signature de cinq fournisseurs et de leurs tests.
- Les champs `importIskPerCubicMeterJump` des ressources restent exposés (aucun retrait sans accord) : ils décrivent le barème par saut du transport entre structures.

## Glossaire

Ajouts : **Hub de courier**, **Tarif du pont**. Modifiés : **Import** (hub puis pont), **Contrat de courrier** (destination = hub, récompense = m³ × tarif Jita → hub).
