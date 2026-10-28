# Spec #158 — Group Industry : contenu imbriqué du container et états distincts de la vérification

Statut : **brouillon**, en attente de validation par Sylvain (voir « 🚦 Questions pour Sylvain »).

## Contexte

Un projet Group Industry peut porter un nom de container (`GroupIndustryProject::$containerName`). Un owner ou un admin du projet compare alors les lignes matériaux de la BOM au contenu de ce container dans les assets corpo en cache (`GET /group-industry/projects/{projectId}/container-verification`, `ContainerVerificationProvider`, `GroupIndustryContainerService::verifyContainer()`).

Constaté dans le code (branche `fix/group-group-industry`, 90f0618 ; les numéros de ligne de l'issue sont périmés) :

- **Chargement.** `GroupIndustryContainerService::getContainerQuantities()` lit `VisibleCorporationAssetsFinder::findVisibleAssets()` : assets des divisions visibles (`CachedAssetRepository::findByCorporationAndDivisions()`, flags `CorpSAG{N}`) puis leur contenu jusqu'à 5 niveaux (`CachedAssetRepository::findContentsOfContainers()`, `MAX_CONTAINER_DEPTH`). L'issue parle de `findByCorporationId` : ce n'est plus le cas, la lecture est limitée aux divisions visibles, mais elle porte sur tous leurs assets.
- **Comptage.** Le service ne compte que les assets dont le `locationId` est l'`itemId` d'un container du nom : le contenu direct. Le contenu d'un sous-container est chargé par le finder, puis ignoré.
- **États confondus.** Le statut `unchecked` et `containerQuantity = 0` sortent dans cinq situations différentes :
  1. projet sans nom de container, ou owner sans corporation (`buildAllUnchecked()`) ;
  2. visibilité corpo non configurée ou sans division (`findVisibleAssets()` renvoie `[]`) ;
  3. aucun asset corpo en cache pour la corporation : jamais synchronisés, ou aucun personnage avec le scope `esi-assets.read_corporation_assets.v1` et le rôle requis (`AssetsSyncService::getCorpAssetsCharacter()`) ;
  4. aucun container de ce nom dans les assets visibles (nom absent, container dans une division non visible ou dans `CorpDeliveries`) ;
  5. container trouvé, matériau absent (`determineStatus()` rend `unchecked` pour 0).
- **Valeur plausible.** Dans les cas 1 à 4, `containerQuantity` vaut 0 alors que la quantité n'est pas connue (règle « jamais de valeur plausible » de `CLAUDE.md`).
- **Front.** `BomTable.vue` n'affiche que `verified` et `partial` : pour `unchecked`, rien. L'utilisateur ne sait pas s'il doit synchroniser les assets, corriger le nom du container ou le remplir. La vérification n'est lancée que depuis `ContainerBanner.vue`, affiché seulement si le projet a un nom de container.

## Règles métier

- **R1 — Contenu du container.** La quantité d'un type dans le container est la somme des quantités de tous les assets de ce type qui descendent d'un container du nom : contenu direct et contenu des sous-containers, à toute profondeur chargée par `findContentsOfContainers()`.
- **R2 — Chaque asset compté une fois.** Plusieurs containers du même nom s'additionnent (comportement actuel). Un container du nom rangé dans un autre container du même nom ne fait pas compter son contenu deux fois.
- **R3 — Correspondance du nom.** Nom exact, sensible à la casse, comme aujourd'hui (`itemName === containerName`).
- **R4 — États, évalués dans cet ordre.** Le premier qui s'applique fixe le statut de toutes les lignes, sauf le dernier qui se décide ligne par ligne.

  | Ordre | Situation | `status` | `unknownReason` | `containerQuantity` |
  |---|---|---|---|---|
  | 1 | Projet sans nom de container | aucune ligne renvoyée (Q3) | — | — |
  | 2 | Owner sans corporation (pas de main, ou main sans corporation) | `unknown` | `owner_without_corporation` | `null` |
  | 3 | Aucun asset corpo en cache pour la corporation | `unknown` | `corporation_assets_not_synced` | `null` |
  | 4 | Visibilité corpo absente, ou sans division visible | `unknown` | `no_visible_division` | `null` |
  | 5 | Aucun container du nom parmi les assets visibles | `container_not_found` | `null` | `null` |
  | 6 | Container trouvé : quantité ≥ requise | `verified` | `null` | quantité |
  | 6 | Container trouvé : 0 < quantité < requise | `partial` | `null` | quantité |
  | 6 | Container trouvé : quantité 0 | `missing` | `null` | 0 |

  `unchecked` disparaît. Un 0 n'est renvoyé que lorsque le container a été trouvé et ne contient pas le matériau.
- **R5 — API.** La ressource reste une collection par ligne matériau. `GroupContainerVerificationResource::$containerQuantity` devient `?int` ; ajout de `?string $unknownReason`. Type front `ContainerVerificationStatus` et `ContainerVerification` alignés.
- **R6 — Affichage (proposition, Q1).** Les états du container (`unknown`, `container_not_found`) s'affichent une fois dans `ContainerBanner.vue`, avec l'action à mener ; les états de ligne (`verified`, `partial`, `missing`) dans `BomTable.vue`. Pas de maquette nouvelle : composants existants, libellés i18n (Q1, à relire par Sylvain).

## Exemples chiffrés

Corporation 98 000 001, container « Build », BOM : Tritanium (34) requis 500, Pyerite (35) requis 800. Divisions visibles : [2], sauf mention contraire. « A > B » : B est rangé dans A.

| # | Cas | Attendu (Tritanium ; Pyerite) | Aujourd'hui |
|---|---|---|---|
| E1 | CorpSAG2 > Build > {1 000 Tritanium, Minerals > 240 Pyerite} | 1 000 `verified` ; 240 `partial` | Pyerite 0 `unchecked` |
| E2 | CorpSAG2 > Build > {450 Tritanium, Minerals > 100 Tritanium} | 550 `verified` ; 0 `missing` | Tritanium 450 `partial` |
| E3 | CorpSAG2 > Build > Minerals > {600 Pyerite, Bag > 300 Pyerite} | 0 `missing` ; 900 `verified` | Pyerite 0 `unchecked` |
| E4 | CorpSAG2 > Outer > Build > {500 Tritanium, Minerals > 800 Pyerite} (container dans un container dans un hangar) | 500 `verified` ; 800 `verified` | Pyerite 0 `unchecked` |
| E5 | CorpSAG1 > Build > 300 Tritanium et CorpSAG2 > Build > 300 Tritanium, divisions visibles [1, 2] | 600 `verified` ; 0 `missing` | Pyerite 0 `unchecked` |
| E6 | CorpSAG2 > Build > {100 Tritanium, Build > 200 Tritanium} | 300 `partial` (pas 500) ; 0 `missing` | 300 `partial` par hasard (contenu direct de chacun) |
| E7 | CorpSAG2 > Build, vide | 0 `missing` ; 0 `missing` | 0 `unchecked` |
| E8 | Assets synchronisés, aucun container « Build » | `null` `container_not_found` (les deux) | 0 `unchecked` |
| E9 | CorpSAG2 > Build > 1 000 Tritanium, divisions visibles [1] | `null` `container_not_found` (les deux) | 0 `unchecked` |
| E10 | Build dans `CorpDeliveries`, divisions visibles [1..7] | `null` `container_not_found` (les deux) | 0 `unchecked` |
| E11 | Aucun asset corpo en cache pour 98 000 001 (jamais synchronisés, ou aucun personnage avec le scope) | `null` `unknown` / `corporation_assets_not_synced` | 0 `unchecked` |
| E12 | Assets en cache, aucune `CorpAssetVisibility` pour la corporation | `null` `unknown` / `no_visible_division` | 0 `unchecked` |
| E13 | Assets en cache, `CorpAssetVisibility` avec divisions `[]` | `null` `unknown` / `no_visible_division` | 0 `unchecked` |
| E14 | Owner sans main, ou main sans corporation | `null` `unknown` / `owner_without_corporation` | 0 `unchecked` |
| E15 | Projet sans nom de container | `[]` (Q3) | lignes `unchecked` |
| E16 | Container nommé « build » (casse différente) | `null` `container_not_found` | 0 `unchecked` |
| E17 | Projet sans ligne matériau | `[]` | `[]` (inchangé) |

## Cas limites

- **Profondeur bornée.** `findContentsOfContainers()` s'arrête à 5 niveaux sous un asset de hangar : un contenu plus profond n'est pas chargé et serait sous-compté sans le signaler. Les règles d'imbrication en jeu (un container assemblé peut-il entrer dans un autre ?) sont [À CONFIRMER] ; le glossaire admet l'imbrication. Bornage inchangé ici.
- **Nom non lu.** `AssetsService::resolveItemNames()` ignore les erreurs de `/corporations/{id}/assets/names/` (catch silencieux) : un container existant sans nom en cache donne `container_not_found`. Hors périmètre, à traiter dans une issue séparée.
- **Cache périmé.** Les assets corpo ne sont pas planifiés (sync à la demande). Une vérification sur un cache ancien répond depuis ce cache ; un scope perdu après une sync ne rend pas la vérification `unknown`. `CachedAsset::$cachedAt` existe, mais l'exposer est hors périmètre.
- **Vaisseau assemblé du même nom.** R1 compte tout ce qui descend d'un asset du nom, modules montés compris. Cas jugé marginal, comportement accepté.
- **Sous-container compté comme type.** Le sous-container lui-même compte pour son type (ex. Station Container) ; sans effet tant que la BOM ne le demande pas.
- **Asset sans scope.** Sans personnage portant `esi-assets.read_corporation_assets.v1` (et le rôle en jeu), la sync corpo n'a jamais écrit d'asset : E11. Un 403 pendant une sync laisse le cache précédent (`AssetsSyncService::syncCorporationAssets()` lit l'ESI avant de supprimer) : la vérification répond depuis ce cache.

## Hors périmètre

- Coût de lecture : la vérification charge tous les assets des divisions visibles et leur contenu (volume, pas N+1). Recherche ciblée par nom : issue séparée, sur mesure (Q5).
- Catch silencieux de `resolveItemNames()`, borne `MAX_CONTAINER_DEPTH`.
- Vérification des contributions (`GroupIndustryContribution::$isVerified`) : indépendante de ce service.
- Règles d'accès (owner ou admin, `GroupProjectAccessChecker::assertAdminOrOwner()`).

## Tests existants appelés à changer

- `tests/Unit/Service/GroupIndustry/GroupIndustryContainerServiceTest.php` : `testReturnsAllUncheckedWhenNoContainerName` (E15), `testWithoutContainerNameEveryMaterialIsUncheckedInBomOrder` (`:75`, E15 : il attend aussi des lignes `unchecked` sans nom de container), `testReturnsAllUncheckedWhenNoCorporationId` (E14), `testReturnsUncheckedWhenContainerNotFoundInCorpAssets` (E8), `testUncheckedWhenMaterialNotInContainer` et `testHandlesMultipleBomItemsWithMixedStatuses` (`missing`). Le constructeur change si le service reçoit `CachedAssetRepository` pour la règle 3.
- `tests/Integration/Service/GroupIndustry/GroupIndustryContainerServiceTest.php` : `testContainerOutsideTheAllowedDivisionsHoldsNothing` (E9), `testContainerHoldsNothingWhileNoDivisionIsAllowed` (E12), `testContainerInTheDeliveriesHangarHoldsNothingEvenWithEveryDivisionAllowed` (E10).

## Fichiers concernés (indicatif)

`GroupIndustryContainerService`, `GroupContainerVerificationResource`, `CachedAssetRepository` (une méthode « la corporation a-t-elle des assets en cache »), `frontend/src/stores/group-industry/types.ts`, `BomTable.vue`, `ContainerBanner.vue`, locales FR/EN. Aucune migration, aucune dépendance.

## Glossaire

Termes existants : **Container**, **Division**. Proposé, non ajouté tant que Sylvain n'a pas validé :

| Terme FR | Term EN | Définition proposée | Symbole |
|---|---|---|---|
| **Vérification du container** | Container check | Comparaison des lignes matériaux d'une BOM Group Industry au contenu, sous-containers compris, des containers corpo du nom du projet dans les divisions visibles. Elle est inconnue (motif) si l'owner n'a pas de corporation, si les assets corpo ne sont pas en cache ou si aucune division n'est visible ; le container est introuvable si aucun container du nom n'est visible ; sinon chaque ligne est vérifiée, partielle ou absente. | `GroupIndustryContainerService::verifyContainer()`, `GroupContainerVerificationResource::$status` |

## 🚦 Questions pour Sylvain

1. **Libellés et affichage.** Je propose : Vérifié / Verified, Partiel / Partial, Absent du container / Missing from container (ligne) ; Container introuvable / Container not found et Non vérifiable / Cannot be checked + motif (« Synchronisez les assets corpo », « Aucune division partagée », « L'owner n'a pas de corporation »), affichés une fois dans le bandeau du container. Recommandation retenue pour la boucle de nuit, **sans maquette nouvelle** : les états de ligne et le bandeau réutilisent les composants existants de `BomTable.vue` (indicateur de ligne de `verificationMap`) et de `GroupIndustryDetail.vue` (`ContainerBanner`, affiché si le projet a un nom de container), avec les libellés via l'i18n existant (`groupIndustry.container.statuses`, `frontend/src/i18n/locales/`) et des clés ajoutées pour `missing`, `container_not_found`, `unknown` et ses trois motifs. **À relire par Sylvain.**
2. **Un seul `unknown` avec motif** (3 motifs ci-dessus), plutôt qu'un statut par cas, et sans séparer « jamais synchronisé » de « aucun personnage avec le scope » (le message dit de synchroniser avec un directeur qui porte le scope). Recommandation : oui. OK ?
3. **Projet sans nom de container** : liste vide (rien à vérifier, le bandeau n'apparaît pas) plutôt que des lignes `unknown`. Recommandation : liste vide. OK ?
4. **Container rangé dans une division non visible** : `container_not_found`, sans révéler qu'il existe ailleurs (la liste blanche du directeur reste étanche). Recommandation : oui. OK ?
5. **Coût de lecture** des assets visibles : hors de #158, issue séparée à ouvrir seulement si une mesure montre une lenteur. Recommandation : hors périmètre. OK ?
