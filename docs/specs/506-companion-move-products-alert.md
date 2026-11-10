# Spec #506 : alerte « déplacer N produits » du compagnon

Suite de #322 (compagnon, R4) et #319 (lieux des jobs). Mode nuit : spec validée en autonomie. Front seul : aucune route ni migration, tout vient de `jobMatches[].locations` de la BOM. Une seule règle nouvelle ; les deux vérifications en jeu de l'issue ne sont pas implémentées (voir Hors périmètre).

## Contexte vérifié sur origin/main

- `JobMatch.locations` (`blueprint`, `output`, chacun `status` `inside` | `outside` | `unknown`, `unknownReason`) n'est servi par `ProjectBomProvider` qu'au propriétaire et aux admins ; pour les autres membres il vaut `null` ou est absent.
- Une ligne de job `manufacturing` ou `reaction` porte `requiredQuantity` = runs × unités par run et `runs` = runs prévus (`IndustryProjectService`). Les autres activités ont `requiredQuantity` = runs : pas d'unités par run.
- `buildCompanionTasks` (`companionTasks.ts`) produit déjà `jobs` (mes jobs ESI, statuts `active`, `paused`, `ready`) et `alerts` (`job_ready`, `join_request`, `bom_complete`).

## Règles

- **R1 — Périmètre.** Sont évalués les jobs de `jobs` (mes personnages, statuts `active`, `paused`, `ready`) d'une ligne `manufacturing` ou `reaction` dont `locations` est présent. `locations` absent ou `null` : le job n'est évalué ni comme dehors, ni comme dedans, ni comme inconnu (comme R2 de #322 : jamais inventé).
- **R2 — Quantité d'un job.** `match.runs × line.requiredQuantity / line.runs`. Si `line.runs` est `null` ou `0`, la quantité est inconnue : le job est compté « inconnu », jamais 0.
- **R3 — Résultat.** `buildCompanionTasks` renvoie `moveProducts: { quantity, jobCount, unknownJobCount }` :
  - `outside` (`locations.output.status`) : le job ajoute sa quantité à `quantity` et 1 à `jobCount` ;
  - `inside` : ignoré ;
  - `unknown` : ajoute 1 à `unknownJobCount`, jamais à `quantity` ;
  - `locations.blueprint` n'entre pas dans la règle : seul le lieu de sortie compte.
- **R4 — Alerte.** Si `jobCount > 0`, `alerts` reçoit `{ key: 'move_products', kind: 'move_products' }` (clé stable, une seule). Elle suit R4 de #322 : pulsation ambre 3 fois sans son quand la clé apparaît, `prefers-reduced-motion` respecté, pas de nouvelle pulsation quand `quantity` change. Priorité d'affichage : `job_ready`, `move_products`, `join_request`, `bom_complete` (un produit à déplacer est une action de l'utilisateur, juste après un job à livrer).
- **R5 — Affichage.** Texte : « Déplacer {quantity} produits dans le container du projet ({count} jobs) » (pluriel géré, FR et EN, `groupIndustry.companion.alerts.moveProducts`). Barre : pastille de l'alerte la plus prioritaire (R4 de #322). Colonne : ligne d'alerte dans la section des alertes.
- **R6 — État inconnu explicite.** Si `unknownJobCount > 0`, la colonne affiche sous les alertes une ligne discrète (pas une alerte, pas de pulsation) « Lieu de sortie inconnu pour {count} jobs : à vérifier dans la BOM » (`groupIndustry.companion.moveProductsUnknown`, `data-unknown="true"`). Aucun inconnu : pas de ligne. Cette ligne n'existe pas dans la barre.
- **R7 — Pas de valeur par défaut.** Ni `?? 0`, ni catch silencieux, ni `outside` supposé par défaut.

## Exemples chiffrés (tests rouges)

Utilisateur : personnage « Alpha ». Ligne Hammerhead II `manufacturing`, `runs` 4, `requiredQuantity` 20 (5 par run).

| # | Entrée | Sortie |
|---|---|---|
| E1 | Job Alpha `ready`, 2 runs, sortie `outside` | `moveProducts` { quantity 10, jobCount 1, unknownJobCount 0 } ; alerte `move_products` |
| E2 | Deux jobs Alpha, 2 runs `outside` et 2 runs `outside` | `quantity` 20, `jobCount` 2, une seule alerte `move_products` |
| E3 | Job Alpha, sortie `inside` | `quantity` 0, `jobCount` 0, pas d'alerte |
| E4 | Job Alpha, sortie `unknown` (raison `location_unresolved`) | `unknownJobCount` 1, `quantity` 0, pas d'alerte `move_products` |
| E5 | Un job `outside` (2 runs) et un `unknown` | `quantity` 10, `jobCount` 1, `unknownJobCount` 1, alerte présente |
| E6 | Job de « Zeta » (autre joueur), sortie `outside` | ignoré : tout à 0, pas d'alerte |
| E7 | Job Alpha `delivered` ou `cancelled`, sortie `outside` | ignoré (statut hors `active`, `paused`, `ready`) |
| E8 | Job Alpha, `locations` absent (membre simple) | ignoré : ni dehors ni inconnu, `unknownJobCount` 0, pas d'alerte |
| E9 | Ligne `copy` (activité hors fabrication), sortie `outside` | ignoré (pas d'unités par run) |
| E10 | Ligne `manufacturing` avec `runs` `null`, sortie `outside` | `unknownJobCount` 1, `quantity` 0, pas d'alerte |
| E11 | Sortie `outside` mais blueprint `inside`, puis blueprint `outside` et sortie `inside` | premier : compté ; second : ignoré |
| E12 | `GroupCompanion` colonne et barre, alerte `move_products` | texte « Déplacer 10 produits dans le container du projet (1 jobs) » dans la colonne ; pastille dans la barre si c'est l'alerte la plus prioritaire ; avec aussi `job_ready`, la pastille reste `job_ready` |
| E13 | `GroupCompanion` colonne, `unknownJobCount` 2 | ligne `data-unknown="true"` « Lieu de sortie inconnu pour 2 jobs » ; absente de la barre et absente si 0 |
| E14 | Clé `move_products` apparaît | classe de pulsation ambre ; reste affichée sans pulsation si `prefers-reduced-motion` (comportement déjà testé de R4 : pas de test dédié) |

## Cas limites

- Job `outside` qui devient `delivered` : l'alerte disparaît (le statut sort du périmètre) ; l'API ne dit pas où est le produit après livraison, `output_location_id` est un attribut du job et ne bouge pas quand le pilote déplace l'objet. Compter les jobs livrés laisserait l'alerte allumée à jamais.
- Plusieurs jobs d'une même ligne : leurs quantités s'additionnent.
- Quantité d'un job non entière : impossible tant que `requiredQuantity` = runs × unités par run (arrondi non géré, non nécessaire).

## Accès et fraîcheur

Aucun contrôle nouveau : les données sont celles de la BOM déjà servie au compagnon (R6 de #322, rafraîchie à chaque événement Mercure du projet). Les membres simples ne reçoivent pas `locations` : ils n'ont ni alerte ni ligne d'inconnu (comportement hérité de #319, pas modifié ici).

## Tests existants contredits ou modifiés

Aucun attendu. À vérifier : tests qui listent exhaustivement les clés de `groupIndustry.companion.alerts` ou le type `CompanionAlertKind`, et les objets `CompanionTasks` construits à la main dans `companionFixtures.ts` (le nouveau champ `moveProducts` est obligatoire dans le type).

## Hors périmètre et questions ouvertes (en jeu, Sylvain)

- Les deux vérifications en jeu de #506 ne sont pas implémentées, aucune hypothèse codée : (1) les matériaux sont-ils pris au même lieu que le blueprint ? (la règle ne lit que le lieu de sortie) ; (2) `output_location_id` d'un job corpo désigne-t-il le container, l'office ou un hangar de division ? Si la réponse change la règle `inside` / `outside` de #319, R3 s'appuiera sur le nouveau statut sans changer cette spec.
- Les jobs `delivered` (voir cas limites), les activités hors fabrication, l'alerte pour les membres simples (sans `locations`), un compteur de produits déjà déplacés (inconnu de l'API).

## Termes du glossaire

**Compagnon de projet** : ajout de l'alerte « déplacer N produits » (#506) à la liste des alertes.
