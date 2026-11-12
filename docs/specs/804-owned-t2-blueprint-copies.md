# Spec #804 : BPC T2 possédés, proposés puis déclarés sur le projet

Mode nuit (2026-11-13) : spec rédigée sans gate. Décision de Sylvain (commentaire du 2026-11-12) : option 1, circuit l. Même mécanique que #708 : lecture seule d'abord, rien n'est déduit avant la validation, qui persiste une donnée lue par la génération du plan.

## Contexte

`IndustryProjectService::replaceT2BlueprintCopiesByInventions` ajoute une ligne `invention`, une copie T1 et des datacores pour tout produit T2 sans regarder les BPC T2 que le propriétaire possède déjà. `copyLinesOf` ne connaît que les originaux (`OriginalBlueprintFinder::typeIdsOwnedBy`). Un joueur qui détient les BPC voit donc un plan qui lui fait réinventer.

## Règles

1. **Donnée déclarée** : un **BPC T2 déclaré** = (projet, produit T2, runs ≥ 1), unique par (projet, produit). Nouvelle entité `IndustryProjectDeclaredBlueprintCopy` (table `industry_project_declared_blueprint_copies`, FK projet `ON DELETE CASCADE`, collection `orphanRemoval` sur `IndustryProject`) + migration.
2. **Plan** : pour chaque produit T2 inventé du plan, `runsFabriqués` = somme des runs des lignes de fabrication du produit (calcul actuel), `runsCouverts = min(runs déclarés, runsFabriqués)`, `runsAInventer = runsFabriqués − runsCouverts`. Tentatives = `ceil(runsAInventer / (baseRuns × probabilité))` (arrondi inchangé). La ligne `<produit>:copy` (copie du T2) est retirée dans tous les cas, comme aujourd'hui.
3. **Couverture complète** (`runsAInventer = 0`) : aucune ligne `invention`, aucune copie T1 ajoutée pour ce produit, aucun datacore ajouté pour ce produit, et la probabilité d'invention n'est pas exigée (pas de `InventionProbabilityMissingException` pour ce produit). Une copie T1 partagée avec un autre produit garde les runs de l'autre.
4. **Couverture partielle** : seules les tentatives de `runsAInventer` sont planifiées ; copie T1 (runs = tentatives) et datacores (quantité × tentatives) suivent.
5. **Déclaration sans effet** : une déclaration dont le produit n'est plus un produit T2 inventé du plan est ignorée par le plan et conservée en base.
6. **Proposition** : `GET /industry/projects/{projectId}/t2-blueprint-copy-proposal` (ressource `ProjectT2BlueprintCopyProposal`, `id` = id du projet), lecture seule, `ProjectAccessChecker::assertOwner` (autre membre 403, projet inconnu 404). Aucune écriture, aucun évènement Mercure (action synchrone).
7. **État** (`state`) : `not_solo` (`!isSoloProjectOf($owner)` ou projet `Completed`, `lines = []`) ; `assets_unknown` (`lines = []`, `unknownReason`) ; `ready`. Jamais 0 pour « inconnu ». Raisons : `blueprints_not_synced` (aucun `OwnedBlueprint` pour aucun personnage du propriétaire), puis, si `containerName` est renseigné, la raison de `ProjectContainerService::containerStockOf` (`container_not_found`, `no_director`, `corporation_assets_not_synced`, `no_visible_division`, `owner_without_corporation`). Sans `containerName` : pas de container, pas d'état inconnu.
8. **Sources de runs** : BPC (`OwnedBlueprint::isCopy()`) du produit, runs = `OwnedBlueprint::getRuns()` (le sync blueprints donne les runs, les assets non). Personnel : copies des personnages du propriétaire. Container : copies corporatives dont `itemId` est un contenu du container du projet (sous-conteneurs compris) ; pour cela `ProjectContainerStock` expose en plus les `itemId` du contenu (`contentItemIds`, valeur par défaut `[]`). Le blueprint d'un produit est celui qui le fabrique (`IndustryActivityProductRepository::producerOf`).
9. **Lignes** : une par produit T2 inventé du plan, listée seulement si `availableRuns > 0`. Contenu : `productTypeId`, `typeName`, `manufacturingRuns`, `personalRuns`, `containerRuns` (`null` sans container), `availableRuns` (= personnel + container), `proposedRuns = min(availableRuns, manufacturingRuns)`, `declaredRuns` (0 si non déclaré). Ordre : `typeName` croissant. Les runs déclarés ne sont pas retranchés du stock (c'est la même détention) : la proposition reste stable après validation.
10. **Validation** : `POST /industry/projects/{projectId}/t2-blueprint-copies`, propriétaire seul, corps `{copies: [{productTypeId, runs}]}`. Remplace tout l'ensemble déclaré du projet (liste vide = tout retirer). Chaque entrée doit figurer dans les lignes de la proposition et avoir `1 ≤ runs ≤ proposedRuns`, sinon 400 avec message ; état de la proposition ≠ `ready` : 400. Après persistance, la BOM est recalculée (`buildBom`, `refuseToDropWorkedLines: true` hors brouillon) ; un refus (`RuntimeException`) devient 400 et **rien n'est persisté** (tout ou rien). La réponse est la proposition relue.
11. **Affichage** : panneau « BPC T2 possédés » dans le détail du projet, même emplacement et même identité HUD que `IntermediateStockProposal.vue` : ouvert sur un projet `draft` solo dès que `lines` est non vide, replié derrière un bouton sur les autres statuts (état `ready` seulement). Ligne cochable avec runs éditables (entier de 1 à `proposedRuns`, valeur initiale `declaredRuns` si > 0, sinon `proposedRuns`) ; une ligne déjà déclarée est cochée. Bouton « Déclarer » (actif dès que la sélection diffère de l'ensemble déclaré, y compris vide pour tout retirer) envoie l'ensemble coché, puis recharge la BOM et la proposition ; erreur 400 : message du serveur affiché, rien n'est modifié. `assets_unknown` : message avec raison traduite ; `ready` sans ligne : « aucun BPC T2 possédé pour ce plan » ; `not_solo` : rien. Noms longs tronqués avec `title`. Textes en FR et EN.
12. **Valorisation** : aucun montant dans le panneau. Le coût du plan baisse parce que l'invention, la copie T1 et les datacores disparaissent de la BOM ; le BPC possédé est supposé sans coût (déjà détenu).

## Exemples chiffrés (`attempts(n) = ceil(n / (baseRuns × p))`)

| Cas | Entrée | Attendu |
|---|---|---|
| Couverture complète (issue) | 15 runs fabriqués, propriétaire : 15 runs de BPC T2, déclaré 15 | pas de ligne `invention`, pas de copie T1, pas de datacore, pas de ligne `<produit>:copy` |
| Couverture partielle | 15 runs, déclaré 6 | invention `attempts(9)` ; copie T1 = `attempts(9)` runs ; datacores = quantité × `attempts(9)` |
| Rien déclaré | 15 runs, BPC possédés non déclarés | plan identique à aujourd'hui (`attempts(15)`) |
| Déclaré au-dessus des runs fabriqués | `proposedRuns` plafonné à 15, déclaré 15 sur un plan à 15 | couverture complète |
| Plusieurs BPC | personnel 4 + 6 runs, container 3 runs, 15 runs fabriqués | `availableRuns = 13`, `proposedRuns = 13`, `containerRuns = 3` |
| Plafond par la fabrication | 40 runs possédés, 15 fabriqués | `proposedRuns = 15` |
| Runs déclarés > proposés | `runs = 14` pour `proposedRuns = 13` | 400, rien persisté |
| Produit T1 | une ligne T1 sans invention | jamais listé ; déclaration refusée (400) |
| Copie T1 partagée | deux produits T2 sur le même T1, un couvert, l'autre non | copie T1 = tentatives de l'autre seulement |
| Blueprints jamais synchronisés | aucun `OwnedBlueprint` | `assets_unknown`, `blueprints_not_synced`, `lines = []` |
| Container nommé introuvable | `containerName` renseigné, absent | `assets_unknown`, `container_not_found` |
| Projet de groupe ou terminé | membre accepté autre que le propriétaire | `not_solo`, `lines = []` |
| Membre non propriétaire | GET ou POST | 403 |
| Refus de recalcul | projet publié, l'invention retirée porte une activité travaillée | 400, ensemble déclaré inchangé |
| Retirer | POST `copies: []` | déclarations supprimées, BOM recalculée comme avant |

## Cas limites

- Produit à plusieurs unités par run : sans effet, les runs sont des runs de blueprint.
- Une copie de runs très supérieurs à la demande ne couvre que `runsFabriqués` : le surplus reste à son propriétaire.
- Les copies déjà consommées par un job en cours ne sont pas déduites : la proposition lit le dernier sync, le joueur valide.
- Un BPC T2 dans le container mais absent du sync blueprints corporatif n'est pas compté (runs inconnus, jamais supposés).
- Fraîcheur : dernier sync blueprints et dernier cache d'assets, aucune synchronisation déclenchée par la lecture.

## Accès

Propriétaire seul. Copies personnelles : celles des personnages du propriétaire (`OwnedBlueprintRepository::findByCharacters`). Copies corporatives : seulement celles dont l'`itemId` est dans le container visible du propriétaire (`VisibleCorporationAssetsFinder` via `ProjectContainerService`), jamais les copies corporatives hors container. Aucun identifiant reçu d'un autre périmètre hors `productTypeId` validé contre la proposition.

## Tests existants contredits ou modifiés

Aucun attendu : le comportement par défaut (rien déclaré) est inchangé. À vérifier au rouge : `IndustryProjectServiceOwnedBlueprintTest`, `IndustryProjectServiceOwnedEfficiencyTest`, les tests de `replaceT2BlueprintCopiesByInventions`, et tout test qui construit `ProjectContainerStock` ou stubbe `IndustryProjectDeclaredBlueprintCopy`.

## Hors périmètre

Consommation des runs d'un BPC déclaré (jobs lancés, copies épuisées), partage des mêmes copies entre deux projets du propriétaire (limite connue : chaque projet peut déclarer les mêmes runs), projet de groupe, copies corporatives hors container, choix d'un BPC précis (ME/TE, `OwnedBlueprintUse` inchangé), ligne de BOM « BPC possédé », produits T1.

## Décisions (mode nuit)

- D1 : solo seulement, comme #708 (états `not_solo`).
- D2 : sources = copies personnelles + copies du container du projet.
- D3 : la validation remplace l'ensemble (un seul POST idempotent).
- D4 : couverture complète : probabilité d'invention non exigée pour ce produit.

## Glossaire

- **BPC T2 déclaré** (Declared T2 blueprint copy) : runs de BPC T2 que le propriétaire a validés sur un projet solo pour un produit T2. Le plan les retranche des runs à inventer (invention, copie T1, datacores). Voir **Proposition de BPC T2**.
- **Proposition de BPC T2** (T2 blueprint copy proposal) : lecture seule des copies T2 du propriétaire (sync blueprints personnel, plus container du projet), par produit T2 du plan : runs proposés = min(runs disponibles, runs fabriqués). Rien n'est déduit avant la validation (spec #804).
