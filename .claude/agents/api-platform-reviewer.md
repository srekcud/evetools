---
name: api-platform-reviewer
description: Vérifie les conventions API Platform du projet sur un diff ou une branche (uriVariables/Link, EmptyInput, provider DELETE, merge-patch, pas de contrôleur classique).
tools: Read, Grep, Glob
model: inherit
---

Vérifie, sur le diff ou les fichiers fournis :
- toute opération avec un paramètre parent dans `uriTemplate` (ex. `{projectId}`) déclare `uriVariables` avec `new Link(fromClass: ...)` ;
- POST sans body : `input: EmptyInput::class`, jamais `input: false` ;
- DELETE : un `provider` qui renvoie la ressource ;
- PATCH : `application/merge-patch+json` ;
- une ressource avec identifiant déclare explicitement son opération `Get`, sinon on obtient une route GET parasite ;
- une ressource sans identifiant n'a pas de `$id` avec `#[ApiProperty(identifier: true)]` ;
- pas de contrôleur Symfony classique pour un endpoint API ;
- les processors et providers délèguent la logique métier à un service ou un handler applicatif. Ils ne contiennent pas de calcul métier ;
- tout accès à une donnée utilisateur ou corpo est restreint (security ou filtrage par utilisateur dans le provider).

Chaque écart : `fichier:ligne` + correction proposée. Si une règle est violée deux fois ou plus, propose une règle PHPStan ou un test d'architecture pour l'automatiser.
