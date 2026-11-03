# Spec #311 — Opération de minage : vaisseau de boost et compresseurs montés

- **Statut** : rédigée en autonomie (mode nuit) le 2026-11-03, à valider par Sylvain avant `/tdd-red`.
- **Circuit** : `circuit:m`, sans note de câblage. **Dépend de** : #262 (création), #264 (réglages de valorisation), #266.
- **Maquette** : `mockups/grouped-mining-v4.html` (étapes 4, 9, 14 : `BOOST_SHIPS`, `compressible()`, `compressionBanner()`, `compTag()`, champ « Vaisseau de boost et compresseurs » de la modale de création).
- **Périmètre interdit** : aucune classe Industry, Group Industry ou Stockpile.

## 1. Contexte

Le booster vole en Porpoise, Orca ou Rorqual. Selon la coque et les compresseurs montés, tous les minerais ne se compressent pas sur place, et le volume à transporter en dépend. Ce lot déclare la coque et les compresseurs sur l'opération et dit, pour chaque type miné, s'il se compresse sur place.

Écarts avec l'issue, constatés dans le code (lu le 2026-11-03) :
- Les **sites** (#267) et le **compagnon** n'existent pas. Les « types du site » sont ici les types minés de l'opération (quantités du ledger). Le compagnon et le planning du hauler sont hors périmètre.
- La valorisation ne change pas : `MiningOperationValuator` n'est pas touché (1 brut = 1 compressé, #259).

Données SDE réelles (base locale, vérifiées) : groupe `4174` « Compressors », 12 types « Medium|Large|Capital … Compressor I ». Attribut `canFitShipType1` = type de la coque (Porpoise `42244`, Orca `28606`, Rorqual `28352`). Attribut `compressibleItemsTypeList` = genre : `330` moon, `331` ice, `332` gas, `333` mercoxit, `334` asteroid.

## 2. Modèle

Une migration : `mining_operations` + `boost_ship_type_id` integer nullable, + `mounted_compressor_kinds` json non nul, défaut `[]` (liste de valeurs d'enum). Lignes existantes : `null`, `[]`.

| Enum (`src/Enum/`) | Cas |
|---|---|
| `MiningBoostShip` (backed int = type SDE) | `Porpoise` 42244, `Orca` 28606, `Rorqual` 28352 |
| `MiningCompressorKind` (backed string) | `Asteroid` `asteroid` (liste 334), `Gas` `gas` (332), `Ice` `ice` (331), `Mercoxit` `mercoxit` (333), `Moon` `moon` (330) |
| `MiningCompressionStatus` | `OnSite` `on_site`, `NoHullCompressor` `no_hull_compressor`, `NotMounted` `not_mounted`, `UnknownOreKind` `unknown_ore_kind`, `NoBoostShip` `no_boost_ship` |

## 3. Règles métier

- **R1 — Coque et compresseurs.** L'opération porte une coque facultative et la liste des genres de compresseurs montés. Sans coque, la liste est vide.
- **R2 — Compresseurs montables.** Pour une coque, les genres montables sont lus dans le SDE : types du groupe `4174` dont `canFitShipType1` = la coque, genre = `compressibleItemsTypeList`. Une coque sans aucun compresseur dans le SDE est une erreur explicite (`BoostCompressorsMissingFromSde`), jamais une liste vide.
- **R3 — Saisie à la création.** `POST /api/mining-operations` accepte `boostShipTypeId` (entier ou `null`, absent = `null`) et `mountedCompressorKinds` (liste). Mêmes règles que R4.
- **R4 — Modification.** `PATCH /api/mining-operations/{operationId}/valuation` (réglages existants, mêmes droits : Owner et Admins, mêmes statuts éditables, refus une fois les parts gelées) accepte les deux champs. Si `boostShipTypeId` est fourni, `mountedCompressorKinds` l'est aussi. Refus `422` (violation sur le champ) : coque hors des trois valeurs, genre hors des cinq valeurs, doublon, liste non vide sans coque. Refus `400` « The {genre} compressor cannot be mounted on a {coque} » : genre non montable sur la coque (R2). Mettre la coque à `null` vide la liste. Champs absents inchangés.
- **R5 — Genre d'un minerai.** Par le SDE du type : groupe `465` → `ice` ; `468` → `mercoxit` ; groupes `1884`, `1920`, `1921`, `1922`, `1923` → `moon` ; groupe `711` → `gas` ; tout autre groupe de la catégorie `25` → `asteroid` ; sinon (type absent du SDE ou hors catégorie) genre inconnu.
- **R6 — Verdict par type.** Dans cet ordre : pas de coque → `no_boost_ship`, `compressible` `null` ; genre inconnu → `unknown_ore_kind`, `null` ; genre non montable sur la coque → `no_hull_compressor`, `false` ; montable mais absent de la liste montée → `not_mounted`, `false` ; sinon `on_site`, `true`.
- **R7 — Écarts.** `gaps.noHull` = genres distincts des types `no_hull_compressor` ; `gaps.notMounted` = genres distincts des types `not_mounted`. Un type `null` n'entre dans aucun écart (jamais un faux « compressible » ni un faux bandeau).
- **R8 — Types de l'opération.** Types dont la quantité minée (ledger, corrections comprises) d'au moins un participant est > 0, sans doublon, triés par `typeId`.
- **R9 — Lecture.** `MiningOperationLedgerResource` gagne `compression` : `boostShipTypeId`, `boostShipName`, `mountedCompressors` (`[{kind, typeId, typeName}]`, le type du compresseur du SDE), `types` (`[{typeId, compressible, status}]`), `gaps` (`{noHull, notMounted}`). `valuationSettings` de l'opération (et du Valuation) gagne `boostShipTypeId` et `mountedCompressorKinds`.
- **R10 — Catalogue.** `GET /api/mining-boost-ships` (`ROLE_USER`, sans pagination) : `[{typeId, name, compressors: [{kind, typeId, name}]}]` pour les trois coques, lu dans le SDE (R2).
- **R11 — Temps réel.** La modification passe par le PATCH de valorisation : l'action Mercure existante `valuation_updated` sur `/user/{id}/mining-operations` suffit, aucun topic nouveau. La création publie ce qu'elle publie déjà.

## 4. Exemples chiffrés (tests rouges)

| # | Coque | Montés | Type miné | `compressible` / `status` | Écarts |
|---|---|---|---|---|---|
| 1 | Porpoise | asteroid, gas | Spodumain (asteroid) | `true` / `on_site` | aucun |
| 2 | Porpoise | asteroid, gas | Zeolites (moon) | `false` / `no_hull_compressor` | noHull `[moon]` |
| 3 | Orca | asteroid | Blue Ice | `false` / `not_mounted` | notMounted `[ice]` |
| 4 | Orca | asteroid, gas, ice, mercoxit, moon | Mercoxit | `true` / `on_site` | aucun |
| 5 | Rorqual | (vide) | Veldspar | `false` / `not_mounted` | notMounted `[asteroid]` |
| 6 | aucune | (vide) | Veldspar | `null` / `no_boost_ship` | aucun |
| 7 | Orca | tous | type absent du SDE | `null` / `unknown_ore_kind` | aucun |
| 8 | Porpoise | gas | Fullerite-C50 (groupe 711) | `true` / `on_site` | aucun |
| 9 | Porpoise | asteroid, gas | Spodumain + Zeolites + Blue Ice | 3 verdicts (true, false, false) | noHull `[moon, ice]` |
| 10 | Porpoise | ice | (PATCH) | `400` « The ice compressor cannot be mounted on a Porpoise » | n/a |
| 11 | aucune | moon | (PATCH) | `422` sur `mountedCompressorKinds` | n/a |
| 12 | Orca puis `null` | tous puis n/a | (PATCH) | liste vidée | n/a |

Valeur des parts : même opération avec Porpoise et avec Orca → mêmes valeurs minées, mêmes parts, mêmes paiements (R-valorisation inchangée).

## 5. Front (HUD EVE, maquette retenue)

- **Création** (`NewMiningOperationModal`) : choix de la coque (3 boutons radio), puis un compresseur par case, **toutes cochées** à l'apparition (comme la maquette ; la liste est envoyée explicitement), noms et genres lus de `GET /api/mining-boost-ships`. Une coque absente de la saisie reste « non déclarée ».
- **Réglages de valorisation** (`MiningValuationSettings`) : même champ, enregistré par le PATCH de R4.
- **Page de l'opération** : par type, badge « sur place » (`true`), « aucun » (`false`), « inconnu » (`null`) dans le tableau des quantités minées ; bandeau ambre quand `gaps` n'est pas vide (« Le {coque} ne peut pas compresser … : volume brut à transporter » pour `noHull`, « Aucun compresseur monté pour … » pour `notMounted`), avec la mention que la valeur des parts ne change pas. Pas de bandeau sans coque. Libellés en i18n `en`/`fr`.

## 6. Accès et fraîcheur

- Lecture : participants (contrôle existant du ledger et de l'opération). Écriture : Owner et Admins (contrôle existant du PATCH de valorisation). Aucun identifiant reçu d'un autre périmètre ; aucune donnée de corporation lue.
- Catalogue : SDE seul, pas de donnée utilisateur. Le SDE ne change qu'à l'import : aucun cache.

## 7. Tests existants modifiés

- Tests qui comparent la forme complète de `valuationSettings` ou du ledger (`MiningOperationValuationApiTest`, `MiningOperationLedgerApiTest`, frontend du ledger et des réglages) : deux clés et un bloc de plus, à mettre à jour avec leur valeur exacte.
- Aucun test de calcul de parts ne change.

## 8. Hors périmètre

- Compagnon, sites et scans (#267, #305), planning du hauler et capacités (fleet hangar, soute de minerai), portée, compétences du pilote, Industrial Core actif (affiché en texte seulement).
- Compresseurs T2, import des listes de types du SDE (le genre se déduit des groupes, R5).

## 9. Décisions prises en autonomie

1. Coque facultative et valeurs explicites (aucun défaut côté API) ; le défaut « tout coché » est un choix d'interface.
2. Genres déduits des groupes SDE plutôt que d'un import des listes de types (évite une migration et un réimport en production) ; un minerai hors table reste « inconnu ».
3. Réglage porté par le PATCH de valorisation existant, pas de nouvel endpoint d'écriture.

## 10. Termes du glossaire ajoutés

| Terme | English | Définition |
|---|---|---|
| **Vaisseau de boost** | Boost ship | Coque du booster de l'opération : Porpoise, Orca ou Rorqual. |
| **Compresseur monté** | Mounted compressor | Genre de compresseur (minerai d'astéroïde, gaz, glace, mercoxit, minerai lunaire) monté sur le vaisseau de boost. |
| **Compressible sur place** | Compressible on site | Un type miné dont le genre a un compresseur monté sur le vaisseau de boost. |
