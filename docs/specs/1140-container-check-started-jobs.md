# Spec #1140 — la vérification du container déduit les matériaux consommés par les jobs lancés

Mode nuit : spec validée en autonomie, décisions notées en bas.

## Contexte

`GET /industry/projects/{projectId}/container-verification` (`ContainerVerificationProvider` → `ProjectContainerService::verifyContainer`) compare, pour chaque ligne matériau et chaque ligne de job `component`, la quantité trouvée dans les containers du projet à `IndustryProjectBomLine::getRequiredQuantity()`. Un job lancé a retiré ses matériaux du container : la ligne passe `partial` à tort (exemple observé : 34 987 en container pour 38 200 requis, 33 runs d'une réaction à 100 par run, bonus de raffinerie compris, soit 3 213 consommés).

## Règles

1. **Job lancé** : un `IndustryProjectJobMatch` d'une ligne de job (`component` ou `final`, mais pas `copy`, `invention` ni recherche : seuls les jobs du plan ont des besoins) dont le statut n'est ni `cancelled` ni `reverted` (`CachedIndustryJob::STATUSES_WITHOUT_OUTPUT`), donc `active`, `paused`, `ready`, `delivered`. Un job non lié au projet ne consomme rien de son côté.
2. **Quantité consommée par ligne de job** : pour chaque matériau du besoin de la ligne (`JobMaterialNeedsMapper::map`, mêmes quantités par run que le plan : ME, structure, bonus de rig), `intdiv(besoin × min(runs lancés, runs de la ligne), runs de la ligne)`, où « runs lancés » est la somme des `getRuns()` des matchs de la règle 1.
3. **Quantité consommée par type** : somme des quantités consommées de toutes les lignes de job du projet, par `typeId` (un composant fabriqué consommé par un job parent est un matériau de ce job : même calcul).
4. **Quantité attendue d'une ligne** : `max(0, requiredQuantity − consommé du type)`. Statut : `verified` si `containerQuantity >= attendue`, `partial` si `0 < containerQuantity < attendue`, `missing` sinon. Une attendue à 0 est `verified`.
5. **Inconnu, jamais une valeur plausible** : si au moins une ligne de job a un match de la règle 1 et que sa consommation ne peut pas être calculée, toutes les lignes reçoivent le statut `unknown`, `containerQuantity` `null` et la raison `job_consumption_unknown`. Deux causes : le plan du propriétaire est indisponible (`ProjectJobMaterialNeedsLoader::load` renvoie `null`), ou la ligne de job démarrée n'a pas d'entrée dans les besoins (`JobMaterialNeedsMapper` ne l'a pas rapprochée d'un job du plan). Sans aucun match démarré, le plan n'est pas chargé et le comportement actuel est inchangé (même avec un plan indisponible).
6. **Priorité des états** : les états actuels du container (propriétaire sans corporation, directeur absent, assets non synchronisés, division masquée, container introuvable, assets périmés) passent avant la règle 5 ; la consommation n'est lue qu'une fois le container trouvé.
7. **Ressource** : `ProjectContainerVerificationResource` garde `requiredQuantity` (BOM) et gagne `consumedQuantity` (int, 0 sans job lancé, `null` quand le statut est `unknown` ou `container_*`). Le front affiche `containerQuantity / (requiredQuantity − consumedQuantity)` et, si `consumedQuantity > 0`, la mention « {n} used by started jobs ».

## Exemples chiffrés

| # | Ligne (requis) | Container | Jobs lancés sur le projet | Consommé | Attendue | Statut |
|---|---|---|---|---|---|---|
| 1 | Cadmium 38 200 | 34 987 | réaction ×33, 100 Cadmium par run, bonus 3 213 au total (match `active`, runs 33 sur 33) | 3 213 | 34 987 | `verified` |
| 2 | Cadmium 38 200 | 34 986 | idem | 3 213 | 34 987 | `partial` |
| 3 | Cadmium 38 200 | 38 200 | aucun | 0 | 38 200 | `verified` |
| 4 | Cadmium 38 200 | 0 | aucun | 0 | 38 200 | `missing` |
| 5 | Cadmium 1 000 | 0 | job ligne 10 runs, besoin 1 000 pour 10 runs, match `delivered` 10 runs | 1 000 | 0 | `verified` |
| 6 | Cadmium 1 000 | 600 | job ligne 10 runs, besoin 1 000, match `active` 4 runs | 400 | 600 | `verified` |
| 7 | Cadmium 1 000 | 600 | job ligne 10 runs, besoin 1 000, matchs `active` 4 runs + `ready` 3 runs | 700 | 300 | `verified` |
| 8 | Cadmium 1 000 | 900 | job ligne 10 runs, match `cancelled` 10 runs | 0 | 1 000 | `partial` |
| 9 | Cadmium 1 000 | 900 | job ligne 10 runs, match `reverted` 10 runs | 0 | 1 000 | `partial` |
| 10 | Cadmium 1 000 | 600 | deux jobs lignes (besoin 1 000 pour 10 runs et 300 pour 3 runs), matchs `active` 10 runs et 3 runs | 1 300 | 0 (plafonné) | `verified` |
| 11 | Cadmium 1 000 | 500 | job ligne 3 runs, besoin 100 pour 3 runs, match `active` 1 run | 33 (`intdiv(100×1, 3)`) | 967 | `partial` |
| 12 | Cadmium 1 000 | 600 | match démarré, plan du propriétaire indisponible | — | — | `unknown`, `job_consumption_unknown`, `containerQuantity` `null` |
| 13 | Cadmium 1 000 | 600 | match démarré sur une ligne absente des besoins | — | — | idem |
| 14 | Cadmium 1 000 | 600 | aucun match démarré, plan indisponible | 0 | 1 000 | `partial` (inchangé) |
| 15 | composant T 50 (ligne job `component`) | 20 | job parent lancé qui consomme 30 de T | 30 | 20 | `verified` |

Les besoins des lignes 5 à 11 viennent de `ProjectJobMaterialNeedsLoader` (stub dans les tests unitaires).

## Cas limites

- Match dont les runs dépassent ceux de la ligne : plafonné à `runs de la ligne` (exemple 10).
- Ligne de job à `runs === 0` (couverte par stock, `isCoveredByStock`) : aucun besoin, aucune consommation, et pas d'entrée inconnue pour elle.
- Ligne de job sans runs (`getRuns() === null`) portant un match démarré : `UnexpectedValueException`, comme `ChainMaterialSupply::deliveredProductionOf`.
- Aucune ligne matériau ou aucun nom de container : `[]`, plan non chargé (inchangé).

## Accès et fraîcheur

Aucun nouveau point d'entrée : même provider, `assertAcceptedMember`, mêmes données. Le plan est celui du propriétaire (`planOf($project, $project->getOwner())`), déjà utilisé par `/chain`. Les matchs viennent de la base (lecture, rien d'ESI en direct).

## Hors périmètre

- La couverture par stock de container des lignes matériaux (#866, `ProjectLineFulfillments::fulfilledOf`) et de `ChainMaterialSupply` : elle perd aussi les unités consommées, mais la corriger ajoute un `planOf` à chaque lecture du cockpit, de la BOM, de la chaîne et de l'approvisionnement, déjà saturés (#1136). À traiter dans un lot à part ; le lecteur de consommation de ce lot est réutilisable.
- `containerStockOf`, stock non réclamé, confirmation de livraison, propositions de stock : inchangés.
- Matchs `delivered` d'un job `component` qui ajoutent le produit au container : inchangé (le container les contient déjà).

## Tests existants modifiés

`tests/Unit/Service/Industry/Project/ProjectContainerServiceTest.php` (+ `ProjectContainerServiceContainerStockTest.php`, `tests/Integration/Service/Industry/Project/ProjectContainerServiceTest.php`) : le constructeur de `ProjectContainerService` gagne un lecteur de consommation ; les harnais le fournissent à « aucun job lancé ».

## Termes du glossaire

Ajout : **Consommation des jobs lancés** (Materials consumed by started jobs) : quantité d'un type retirée du container par les jobs liés non annulés du projet, au prorata des runs lancés, sur les besoins du plan ; déduite de la quantité attendue par la vérification du container ; inconnue (`job_consumption_unknown`) si le plan ne la donne pas.

## Décisions (mode nuit)

- `paused` compte comme lancé (EVE retire les matériaux au démarrage), `cancelled` et `reverted` non (ils sont dans `STATUSES_WITHOUT_OUTPUT`, la ligne reste à refaire) ; l'issue ne cite que `active`, `ready`, `delivered`.
- Inconnu global plutôt que par matériau : sans plan, on ne sait pas quels types un job lancé a consommé.
- #866 laissé à un lot suivant (voir hors périmètre) : à confirmer par Sylvain.
