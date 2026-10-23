# Spec #204 — PATCH d'un projet ou d'une étape sans arbre de production

## Contexte

Le PATCH d'un projet (`/industry/projects/{id}`) a pour provider `ProjectProvider`, qui mappe toutes les étapes et construit l'arbre de production complet ; le processor `UpdateProjectProcessor` relit le projet, vérifie le propriétaire et renvoie sa propre ressource, sans rien utiliser de ce que le provider a construit. Le PATCH d'une étape a pour provider `ProjectStepProvider`, qui mappe l'étape alors que `UpdateStepProcessor` refait la même lecture.

## Règles

- **R1.** Le PATCH d'un projet ne construit pas l'arbre de production et ne mappe pas les étapes avant le processor. Le provider renvoie une ressource qui ne porte que l'identifiant, comme celui du DELETE.
- **R2.** Le PATCH d'une étape ne mappe pas l'étape avant le processor ; même provider léger que son DELETE.
- **R3.** Inchangé : le processor vérifie le propriétaire (404 pour un projet d'un autre utilisateur ou inconnu), la réponse du PATCH est celle du processor, le GET d'un projet garde son arbre.
- **R4.** Le provider léger s'appelle d'après ce qu'il fait (identifiant seul), plus d'après le DELETE.

## Exemples

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | PATCH `{notes: "x"}` d'un projet | arbre construit (`IndustryTreeService::buildProductionTree()` appelé) | arbre non construit ; notes enregistrées, réponse 200 |
| E2 | PATCH `{runs: 10}` d'un projet | arbre construit par le provider puis par la régénération | arbre construit une seule fois (régénération) |
| E3 | PATCH d'un projet d'un autre utilisateur | 404 | 404 |
| E4 | PATCH `{purchased: true}` d'une étape | étape mappée deux fois | étape mappée une fois (réponse du processor) |
| E5 | GET d'un projet | arbre présent | inchangé |

## Décision prise en autonomie

Renommer `ProjectDeleteProvider` et `ProjectStepDeleteProvider` en `ProjectReferenceProvider` et `ProjectStepReferenceProvider`, partagés par le DELETE et le PATCH, plutôt que d'ajouter deux providers identiques.
