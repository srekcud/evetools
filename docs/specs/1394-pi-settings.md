# Spec #1394 : réglages PI (lieu maison, hub, couverture visée)

Mode nuit : validée en autonomie, décisions en bas. Décision de Sylvain (commentaire de l'issue, 2026-11-26) : **un seul lieu maison pour tout le compte**, pas de maison par région. Maquette locale non versionnée : bloc `params` de l'onglet Stock (« Stock maison », « Hub », « Couverture visée … semaines »). Ces réglages sont consommés par #1395 (couverture du stock), #1396, #1397, #1398 ; aucun calcul ici.

## Contexte

- Le hub est le marché préféré existant : `User::getEffectiveMarketStructureId()` (préféré, sinon premier des `marketStructures`), repli sur `app.default_market_structure_id` (C-J6MT, `config/services.yaml:9`). Il s'écrit déjà par `PATCH /me/settings` (`UserSettingsResource`). Aucun second champ de hub n'est créé.
- Le lieu maison et la couverture visée n'existent pas. Il n'y a pas d'onglet Stock sur main : cette issue crée l'onglet (coquille) avec le panneau de réglages ; #1395 le remplit.
- Il n'existe pas de recherche de station (la recherche existante ne couvre que les structures, par ESI). Le lieu maison se choisit parmi les lieux racines où un personnage du compte a des assets personnels (`AssetBrowseRepository::summarizeLocations(AssetScope::forCharacter(...))`), stations et structures confondues, noms déjà résolus par la synchro des assets.

## Backend

1. `User` gagne trois colonnes : `planetaryHomeLocationId` (bigint, nullable), `planetaryHomeLocationName` (string 255, nullable, nom au moment du choix), `planetaryCoverageWeeks` (smallint, non nul, défaut base et entité = constante `DEFAULT_PLANETARY_COVERAGE_WEEKS` = 3, valeur de la maquette). Migration `Version20261126090000` (ajout des colonnes, `down` les retire). Aucune donnée existante touchée.
2. Ressource `PlanetarySettingsResource` (`shortName: PlanetarySettings`, API Platform, `security: is_granted('ROLE_USER')`) :
   - `GET /planetary/settings` : `homeLocationId` (?int), `homeLocationName` (?string), `coverageWeeks` (int), `hubStructureId` (int), `hubStructureName` (string) = hub effectif (règle du contexte).
   - `PATCH /planetary/settings` (`application/merge-patch+json`) : champs écrivables `homeLocationId` et `coverageWeeks` seulement ; `hubStructureId` et `hubStructureName` sont ignorés à l'écriture. Réponse = ressource à jour. Provider commun aux deux opérations, processor dédié.
3. Validation du PATCH (422 avec violation sur le champ, message en anglais, rien n'est écrit si une règle échoue) :
   - `coverageWeeks` : entier de 1 à 52 (`MIN_COVERAGE_WEEKS`, `MAX_COVERAGE_WEEKS`).
   - `homeLocationId` non nul : doit être l'un des lieux racines des assets personnels des personnages de l'utilisateur connecté (règle 4). Sinon violation « This location is not known to any of your characters. ». Le nom stocké est celui du lieu candidat.
   - `homeLocationId` nul : le lieu et son nom sont effacés (pas de maison, état valide).
4. Lieux candidats : `GET /planetary/settings/locations` (ressource `PlanetaryHomeLocationResource`, `ROLE_USER`) renvoie `locations: [{locationId, locationName, solarSystemName}]`. Source : `summarizeLocations` de chaque personnage de l'utilisateur, fusionné par `locationId` (un lieu commun à deux personnages une seule fois), trié par nom puis id. Utilisé aussi par la règle 3 : un service `PlanetaryHomeLocationCatalog` (`forUser(User): list<...>`) est la source unique. Aucun paramètre d'identifiant reçu : seuls les personnages de l'utilisateur connecté.
5. Pas de Mercure (écriture synchrone d'un réglage), pas de synchro planifiée, aucun appel ESI.

## Frontend

6. Onglet `stock` ajouté à `PLANETARY_TABS` (ordre : planner, colonies, stock, revenue), libellé `pi.tabs.stock` FR+EN, composant `PiStockTab.vue` qui affiche `PiStockSettings.vue` et, tant que #1395 n'est pas là, rien d'autre. Colonies reste l'onglet par défaut.
7. Store `planetarySettings` : `load()` (GET réglages + lieux candidats + favoris du hub via `/me/settings`), `saveHomeLocation(id | null)`, `saveCoverageWeeks(n)` (PATCH, remplace l'état par la réponse), `saveHub(id)` (PATCH `/me/settings` avec `preferredMarketStructureId` et `preferredMarketStructureName` du favori choisi, puis recharge les réglages). Un enregistrement refusé (422) ou en erreur laisse la valeur précédente affichée et expose `error`.
8. `PiStockSettings.vue` : un sélecteur « Lieu maison » (option vide « Aucun » + candidats ; un lieu enregistré qui n'est plus candidat reste affiché avec son nom), un sélecteur « Hub » (favoris de l'utilisateur ; sans favori : hub effectif affiché en lecture seule avec un texte renvoyant aux réglages de marché), un champ « Couverture visée » en semaines. Champ semaines : saisie vide, ≤ 0, > 52, non entière ou non numérique : refusée côté client, valeur précédente conservée, bordure rouge, aucun appel API. Direction design HUD EVE, mêmes classes que `PiPlannerSettings.vue`.
9. Aucun libellé de réglage ni écran existant n'est retiré.

## Exemples chiffrés (tests rouges)

| Cas | Attendu |
|---|---|
| Utilisateur neuf, `GET /planetary/settings` | `homeLocationId` null, `coverageWeeks` 3, hub = défaut config |
| `PATCH {coverageWeeks: 4}` | 200, 4 stocké, lieu inchangé |
| `PATCH {coverageWeeks: 0}` / `53` / `2.5` | 422 sur `coverageWeeks`, 3 conservé |
| `PATCH {coverageWeeks: 1}` / `52` | 200 (bornes incluses) |
| `PATCH {homeLocationId: L}` avec L parmi les assets du personnage B de l'utilisateur | 200, nom du lieu stocké |
| `PATCH {homeLocationId: L}` avec L seulement chez un autre utilisateur ou inconnu | 422 sur `homeLocationId`, rien d'écrit |
| `PATCH {homeLocationId: null}` après un choix | 200, id et nom à null |
| PATCH sans `homeLocationId` ni `coverageWeeks` écrivables (`{hubStructureId: 1}`) | 200, aucun changement |
| Hub : préféré 7 et favoris [9] | `hubStructureId` 7 ; préféré null, favoris [9] → 9 ; ni l'un ni l'autre → défaut config |
| Lieux candidats | personnages A (L1, L2) et B (L2, L3) : L1, L2, L3 une fois chacun, triés par nom ; autre utilisateur : absent ; aucun asset : liste vide |
| Anonyme | 401 sur les deux GET et le PATCH |

## Accès et fraîcheur

Toutes les lectures et écritures sont limitées à l'utilisateur connecté (réglage sur `User`, candidats par ses personnages). Fraîcheur des candidats = dernière synchro des assets (30 min à la demande, pas de synchro planifiée) ; un lieu sans asset n'est pas proposé.

## Hors périmètre

Couverture, ordres d'achat, prix (#1395, #1396, #1400) ; lien manuel d'un customs office ; recherche de station ou de structure hors assets ; réglages d'alertes PI (restent dans Réglages · Notifications).

## Décisions prises en autonomie

- Défaut de couverture 3 semaines (maquette), à ajuster par Sylvain.
- Lieu maison limité aux lieux où le compte a déjà des assets : évite une recherche de station inexistante et garantit que le stock maison est lisible ; un lieu vierge ne peut pas être choisi.
- Hub : pas de second champ, le marché préféré existant est réutilisé ; le défaut C-J6MT n'est pas proposé comme choix explicite (sa sélection serait refusée par la règle de `UpdateUserSettingsProcessor` s'il n'est ni en cache ni en asset).
