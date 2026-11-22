# #1306 — Mes projets : rôle, équipe, avancement du flux, qui est attendu

Circuit `m` (label `s` sous-estimé : nouvelle ressource API qui expose l'équipe et les attendus des autres participants, calcul par projet de l'état par station, réécriture de la liste). Mode nuit, décisions prises sur le prototype (`projects()`, colonnes `COLS`, « Qui est attendu ») et l'issue. Prérequis sur `feat/industry-flow` : #1294 (`ProjectStationQuantities`, 7c045ce5c).

## Règles

R1. **Ressource** `IndustryProjectOverview`, `GET /industry/projects/overview` (`uriTemplate` fixe, aucune variable), un élément par projet dont l'utilisateur est participant accepté (même ensemble que `GET /industry/projects`). Un participant en attente ou retiré n'y a rien.  Même ordre que `GET /industry/projects`.

R2. **Identité.** `projectId`, `myRole` (`owner`, `admin`, `member`), `ownerName` (`PilotName::of`, le main, jamais un alt), `team` : les autres participants acceptés (propriétaire exclu), `[{name, role}]`, noms par `PilotName::of`.

R3. **Flux.** `flow` : `{toBring, onSite, inProduction, out, done}` (entiers) ; `flowReason` ; `flowUnknown` (entier). Calculé pour les projets `published`, `in_progress` et `selling` ; pour `draft` : `flow = null`, `flowReason = 'not_started'` ; pour `completed` : `flow = null`, `flowReason = 'completed'`.
- Source des cinq premiers : `ProjectStationQuantities::of($project)`. Une **carte** = un couple (type, station) d'une `StationQuantity`. `toBring` : cartes avec `toBring > 0` ou `inTransit > 0` (la colonne « À apporter » contient ce qui est en route, comme le prototype) ; `onSite` : `onSite > 0` ; `inProduction` : `inProduction > 0` ; `out` : `out > 0`. Une carte peut compter dans plusieurs colonnes (stock sur place et reste à apporter), le consommé n'est pas une colonne.
- `done` : nombre de types distincts du projet ayant au moins une vente enregistrée (`IndustryProjectSale`, `quantity > 0`). Un projet à usage personnel (« remis ») n'a pas de vente : `done = 0` (pas de lecture de la remise, voir hors périmètre).
- Illisible, jamais 0 : un type dont `stations` est `null` met `flow = null` et `flowReason = unknownReason` du type (`plan_unavailable`, `consumption_unknown`) ; une valeur `null` (`toBring`, `inTransit`, `onSite`, `out`) d'une carte n'est comptée dans aucune colonne et ajoute 1 à `flowUnknown` (une fois par carte).

R4. **Qui est attendu.** `awaited` : `[{name, mine, count}]`, un élément par participant accepté (propriétaire compris) qui a `count > 0` actions sur ce projet, `count` = nombre d'actions que lui donnent les sources d'actions (`ProjectActionSource`, mêmes règles que la file de l'utilisateur : lancer un job, s'approvisionner, relire les apports, traiter les demandes). `mine` vrai pour l'utilisateur connecté, qui passe en premier, puis `count` décroissant, puis nom. Un projet terminé : liste vide. Une liste vide n'est pas « personne » : le front affiche « ESI (jobs, couriers) », comme le prototype (l'attente vient d'un job ou d'un courier, pas d'un participant).
- Service : `UserActionReader::countsOfProject(IndustryProject, list<User>): list<int>` (un entier par utilisateur donné, dans le même ordre), qui n'appelle ni la position des personnages ni le tri.

R5. **Accès.** Authentifié ; le résultat ne contient que les projets du connecté et seulement les noms de joueurs que le projet montre déjà aux participants (main, jamais un alt, `PilotName`). Aucun autre utilisateur ne voit l'état d'un projet qui n'est pas le sien.

R6. **Front : page `/industry/projects` (`GroupIndustryList.vue`).** Les onglets « Mes projets » et « Disponibles » sont conservés (l'entrée de navigation `?tab=available` et ses tests existent ; écart à l'issue « disponibles en dessous », noté au journal). Dans « Mes projets », la grille de `ProjectCard` est remplacée par le tableau ; les modales, les pastilles de statut, les KPI et le panneau des jobs à attribuer sont conservés. Le tableau suit le prototype : colonnes **Projet** (icône du premier produit, nom, résumé `N items · M runs`), **Vous** (rôle : Propriétaire en cyan, Admin et Membre en gris), **Propriétaire · équipe**, cinq colonnes **À apporter**, **Sur place**, **En fabrication**, **Sorti**, **Vendu / remis** (nombre, tuile `h1` si > 0 sinon `h0`), **Qui est attendu** (« vous N » en alerte, « Nom N » en gris, séparés par ` · `, « ESI (jobs, couriers) » si vide, « clôturé » si projet terminé). Cliquer une ligne ouvre le projet (`industry-project`). Flux illisible : « ? » avec la cause en infobulle ; `flowUnknown > 0` : « + N ? » à côté du nombre ; brouillon : « — ». Un échec de lecture de l'aperçu n'empêche pas la liste : les cellules du flux montrent « ? » et la cause.

## Exemples

Projet en cours, un seul produit, station Azbel et Tatara :

| Cas | Entrée | Attendu |
|---|---|---|
| Comptes | cartes : (Tritanium, Azbel) toBring 500 ; (Tritanium, Tatara) onSite 200 ; (Hull, Azbel) inProduction 3 | `toBring 1`, `onSite 1`, `inProduction 1`, `out 0` |
| En route | carte `toBring 0`, `inTransit 40` | compte dans `toBring` |
| Plusieurs colonnes | carte `onSite 10`, `toBring 5` | une carte dans `onSite` et une dans `toBring` |
| Inconnu | carte `onSite null`, `toBring 5` | `toBring 1`, `onSite 0`, `flowUnknown 1` |
| Plan illisible | type avec `stations null`, `plan_unavailable` | `flow null`, `flowReason plan_unavailable` |
| Brouillon | status `draft` | `flow null`, `flowReason not_started`, aucun appel à `ProjectStationQuantities` |
| Terminé | status `completed` | `flow null`, `flowReason completed`, `awaited []` |
| Ventes | deux ventes du même type, une d'un autre | `done 2` |
| Attendus | moi 3 actions, Kaelen 1, Mira 0 | `[{moi, mine true, 3}, {Kaelen, false, 1}]` |
| Rôle | je suis admin d'un projet d'un autre | `myRole admin`, `ownerName` = main du propriétaire |
| Autre projet | projet où je suis `pending` | absent |
| Alt | propriétaire sans main | `ownerName` « Unknown » |

## Cas limites

- Un participant accepté sans personnage principal : « Unknown » (`PilotName`).
- Un projet sans conteneur : `ProjectStationQuantities` répond son propre état (cartes de besoin, `onSite null`) : le front le montre tel quel.
- Un projet dont le calcul lève une exception : l'exception remonte (500), pas de valeur par défaut.
- Coût : un calcul par projet non brouillon et non terminé (lecture des assets en base, pas d'appel ESI) ; les actions sont comptées une fois par participant accepté.

## Hors périmètre

Le tri et les filtres de la liste, la lecture de « remis » d'un projet à usage personnel, la file d'actions de l'Atelier, le kanban du projet, un nouveau topic Mercure (la page se relit à l'ouverture et au retour sur l'onglet, comme aujourd'hui).

## Décisions

D1. Ressource séparée de `GET /industry/projects` : le calcul du flux est lourd, la liste reste utilisée par les sélecteurs et les KPI.
D2. `done` = types vendus : l'état par station (#1294) ne couvre pas les produits ; refus de recompter des unités, une carte vendue = un type.
