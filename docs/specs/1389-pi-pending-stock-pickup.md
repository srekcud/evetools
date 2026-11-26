# Spec #1389 (étape A) — Stocks en attente par colonie, « À ramasser » et « À rapporter »

Mode nuit : validée en autonomie. Empilée sur #1388 (PR #1417). Décisions de Sylvain (2026-11-26) : option A en deux étapes ; la production d'un extracteur suit les routes de la colonie (launchpad, storage ou usine). Cette étape n'inclut ni lieu maison (#1394) ni manque ni lien Stock (#1395). Lecture seule : aucune migration, aucune sync, aucun appel ESI, aucune action async (donc pas de Mercure).

## But

Le joueur ne voit pas ce qui l'attend sur ses colonies : P1 d'extraction, sorties d'usine qui alimentent d'autres planètes, produits finis. Le backend projette le contenu des launchpads et des storage facilities ; l'onglet Approvisionnement affiche ce qu'il y a à ramasser (source interne du plein) et à rapporter.

## Ressource

`GET /api/planetary/pending-stock`, `PlanetaryPendingStockResource` (id fixe `pending-stock`, modèle `PlanetaryInputNeedsResource`), `ROLE_USER`, provider `PlanetaryPendingStockProvider` (`findByUser` seulement), calcul dans `App\Service\Planetary\PlanetaryPendingStockCalculator` (constructeur indépendant : `PlanetaryInputNeedsCalculator` et ses tests ne changent pas). Champs : `storages`, `pendingOutputs`, `complete`.

## Règles backend

1. **Pin de stockage** : groupe SDE « Spaceports » (1030, `kind` = `launchpad`) ou « Storage Facilities » (1029, `kind` = `storage`), constantes nommées, jamais d'id de type en dur. Command center, usines, extracteurs : exclus.
2. **`storages[]`** (tous les pins de stockage, même sans route) : `colonyId`, `planetId`, `planetName`, `solarSystemName`, `characterId`, `characterName`, `pinId`, `typeId`, `typeName`, `kind`, `capacityM3` (SDE, null si absente), `usedVolumeM3` (Σ projeté × volume, null si un volume ou une projection est inconnu), `lastViewAt`, `dataAgeSeconds`, `dataStale` (mêmes règles que #1382), `contents[]` : `typeId`, `typeName`, `stock` (mesuré), `projectedStock` (null si inconnu), `volumePerUnit` (null si absent de la SDE). Une ligne par type présent dans `contents` ou alimenté par une route entrante.
3. **Projection** (âge `a` en jours depuis `PlanetaryColony::getLastUpdate()`, horloge `ClockInterface`) : `projectedStock = max(0, stock − sortie × a) + entrée`.
   - **Sortie** : une route partant du pin, de type `t`, vers un pin usine dont le schéma consomme `t` : besoin quotidien de l'usine pour `t` (à plein régime, #1380 règle 1), réparti au prorata de `PlanetaryRoute::getQuantity()` entre les routes de ce type qui arrivent à cette usine (poids, jamais unité). Les routes vers un pin non usine (transferts) ne bougent pas le stock (hors périmètre).
   - **Entrée** : une route arrivant au pin depuis un extracteur ou une usine. Un extracteur répartit sa production du type de l'extracteur (`ExtractorYieldCalculator::yieldBetween()` de `lastUpdate` à `now`, déjà borné à l'expiration) entre ses routes de ce type, au prorata de `getQuantity()`. Une usine répartit `quantité de sortie × 86400 / cycleTime × a` (plein régime) entre ses routes portant son type de sortie, de la même façon. Une route de type différent de la production de sa source est ignorée.
   - **Capacité** : si les entrées font dépasser `capacityM3`, elles sont réduites proportionnellement pour que le total tienne (`room = max(0, capacité − volume de base)` ; `scale = min(1, room / volume des entrées)`).
   - **Inconnu reste inconnu** : un `yieldBetween` null, ou une capacité / un volume manquant alors qu'une entrée est à plafonner, rend `projectedStock` null pour les types concernés, jamais 0 ni la valeur mesurée. Un pin usine au schéma absent de la SDE (ou sans cycle) rend `complete` false, ses entrées et sorties ne sont pas devinées.
4. **Sortie en attente** (`pendingOutputs[]`, une ligne par couple colonie × type) : un type produit par la colonie (type de sortie d'une usine ou produit d'un extracteur) et consommé par aucune usine de cette même colonie ; quantité = Σ `projectedStock` de ce type sur ses pins de stockage ; lignes à quantité 0 omises, quantité null conservée (inconnu).
   Champs : `colonyId`, `planetId`, `planetName`, `solarSystemName`, `characterId`, `characterName`, `typeId`, `typeName`, `quantity` (null si inconnu), `volumePerUnit`, `volumeM3` (null si l'un des deux est null), `pinIds`, `consumers[]` (`colonyId`, `planetName` des **autres** colonies du joueur qui ont une usine dont le schéma consomme ce type, triées par nom), `destination` (`colony` si `consumers` n'est pas vide, sinon `home`), + `lastViewAt`, `dataAgeSeconds`, `dataStale`.
5. **Tri** : `storages` par `planetName` puis `pinId` ; `pendingOutputs` par `typeId` puis `planetName` ; `contents` par `typeId`.
6. **Noms** : `TypeNameResolver::findName()`, null reste null. Un seul `InvTypeRepository::findByTypeIds()` pour la SDE (pins, contenus, routes, schémas).
7. **Accès** : 401 sans jeton ; un joueur ne voit que ses colonies (corporation et autres joueurs jamais lus).

## Exemples chiffrés

| Cas | Entrée | Sortie |
|---|---|---|
| Sortie d'usine à plein régime | usine P3 sort 1 /h, route vers un launchpad, vue il y a 48 h, stock 0 | stock projeté 48 |
| Extracteur vers storage | 211 200 par programme, `yieldBetween` = 9 600 depuis la vue, route extracteur → storage | stock projeté 9 600 |
| Deux routes du même type | quantités 1 800 et 600 depuis une usine qui sort 100 projetés | 75 et 25 |
| Consommation | launchpad stock 5 000, route vers une usine à 960/j, vue il y a 2 j | projeté 3 080, sans entrée |
| Plancher | consommation 960/j, vue il y a 10 j, stock 5 000 | projeté 0 |
| Plafond | storage 12 000 m3 déjà plein à 11 000 m3, entrée de 2 000 m3 | entrée réduite à 1 000 m3 |
| Capacité inconnue | entrée à plafonner, `capacityM3` null | `projectedStock` null |
| Sortie consommée sur place | P2 sorti et consommé par une usine de la même colonie | absent de `pendingOutputs` |
| Destination | P3 attendu sur la colonie A, une usine P4 sur la colonie B le consomme | `destination` colony, `consumers` = B |
| Sans consommateur | P4 fini | `destination` home, `consumers` vide |
| Quantité 0 | stock projeté 0 | ligne omise |
| Extracteur sans cycle | `extractorCycleTime` null | quantité null |

## Règles front (onglet Approvisionnement, sous la grille)

8. **Store** `planetaryPendingStock` (modèle `stores/planetarySupply.ts`) : charge `/planetary/pending-stock` à l'ouverture de l'onglet et à chaque changement de `planetaryStore.lastSyncAt`. État : données, `isLoading`, `error`. Une erreur s'affiche dans un `ErrorBanner` propre aux deux blocs ; la grille de #1388 reste utilisable.
9. **« À ramasser »** (`pi.supply.pickup.*`) : pour chaque type présent dans les totaux de la grille de #1388 (`aggregate` de `planetarySupply.ts`, quantité à apporter > 0), la quantité en attente chez les colonies (`pendingOutputs` de destination `colony` dont `consumers` est non vide ; somme des `quantity` non null) ; `à ramasser = min(à apporter, en attente)` ; `reste = à apporter − à ramasser`. Une colonne liste les colonies sources (planète, quantité, m3). Une quantité d'une source à null : source marquée « inconnu », exclue de la somme, message sous le bloc. Les types sans stock en attente sont affichés avec 0 ramassé. Aucune colonne maison, manque ni prix (#1394, #1395).
10. **« À rapporter »** : toutes les lignes de `pendingOutputs` (destination `colony` ou `home`) : planète, produit (icône SDE), quantité, m3, destination (« vers <planètes consommatrices> » ou « à placer : aucune de tes colonies ne le consomme », sans le mot « maison »). Triée par destination (colony d'abord) puis planète. Quantité inconnue : cellule « inconnu ».
11. **États** : chargement (spinner), erreur (banner), aucune sortie en attente (message vide explicite), `complete === false` (avertissement). Données périmées (`dataStale`) : cellule en orange avec l'âge en heures.
12. **i18n** FR et EN, parité vérifiée par le test de clés de #1388.
13. **Glossaire** : « Sortie en attente » / « Pending output », « Stock de stockage PI » / « PI storage stock ».

## Cas limites

- Colonie sans usine : ses extracteurs et usines (aucune) → sorties d'extracteur (type d'extracteur) en attente si aucune usine locale ne les consomme.
- Plusieurs personnages : toutes les colonies du joueur.
- `contents` null : stock 0, jamais une erreur.
- `bigint` : `pinId`, `sourcePinId`, `destinationPinId` castés `(int)` aux deux bouts des index.

## Hors périmètre

- Lieu maison, manque, lien vers l'onglet Stock (#1394, #1395) ; deuxième étape de #1389, l'issue reste ouverte.
- Transferts entre stockages, taux de marche réels des usines (plein régime, comme #1380), rendement par route.
- Stock en customs office (#1390). Alertes de stockage plein (#1391).

## Décisions prises en autonomie

- Une ressource dédiée plutôt que d'étendre `/planetary/input-needs` : contrat distinct (les consommateurs de `input-needs` ne changent pas).
- La logique de répartition des routes est dupliquée en partie avec `PlanetaryInputNeedsCalculator` pour ne pas changer son constructeur ni ses tests ; extraction commune à prévoir quand #1390 touchera ces classes.
- Les sorties d'usine sont projetées à plein régime : sans les entrées de l'usine, elles sont surestimées ; la donnée est marquée périmée au-delà de 24 h.
- « Sortie en attente » = produit de la colonie non consommé par une de ses usines : un intermédiaire consommé sur place n'est jamais proposé au ramassage.

## Tests

- Unit `PlanetaryPendingStockCalculatorTest` : un cas par règle 1 à 6 et le tableau ci-dessus, plus une garde (le `PlanetaryInputNeedsCalculator` inchangé).
- Fonctionnel API : 401 sans jeton ; 200 et portée utilisateur.
- vitest : util `planetaryPickup` (règles 9 et 10), store, composant (états, inconnu, périmé), parité i18n.

## Note de câblage

Backend : créer `PlanetaryPendingStockCalculator`, `PlanetaryPendingStockProvider`, `PlanetaryPendingStockResource` (modèle `PlanetaryInputNeeds*`). Dépendances du calculateur : `PlanetSchematicRepository`, `PlanetSchematicTypeRepository`, `InvTypeRepository`, `TypeNameResolver`, `ClockInterface`, `ExtractorYieldCalculator`. Deptrac : la ressource ne référence pas de classe `Service`.
Front : créer `utils/planetaryPickup.ts`, `stores/planetaryPendingStock.ts`, `components/planetary/PiSupplyPendingBlocks.vue` monté dans `PiSupplyTab.vue` sous la grille ; types de la réponse dans l'util. Tests dans `frontend/tests/{utils,stores,components/planetary}/`.
