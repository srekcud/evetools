# Spec #199 — Rigs d'une structure de production validés

## Contexte

`POST /api/industry/structures` et `PATCH /api/industry/structures/{id}` acceptent n'importe quelle liste de rigs (`CreateStructureInput::$rigs`, `UpdateStructureInput::$rigs`, aucune contrainte). `IndustryBonusService::calculateStructureBonusForCategory()` (`:289-296`) additionne ensuite un rig en double et saute un nom inconnu sans signal. Le glossaire limite une structure de production à 3 rigs. Le front filtre les rigs par famille et par taille (`StructureConfig.vue:173-200`), l'API non.

Catalogue des rigs : `RigOptionsProvider` (nom, `size` M/L/XL, famille `manufacturing` ou `reaction`).

## Règles métier

- **R1 — Nombre.** Au plus 3 rigs.
- **R2 — Doublon.** Un même rig ne figure qu'une fois.
- **R3 — Nom connu.** Chaque rig est un nom du catalogue.
- **R4 — Famille et taille.** Le rig doit convenir au type de structure :

  | Type de structure | Famille | Taille |
  |---|---|---|
  | raitaru | manufacturing | M |
  | azbel | manufacturing | L |
  | sotiyo | manufacturing | XL |
  | engineering_complex (ancien) | manufacturing | toutes |
  | athanor | reaction | M |
  | tatara | reaction | L |
  | refinery (ancien) | reaction | toutes |
  | station | aucun rig | — |

- **R5 — Réponse.** Une règle violée renvoie 422 avec une violation sur `rigs` ; rien n'est enregistré.
- **R6 — État final.** En PATCH, la règle s'applique à l'état après modification : changer `structureType` sans envoyer `rigs` valide les rigs existants contre le nouveau type.
- **R7 — Données existantes.** Aucune migration : les structures déjà enregistrées ne sont pas modifiées. Le calcul des bonus reste inchangé.

## Exemples

| # | Requête | Attendu |
|---|---|---|
| E1 | POST raitaru, 3 rigs M manufacturing distincts | 201, rigs enregistrés |
| E2 | POST raitaru, 4 rigs M manufacturing distincts | 422 `rigs` |
| E3 | POST raitaru, le même rig M deux fois | 422 `rigs` |
| E4 | POST raitaru, `["Standup M-Set Inexistant I"]` | 422 `rigs` |
| E5 | POST raitaru, un rig L-Set manufacturing | 422 `rigs` |
| E6 | POST raitaru, un rig M-Set reaction | 422 `rigs` |
| E7 | POST station, un rig M manufacturing | 422 `rigs` |
| E8 | POST engineering_complex, un rig XL manufacturing | 201 |
| E9 | PATCH azbel → `rigs` 4 rigs L | 422, structure inchangée |
| E10 | PATCH raitaru avec un rig M → `structureType: azbel` sans `rigs` | 422 `rigs`, structure inchangée |
| E11 | PATCH raitaru avec un rig M → `name` seul | 200 |
| E12 | POST sotiyo, `rigs: []` | 201 |

## Hors périmètre

- Nettoyage des structures existantes (R7). Requête de contrôle à lancer en prod avant déploiement, notée dans le journal.
- Fusion des deux catalogues de rigs (`RigOptionsProvider` et `IndustryBonusService::getRigOptions()`).
- Bonus calculés pour des données déjà invalides.

## Décisions (validées par Sylvain le 2026-10-23)

- Taille et famille validées côté API (R4), même règle que le front.
- Pas de migration ; requête de contrôle avant déploiement (R7).

## Glossaire

Terme existant : **Structure de production** (« au plus 3 rigs »). Aucun ajout.
