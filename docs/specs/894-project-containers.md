# #894 : un projet surveille plusieurs conteneurs

Décision de Sylvain (2026-11-13, commentaire de l'issue) : table enfant (projet, nom de conteneur) avec contrainte d'unicité par projet, migration d'une ligne par projet ayant déjà un nom, puis suppression de `IndustryProject::$containerName` ; correspondance insensible à la casse et aux espaces de bord.

## Règles

1. `IndustryProject` porte une collection `containers` d'entités `IndustryProjectContainer` (table `industry_project_containers` : `id` UUID, `project_id` FK cascade, `name` VARCHAR(255), unique `(project_id, name)`). `getContainerName()` / `setContainerName()` disparaissent ; `getContainerNames(): list<string>` et `setContainerNames(list<string>)` les remplacent.
2. `setContainerNames` : chaque nom est rogné (`trim`), les vides sont ignorés, les doublons sont fusionnés sans tenir compte de la casse (le premier gagne), l'ordre donné est conservé. Les noms absents de la nouvelle liste sont supprimés (orphelins), les noms déjà présents restent sans être recréés.
3. Un asset corporation correspond à un nom si `mb_strtolower(trim(itemName)) === mb_strtolower(trim(nom))`. Un conteneur racine par nom déclaré, mais plusieurs assets peuvent porter le même nom (ils s'additionnent, décision #801 inchangée : pas de choix par item id, pas de station liée).
4. `ProjectContainerService::walkNamedContainers` accepte la liste des noms. Racines = assets dont le nom correspond à un des noms. Contenu = sous-arbre de chaque racine, chaque asset compté une seule fois même si deux noms se recouvrent (un conteneur déclaré dans un autre conteneur déclaré).
5. « Conteneur introuvable » est rapporté par nom : `ProjectContainerStock`, `ProjectContainerScope` et chaque ligne de `ProjectContainerVerificationResource` portent `missingContainerNames: list<string>` (les noms déclarés, tels que saisis, sans aucun asset visible). Vide quand tous sont trouvés.
6. Statuts : aucun nom déclaré = `no_container_name` (comportement actuel, `ProjectLineFulfillments::none()`). Tous les noms introuvables = statut actuel (`container_not_found`, `container_in_hidden_division` si au moins un des noms se trouve dans une division masquée, `corporation_assets_stale`) avec `missingContainerNames` = tous les noms. Au moins un nom trouvé = les quantités des conteneurs trouvés sont utilisées (vérifié / partiel / manquant comme aujourd'hui) et `missingContainerNames` liste ceux qui manquent.
7. Les lecteurs passent par `getContainerNames()` : `IntermediateStockProposer`, `T2BlueprintCopyProposer` (condition `[] !== $project->getContainerNames()`), `ProjectContainerFulfillment` (via `containerStockOf`), `ProjectJobLocationResolver` (via `containerScopeOf`, ids de tous les conteneurs trouvés), la vérification du conteneur.
8. API `IndustryProjectResource` : `containerName` est remplacé par `containerNames: list<string>`. `CreateProjectInput::$containerNames` (liste, défaut `[]`) et `UpdateProjectInput::$containerNames` (`null` = inchangé, `[]` = tous retirés) : au plus 20 noms, chacun une chaîne de 255 caractères au plus. `CreateProjectData` et `IndustryProjectService::createProject` suivent.
9. Migration : crée la table, insère une ligne `(gen_random_uuid(), id, trim(container_name))` pour chaque projet dont `trim(container_name)` n'est pas vide, puis supprime `industry_projects.container_name`. `down()` recrée la colonne (premier nom par ordre alphabétique pour chaque projet) et supprime la table.
10. Front : `IndustryProject.containerNames: string[]`, `CreateProjectPayload.containerNames?: string[]` ; le champ de saisie (création et édition) devient un champ multiligne, un nom par ligne ; `ContainerBanner` reçoit `containerNames: string[]`, affiche tous les noms et, par nom introuvable, une ligne `groupIndustry.container.missingNames`. La bannière s'affiche quand `containerNames.length > 0`. `MiningOperation` (`fleetStorageContainerName`) n'est pas touché.

## Exemples

| Noms déclarés | Assets visibles (nom : contenu) | Résultat |
|---|---|---|
| `["Mats", "Products"]` | `mats` : 10 Tritanium ; `PRODUCTS` : 5 Hull | stock {Trit: 10, Hull: 5}, manquants `[]` |
| `["Mats", "Products"]` | `Mats` : 10 Tritanium | stock {Trit: 10}, manquants `["Products"]` |
| `["Mats", "Products"]` | aucun | `container_not_found`, manquants `["Mats", "Products"]` |
| `[" Mats "]` | `Mats ` (espace final) | trouvé |
| `["Mats", "mats"]` | | stocké `["Mats"]` |
| `["Mats"]` | deux assets `Mats` dans deux stations | quantités additionnées |

## Cas limites

- Migration d'un projet dont `container_name` est vide ou `NULL` : aucune ligne enfant.
- Un asset sans nom (`itemName` null) ne correspond jamais.
- Un nom déclaré qui désigne un conteneur contenu dans un autre conteneur déclaré : ses assets ne sont comptés qu'une fois.

## Hors périmètre

- Épinglage des conteneurs dans l'inventaire (#893, encore ouverte) : `containerNames` sur l'API suffit à #893 pour lire la liste.
- Conteneur de stockage de flotte de minage.
- Choix d'un conteneur par station ou par item id.

## Décisions prises en autonomie

- Le champ d'API `containerName` est remplacé (pas conservé en doublon) : la décision de l'issue supprime la colonne, l'API et le front suivent.
- Saisie front : un nom par ligne (un nom d'EVE peut contenir une virgule).

## Prod

Migration automatique au déploiement (`doctrine:migrations:migrate`), aucune commande console.
