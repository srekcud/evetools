# Spec #1460 : du stock au personnage, dans l'Approvisionnement PI

Mode nuit : validée en autonomie, décisions en bas. Circuit m, un lot. Option (a) retenue par Sylvain le 2026-11-28 : **détenteur du stock** déduit des assets, sans réglage ni migration.

## Contexte

Le stock PI est en général tenu par un personnage au lieu d'origine, puis déplacé vers les autres avant un passage. L'onglet Approvisionnement donne la quantité par launchpad (grille) et le total du compte, pas ce que chaque personnage doit recevoir ni qui peut le lui donner.

## Preuves de l'issue (1b)

- Tableau par launchpad vide : pas de bug d'affichage, confirmé (`PiSupplyTab.vue` l. 239-242). Une cellule est vide seulement si le launchpad ne consomme pas le type ; sinon nombre (0 compris) ou « inconnu ». Launchpads sans entrée filtrés (`fed`) ; intermédiaires d'une chaîne sur une planète retirés des entrées par #1452 (371c7baf6). Rien à faire dans ce lot.
- Correction du brief : `PlanetaryInputNeedsCalculator::launchpadRow` ne porte **pas** `characterId`/`characterName` (seul `colonyRow` les porte). #1456 (45c08cd04) résout le nom côté front, depuis `usePlanetaryStore().characterNameByColonyId`.
- Lieu d'origine (#1394) : `User::getPlanetaryHomeLocationId()`, lu via `PlanetarySettingsResource::forUser`. Quantités par lieu racine et par personnage : `AssetBrowseRepository::quantitiesByRootLocation(AssetScope::forCharacter(...))`, conteneurs compris (même lecture que `PlanetaryStockReader`, qui somme les personnages).
- Fraîcheur des assets d'un personnage : `Character::getLastSyncAt()`, posé seulement par `AssetsSyncService::syncCharacterAssets` (sync à la demande).

## Règles

Back :
1. Chaque ligne de `launchpads[]` de `GET /api/planetary/input-needs` gagne `characterId` (`getEveCharacterId()`) et `characterName` du personnage de la colonie. Rien d'autre ne change dans ce payload.
2. Nouvelle ressource en lecture `GET /api/planetary/supply-holders` (provider dédié, chargée par l'onglet Approvisionnement seul) : `homeLocationId`, `homeLocationName`, et une entrée par personnage **du compte connecté** (`User::getCharacters()`), triée par nom : `characterId`, `characterName`, `status` (`known` | `unknown`), `unknownReason` (`missing_assets_scope` | `assets_never_read` | null), `assetsReadAt` (ISO ou null), `stale` (bool), `quantities: [{typeId, quantity}]`.
3. Types lus : les types en entrée des launchpads de l'utilisateur (`feeds[].typeId` de `PlanetaryInputNeedsCalculator::calculate`). Quantité = `quantitiesByRootLocation` du personnage au lieu d'origine ; un type absent des assets d'un personnage `known` vaut 0 et n'est pas listé.
4. Statut, dans l'ordre : pas de jeton ou jeton sans `esi-assets.read_assets.v1` → `unknown`/`missing_assets_scope` ; `lastSyncAt` null → `unknown`/`assets_never_read` ; sinon `known`. Un personnage `unknown` a `quantities: []` (jamais des zéros). `stale` = `known` et `assetsReadAt` de plus de 24 h (même seuil que l'âge de la donnée PI).
5. Lieu d'origine non réglé : `homeLocationId` null, tous les personnages renvoyés avec leur statut et `quantities: []`, aucune requête d'assets.

Front, `frontend/src/utils/planetarySupply.ts` (calcul pur, aucun recalcul de ce que le back fournit) :
6. Les lignes de `SupplyGrid.rows` gagnent `characterId` et `characterName` repris du launchpad (règle 1).
7. **Besoin d'un personnage** par type = somme des `refill.lines` de ses lignes de grille au statut `known` ; ses lignes `unknown` sont comptées en `excludedCount` du personnage (même règle que le total de la grille). Un personnage sans ligne de grille n'est pas destinataire.
8. **Transfert depuis le stock** d'un personnage par type = `max(0, besoin − ce qu'il tient lui-même au lieu d'origine)`. Inconnu si ses assets sont `unknown` ou si le lieu d'origine n'est pas réglé. Volume = Σ transfert × `volumePerUnit` du feed ; inconnu si un transfert ou un volume l'est.
9. **Détenteurs** d'un type : personnages `known` (destinataires ou non) dont le disponible `max(0, tenu − besoin propre)` est positif, triés par disponible décroissant puis nom ; les personnages `unknown` sont listés « inconnu » avec leur raison.
10. **Manque** d'un type = `max(0, Σ transferts − Σ disponibles)`. Inconnu dès qu'un transfert ou les assets d'un personnage du compte sont inconnus.
11. Aucune affectation détenteur → destinataire n'est calculée : le bloc dit quoi recevoir et qui détient.

UI, `PiSupplyTab.vue` :
12. Bloc « Du stock au personnage » au-dessus du filtre de tier et de la grille, affiché aux mêmes conditions que la grille (`showGrid`). Une carte par destinataire, triée par nom : lignes type (icône, nom, tier), transfert, volume m³, détenteurs ; total m³ ; détail repliable « Par planète et launchpad » = les lignes de grille du personnage (quantités déjà calculées).
13. Bouton « Copier en multibuy » par personnage : lignes à transfert > 0 et nom connu (`multibuyText`) ; désactivé si aucune ligne.
14. Marques : assets périmés (« lus le … »), assets inconnus (raison), lieu d'origine non réglé (lien vers les réglages du Stock, événement `open-stock` existant), manque par type, launchpads exclus. Pas de filtre de tier sur le bloc (comme la copie de la grille). Libellés FR et EN.

## Exemples chiffrés (futurs tests rouges)

Volume unitaire 0,75 m³ pour X et Y. Alpha : LP1 (X 1 000, Y 500), LP2 (X 200). Bravo : LP3 (X 400). Hold n'a aucun launchpad.

| # | Assets au lieu d'origine | Attendu |
|---|---|---|
| E1 | Hold X 3 000 Y 100 ; Alpha X 300 ; Bravo rien (`known`) | Alpha : X 900, Y 500, 1 050 m³, multibuy `X\t900` puis `Y\t500`. Bravo : X 400, 300 m³. Détenteurs X : Hold 3 000 ; Y : Hold 100. Manque X 0, Y 400 |
| E2 | E1, mais Alpha tient X 2 000 | Alpha : X 0 (absent du multibuy), Y 500. Détenteurs X : Hold 3 000, Alpha 800 |
| E3 | Hold X 3 000, Carl (sans launchpad) X 500 | Détenteurs X : Hold 3 000 puis Carl 500 |
| E4 | E1, Bravo `assets_never_read` | Bravo : besoin X 400, transfert inconnu, volume inconnu. Détenteurs X : Hold 3 000, Bravo inconnu. Manque X et Y inconnus |
| E5 | E1, Hold `missing_assets_scope` | Hold `unknown`, `quantities: []` ; détenteurs : Hold inconnu ; manques inconnus |
| E6 | E1, refill de LP2 inconnu (capacité null) | Alpha : X 700 (1 000 − 300), 1 launchpad exclu |
| E7 | Lieu d'origine non réglé | Besoins affichés, transferts et volumes inconnus, message avec lien vers les réglages |
| E8 | E1, assets de Hold lus il y a 30 h | Quantités utilisées, Hold marqué périmé avec la date |
| E9 | Back : personnage d'un autre utilisateur avec X au même lieu | Absent de `supply-holders` |
| E10 | Back : Hold, X 1 000 dans un conteneur à la racine du lieu d'origine, 200 dans une autre station | `quantities` X = 1 000 |

## Cas limites

- Jours invalides : bloc masqué comme la grille. Changer les jours recalcule côté front, sans nouvel appel.
- Personnage destinataire et détenteur à la fois : son propre stock couvre d'abord son besoin (règle 8), seul le reste est disponible (règle 9).
- Type sans nom : exclu du multibuy, signalé comme dans la grille (`pi.supply.unnamed`).
- Échec de `supply-holders` : bannière d'erreur du bloc, la grille reste affichée.
- Aucun launchpad alimenté : ni bloc ni appel utile (état vide existant).

## Accès et fraîcheur

- Aucun identifiant reçu du client : personnages = `User::getCharacters()`, lieu d'origine = réglage de l'utilisateur. Un personnage retiré du compte disparaît ; l'appartenance à une corporation n'intervient pas.
- Assets lus en base (sync à la demande) : `assetsReadAt` affiché, périmé au-delà de 24 h ; aucun déclenchement de sync dans ce lot.
- Jeton révoqué ou scope assets absent : `unknown`, même si des assets anciens restent en base.

## Tests existants touchés

- `frontend/tests/planetarySupplyFixtures.ts` : `launchpadOf` gagne `characterId`/`characterName` (type `SupplyLaunchpad`).
- `PiSupplyTab.spec.ts`, `piSupplyCharacter.spec.ts`, `piSupplyHomeShortfall.spec.ts` : `supply-holders` non mocké reste en attente (promesse jamais résolue), aucune assertion contredite si le bloc a ses propres `data-testid`.
- PHP : aucune assertion de forme exacte sur `launchpads[]` (`PlanetaryInputNeedsCalculatorTest`, `PlanetaryInputNeedsApiTest`).

## Hors périmètre

- Affectation d'un détenteur précis à chaque destinataire, contrats ou courriers entre personnages.
- Stock des hangars de corporation au lieu d'origine (compté par l'onglet Stock, détenu par personne).
- Déduction des ramassages en colonie (« À ramasser ») du transfert.
- Réglage explicite du détenteur (option b), sync d'assets depuis le bloc, persistance d'état.

## Glossaire

Ajoutés : **Détenteur du stock** (Stock holder), **Transfert depuis le stock** (Transfer from stock).

## Décisions prises en autonomie

- Nouvelle ressource plutôt qu'extension d'`input-needs` : ce payload est aussi chargé par Colonies, la lecture d'assets par personnage n'y a pas sa place.
- Personnage de la ligne de launchpad fourni par le back (règle 1) ; le lien #1456 par `colonyId` reste en place pour les lignes existantes.
- Pas d'affectation détenteur → destinataire : liste des détenteurs et manque par type.
- Assets inconnus = transfert et manque inconnus ; seuil de péremption 24 h.
- Corp exclue, ramassages non déduits, bloc non filtré par tier.
