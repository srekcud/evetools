# #1000 — Personnages producteurs d'un projet

Issue : choisir, à la création puis dans les réglages, les personnages du propriétaire qui produisent pour un projet. Circuit m, groupe `unified-industry`, maquette `industry-project-v3` non concernée (pas de nouvel écran : une case par personnage dans les modales existantes).

## Règles

1. `IndustryProject::$producerCharacterIds` : liste des `eveCharacterId` (entiers) des personnages du propriétaire qui produisent, colonne JSON nullable `producer_character_ids`. `null` = tous les personnages du propriétaire (les projets existants gardent leur comportement, sans rattrapage).
2. Création : `CreateProjectInput::$producerCharacterIds` (`?list<int>`, absent = `null` = tous). Liste vide : 422 sur `producerCharacterIds` (`#[Assert\Count(min: 1)]`). Un identifiant qui n'est pas un personnage du propriétaire : 400 `Unknown producer character`.
3. Modification : `UpdateProjectInput::$producerCharacterIds`, mêmes validations, par le propriétaire ou un administrateur du projet (`assertAdminOrOwner`, déjà en place), la validation se fait contre les personnages du **propriétaire**, pas de la personne qui modifie. Projet `completed` : 400 `Cannot change the producer characters of a completed project`. Aucune reconstruction de la BOM.
4. Lecture : `IndustryProjectResource::$producerCharacterIds` (`?list<int>`, `null` = tous), alimenté par `ProjectResourceMapper`.
5. Planning : `ProjectPlanningInputLoader` ne charge que les personnages sélectionnés (tous si `null`). Un personnage décoché n'a aucune voie ni aucun job prévu. Un identifiant stocké qui ne correspond plus à un personnage du propriétaire (personnage délié) est ignoré ; si plus aucun ne reste, le planning n'a aucun personnage (jobs non planifiés, pas de repli sur tous).
6. Rapprochement des jobs (`IndustryJobMatcher`) inchangé : un job réellement lancé par n'importe quel personnage compte toujours (recommandation de l'issue).
7. Projet de groupe : seuls les personnages du propriétaire sont concernés, comme le planning aujourd'hui ; pas de sélection par participant (question laissée à une issue de suite).
8. Front, `NewProjectModal` : `ProducerCharactersSelect` (cases à cocher, charge `GET /me/characters`), tous cochés par défaut une fois la liste chargée, la dernière case cochée est désactivée (au moins un personnage), `producerCharacterIds` envoyé dans le POST quand la liste est chargée, absent sinon. Échec de chargement : message d'avertissement, rien envoyé.
9. Front, modale d'édition de `GroupIndustryDetail` : même composant, initialisé depuis `project.producerCharacterIds` (`null` = tous cochés), envoyé dans le PATCH seulement s'il a changé, désactivé si le projet est `completed`.
10. i18n FR et EN (`groupIndustry.producerCharacters.*`).

## Exemples

| Personnages du propriétaire | Sélection stockée | Voies du planning |
|---|---|---|
| Aria, Brin | `null` | Aria, Brin |
| Aria, Brin | `[Aria]` | Aria |
| Aria, Brin | `[Brin, 9999]` (9999 délié) | Brin |
| Aria | `[9999]` | aucune, jobs non planifiés |

## Cas limites

- `POST` avec `producerCharacterIds: []` : 422. Avec l'identifiant d'un personnage d'un autre utilisateur : 400.
- `PATCH` sans `producerCharacterIds` : sélection inchangée.
- Un personnage ajouté au compte après la création d'un projet à sélection explicite n'est pas coché d'office.

## Hors périmètre

Sélection par participant, modèles de projet (non copiée), `IndustryJobMatcher`, assignation réelle des jobs, personnages de la corporation.

## Actions prod

Migration `Version20261116130000` (colonne nullable, aucune commande console).
