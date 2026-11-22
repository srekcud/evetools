# #1361 — Même nom de container à plusieurs stations

Circuit `m`, mode nuit. Un joueur nomme ses containers de projet pareil dans chaque station ; le lieu les distingue (modèle par station, #1292). La contrainte d'unicité sur `(project_id, name)` datait du temps où le nom était la seule clé. Back-end seulement, aucun écran.

## Règles

R1. Un container de projet est identifié par sa station : unicité sur `(project_id, location_id)` seulement. Migration Doctrine : supprime l'index unique `UNIQ_F9B6047C166D1F9C5E237E06` sur `(project_id, name)` ; `down` le recrée. Les lignes existantes ne changent pas.

R2. `ProjectContainerPlacer::place()` :
- un container du projet de même nom (casse et espaces ignorés) **sans lieu** est localisé à la station (inchangé) ;
- sinon, un container est créé à la station, même si un container de même nom est déjà localisé dans une autre station (plus de `ContainerNameTakenException` pour ce cas) ;
- une station qui a déjà un container reste refusée (`StationAlreadyHasContainerException`) ;
- le conflit avec le nom d'un container de stock personnel (`isNamedLikeAPersonalStockContainer`) reste refusé.

R3. `ProjectContainerStations` : le nom proposé pour une station n'évite plus les noms des containers déjà localisés (il peut les réutiliser). Il évite toujours les noms des containers sans lieu (sinon l'ajout prendrait ce container au lieu d'en créer un).

R4. `IndustryProject::getContainerNames()` renvoie les noms distincts (clé : `IndustryProjectContainer::keyOf`, premier nom gardé, ordre conservé). Les lecteurs par nom (`PinnedContainerCatalog`, `PersonalStockContainerDeclarer`, vérification des matériaux) ne voient pas de doublon. `setContainerNames()` ne supprime ni ne fusionne les lignes de même nom déjà présentes.

R5. Migration vers le modèle par station (`ProjectStationMigration`, `ContainerLocationInference`) : un container sans lieu dont le nom est trouvé dans plusieurs stations donne un container localisé par station du plan où un asset de ce nom se trouve :
- le container existant est localisé à la première station, un nouveau container de même nom est créé à chaque autre station ;
- chaque station est un changement `container_location` (sujet = id du container localisé ou créé, valeur = id de lieu) ;
- une station hors plan reste rapportée `station_not_in_plan`, une station qui a déjà un container `station_already_has_container` (sujet = id du container d'origine, détail = id de lieu quand plusieurs stations sont en jeu) ;
- un asset de ce nom dont la station est non résolue laisse tout le container rapporté `station_unresolved` (décision de #1320 conservée : un asset non résolu n'est pas écarté au profit d'une station connue) ;
- aucun asset du nom : `container_not_found` (inchangé) ; nom d'un container de stock personnel : `named_like_personal_stock` (inchangé).
`ContainerLocationInference::of()` renvoie la liste des stations distinctes (ordre croissant) ou la raison. Le cas `ContainerInSeveralStations` de l'enum est conservé (aucun réglage retiré sans accord), plus émis.

R6. Lecture d'un container localisé : inchangée (seuls les assets de son nom dans sa station).

## Exemples

| Situation | Résultat |
|---|---|
| `Mats` à A, placer `Mats` à B | deuxième container à B |
| `Mats` sans lieu, placer `mats` à B | le container existant est localisé à B |
| `Mats` à A, nom proposé à B | `<projet> <structure B>` (pas de suffixe lié à `Mats`) |
| Sans lieu `Ishtar`, assets à A et B (plan : A et B) | localisé à A, créé à B, deux changements |
| Sans lieu `Ishtar`, assets à A (plan) et C (hors plan) | localisé à A, C rapportée `station_not_in_plan` |
| Sans lieu `Ishtar`, assets à A et à un lieu non résolu | non migré `station_unresolved` |

## Hors périmètre

Écran de placement (#1353, #1307), choix par item id, station liée au projet, suppression du cas `ContainerInSeveralStations`.

## Décisions ouvertes

Retirer le cas `ContainerInSeveralStations` de `StationMigrationReason` : à valider par Sylvain.
