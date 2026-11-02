# Spec #202 Q1 — Refuser la suppression qui laisserait une étape de matériau sans consommateur

**Statut : rédigée le 2026-11-01.** Suite de la [spec #202](202-delete-step-rules.md) (implémentée et fusionnée). Sylvain a tranché Q1 le 2026-11-01 : **option A, refuser** (400, et `deletable` à faux côté ressource). Q2 (job fixé supprimé) : cascade inchangée, hors de ce lot.

Circuit : `circuit:m` (pas de note de câblage). Harnais disponibles : `DeleteStepProcessorTest` (trait `BuildsStepProcessorsOnGoldenSde`, `materialRepositoryOnGoldenSde()`, `flush` comptés), `IndustryResourceMapperDeletableStepTest` (`AutowiresByType`).

## Contexte

Supprimer la seule étape d'un produit laisse sans consommateur les étapes de ses matériaux qui n'en avaient pas d'autre (D-E12 de #202). La cascade ne les écrit plus (`IndustryStepCalculator` n'écrit que les produits qui reçoivent un besoin) : elles gardent runs et quantité figés. `ProductionStepNeedsResolver::resolve()` les traite alors comme des racines (l. 65 : `$isRoot`) : stock ignoré (l. 67), besoin = quantité persistée, matériaux achetés par la liste d'achats et arbre affiché avec une racine de plus.

État vérifié sur `fix/group-production-tree` 00beb525 :

| Site | Aujourd'hui |
|---|---|
| `IndustryProject::refusalToDeleteStep()` (l. 447-462) | R1 job matché, R2 dernière étape du produit du projet, R3 étape seule engagée ; lit le projet et ses étapes, sans SDE ni requête |
| `DeleteStepProcessor::process()` (l. 56-60) | appelle `refusalToDeleteStep()`, 400 avec le message, avant toute écriture ; dépendances : `Security`, deux repositories, `IndustryStepCalculator`, `EntityManagerInterface` |
| `IndustryResourceMapper::stepToResource()` (l. 238) | `deletable = refusalToDeleteStep($step) === null` ; appelé par `ProjectProvider` et par les processors PATCH, POST, découpe, fusion, liaison, match ; aucune dépendance SDE matériaux ; memo `isT2Cache` par type (l. 29) |
| `ProductionStepGraph` | consommateur = étape de production (activité autre que `copy`) dont les matériaux SDE (`findMaterialEntitiesForBlueprints`, clé `blueprintTypeId-activityId`) contiennent le produit d'une étape de production (`stepMaterialsOf()`) ; la marque « acheté » et le stock n'y jouent aucun rôle |
| `UpdateStepProcessor::update()` (l. 84-90) | refuse « acheté » et stock sur une étape de profondeur 0 (`Cannot mark root products as purchased`) |
| `StepTree.vue` (l. 898) | bouton supprimer si `step.deletable && !readonly` ; aucun changement nécessaire |

## Règles métier

Notations de #202 (étape supprimée, groupe, étape de production). **Étape de matériau** de l'étape supprimée = étape de production dont le produit est un matériau SDE de l'étape supprimée (`ProductionStepGraph::stepMaterialsOf()`). **Consommateur** = au sens de `ProductionStepGraph` ci-dessus.

- **O1 — Refus.** Une étape de production n'est pas supprimable si, une fois retirée, au moins une de ses étapes de matériau n'a plus aucun consommateur parmi les étapes de production restantes : **400** `Cannot delete a step whose material steps have no other consumer; delete them first`.
- **O2 — Ce qui compte, ce qui ne compte pas.**
  - (a) Le déclencheur porte sur les **matériaux** de l'étape supprimée, pas sur ses consommateurs : supprimer la seule étape d'un produit consommé reste permis tant que ses étapes de matériau gardent un autre consommateur (R10 e de #202 inchangé, D-E9).
  - (b) Seule la **perte** d'un consommateur compte : une étape de production qui n'en avait déjà aucun (racine, produit ajouté depuis un type, orpheline héritée) ne déclenche rien.
  - (c) Un autre job du même produit (même groupe découpé, ou autre `splitGroupId` du même produit) a les mêmes matériaux : il reste consommateur, la suppression d'un job d'un groupe n'est jamais refusée par O1 (reprise R6 de #202 inchangée).
  - (d) Marque « acheté » et stock n'interviennent pas, ni sur l'étape de matériau ni sur ses autres consommateurs : c'est la règle du graphe. Une étape de matériau achetée ou en stock laissée orpheline serait comptée comme une racine (`$isRoot`, stock ignoré l. 67) : même refus.
  - (e) Une étape `copy` n'est ni consommateur ni étape de matériau : la supprimer n'est jamais refusé par O1, et une copie laissée sans fabrication reste hors de ce lot (#235, comportement de #202).
  - (f) Un blueprint d'étape de production du projet absent du SDE (aucune ligne de matériau pour sa clé `blueprintTypeId-activityId`) est une erreur explicite, jamais une liste vide : `StepMaterialsMissingFromSdeException` levée par `StepMaterialTypeIdsReader`, sans repli. `DeleteStepProcessor` répond **400** `Cannot delete a step while the materials of a blueprint of the project are missing from the SDE`, sans écriture ni `flush` (O4) ; `deletable` vaut `false` pour les étapes du projet et la lecture du projet ne casse pas. Décision de Sylvain du 2026-11-02 (règle de CLAUDE.md : pas de défaut plausible sur une donnée métier), qui remplace la lecture « non compté » de la cascade pour ce refus ; la cascade (`stepMaterialsOf()`) n'est pas modifiée.
- **O3 — Ordre.** 401, 404 projet, 404 étape, R1, R2, R3, **O1**, puis la cascade (R11). Une étape seule engagée (achat, stock, « acheté ») reçoit le message de R3 ; une étape liée à un job ESI celui de R1.
- **O4 — Rien d'écrit.** Comme R5 de #202 : O1 est vérifié avant toute modification, 0 `flush`.
- **O5 — Une seule règle.** O1 s'ajoute à `IndustryProject::refusalToDeleteStep()`, seule source du refus (`DeleteStepProcessor`) et de `deletable` (`IndustryResourceMapper::stepToResource()`). L'entité ne fait aucune requête : elle reçoit les matériaux des étapes de production du projet, lus du SDE par l'appelant.
- **O6 — Lecture du SDE.** `DeleteStepProcessor` : une lecture des matériaux pour le projet. `IndustryResourceMapper` : au plus une lecture par réponse pour toutes les étapes d'un projet (GET d'un projet = une requête de plus), quel que soit l'appelant de `stepToResource()`.
- **O7 — Front.** Aucun changement : le bouton suit déjà `deletable` (R14 de #202). Le message de refus n'est affiché que si un job ou une étape change entre l'affichage et le clic ; il s'affiche en anglais dans le bandeau comme les refus voisins. **Aucune clé i18n.**
- **O8 — Chemin de l'utilisateur.** Supprimer d'abord les étapes de matériau (de bas en haut), ou, pour une étape de profondeur > 0, la marquer « acheté » (le résolveur ramène alors à 0 les runs effectifs de l'étape et le besoin de ses matériaux, l. 72-91). Le message ne propose pas la marque « acheté » : elle est refusée en profondeur 0 (`UpdateStepProcessor` l. 84), où se trouvent les produits ajoutés depuis un type.

### Contrat de l'API

| Cas | Avant | Après |
|---|---|---|
| DELETE d'une étape seule dont une étape de matériau n'a pas d'autre consommateur | 204, étape de matériau orpheline (runs et quantité figés) | 400 `Cannot delete a step whose material steps have no other consumer; delete them first` |
| `deletable` de cette étape | `true` | `false` |
| Tous les autres cas de #202 | — | inchangés |

## Exemples chiffrés

SDE golden (`tests/Fixtures/Golden/sde-blueprint-recipes.json`), sans structure, blueprints synthétiques comme dans #202. Recettes utilisées : Quantum Microprocessor (fabrication, 1 par run ; 17 Titanium Carbide et 6 Phenolic Composites par run), Titanium Carbide (réaction, 10 000 par run ; 5 Oxygen Fuel Block par run), Phenolic Composites (réaction, 2 200 par run ; 5 Oxygen Fuel Block par run), Oxygen Fuel Block (fabrication, 40 par run). Colonnes `[runs, quantity]`.

- **QM** (D-E12 de #202) : produit du projet Quantum Microprocessor, racine `[32, 32]` ME 10 ; Titanium Carbide `[1, 490]` ; Oxygen Fuel Block `[1, 5]`.
- **QM-P** : QM + Phenolic Composites `[1, 173]` profondeur 1 (6 × 32 × 0,9 = 172,8 → 173) ; Oxygen Fuel Block `[1, 10]` (5 + 5).
- **FC4** (fixture `ferniteCarbideProjectOfFourRuns()` de #202).

### `DeleteStepProcessorTest`

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| O-E1 | Cas de Q1. QM, DELETE Titanium Carbide | 204, Oxygen Fuel Block `[1, 5]` orphelin | 400 O1 ; 0 `flush` ; 3 étapes inchangées |
| O-E2 | Autre consommateur. QM-P, DELETE Titanium Carbide | 204 | 204 ; Quantum Microprocessor `[32, 32]`, Phenolic Composites `[1, 173]`, Oxygen Fuel Block `[1, 5]` |
| O-E3 | Étape de matériau achetée (O2 d). QM, Oxygen Fuel Block marqué « acheté » ; variante : 5 en stock | 204 | 400 O1 ; 0 `flush` |
| O-E4 | Produit ajouté depuis un type (O8). FC4 + Phenolic Composites `[4, 8 800]` profondeur 0 + Oxygen Fuel Block `[1, 20]` profondeur 1 ; DELETE Phenolic Composites | 204, Oxygen Fuel Block orphelin | 400 O1 |
| O-E5 | Ordre (O3). QM, Titanium Carbide avec 100 en stock ; QM, Titanium Carbide avec un job matché | 204 | 400 R3 ; 400 R1 |
| O-E6 | Garde-fou O2 a (D-E9). FC4, DELETE Fernite Alloy | 204, Hydrogen Fuel Block `[1, 20]` | inchangé (Hydrogen Fuel Block garde Fernite Carbide) |
| O-E7 | Garde-fou O2 b (D-E10). FC4 + Phenolic Composites profondeur 0 sans étape Oxygen Fuel Block ; DELETE Phenolic Composites | 204 | inchangé |
| O-E8 | Garde-fou O2 e. QM dont la racine est une copie (`testDeletedCopyOfTheProjectProductWithoutAnyProductionStepOfItLeavesTheMaterialSteps`) | 204 | inchangé (Titanium Carbide n'avait aucun consommateur) |

### `deletable` — `IndustryResourceMapperDeletableStepTest`

| # | Cas | Attendu |
|---|---|---|
| M-1 | QM : Quantum Microprocessor, Titanium Carbide, Oxygen Fuel Block | `false` (R2), `false` (O1), `true` |
| M-2 | QM-P : Titanium Carbide, Phenolic Composites, Oxygen Fuel Block | `true`, `true`, `true` |
| M-3 | QM dont Titanium Carbide est un groupe de deux jobs `[1, 245]` | `true`, `true` (O2 c ; pas de cascade dans le mapper) |
| M-4 | QM, `stepToResource()` sur ses trois étapes | `findMaterialEntitiesForBlueprints()` appelée une fois (O6) |
| M-5 | Tests existants du fichier (FC10, FC4, FC4-R, Phenolic sans étape de matériau, mode manuel) | inchangés |

## Cas limites

- **Chaîne** : supprimer de bas en haut ; chaque étape de matériau peut elle-même être refusée (R1, R3, O1) ; une étape de matériau liée à un job ESI bloque la chaîne jusqu'au déliement.
- **Orphelines héritées** (suppressions passées) : non réparées ; elles ne déclenchent rien (O2 b) ; comptage SQL de #202 (Impact prod) inchangé.
- **Matériau partagé à différentes profondeurs** : seul compte l'existence d'un autre consommateur, quelle que soit sa profondeur.
- **Étape devenue non supprimable entre l'affichage et le clic** (ajout d'étape dans un autre onglet) : 400 O1 dans le bandeau, aucun rechargement (R15 de #202).
- **Projets de Group Industry** : hors périmètre, comme #202.

## Montants visibles

Inchangés : un refus n'écrit rien, une suppression acceptée suit #202 sans changement. Seul effet visible : le bouton supprimer disparaît sur les étapes visées par O1.

## Tests existants appelés à casser

- **Résultat changé** : `DeleteStepProcessorTest::testMaterialStepLeftWithoutConsumerByADeletedStepKeepsItsRunsAndQuantity` (D-E12) attend 204 et Oxygen Fuel Block `[1, 5]` ; devient O-E1 (400, renommé).
- **Harnais seulement, résultats inchangés** (le mapper et le processor reçoivent une source des matériaux SDE) :
  - `DeleteStepProcessorTest::delete()` construit `new DeleteStepProcessor(...)` par position ;
  - mappers réels : `IndustryResourceMapperDeletableStepTest::mapper()`, `IndustryResourceMapperInStockStepTest` (l. 118), `IndustryResourceMapperTest` (l. 52, 76, 99, 306, 520, 530), `IndustryProjectFactoryTest` (l. 301) par `AutowiresByType` (`LogicException` « The harness has no … ») ; `SyncCharacterSkillsProcessorTest` (l. 642) par `new IndustryResourceMapper(...)`. Un repository qui renvoie `[]` suffit hors des tests de `deletable`.
- **Front** : aucun.

Restent verts : les autres tests de `DeleteStepProcessorTest` (aucun ne retire le dernier consommateur d'une étape de matériau : D-E9, D-E10, groupes, copies), `stepTreeDeletableStep.spec.ts`, `stepsDeleteStep.spec.ts`.

## Hors périmètre

- Q2 de #202 (job fixé supprimé).
- Expliquer à l'écran pourquoi le bouton manque (voir Q-A).
- Réparation des orphelines héritées.

## Questions ouvertes

### Q-A — Bouton masqué sans explication

O1 masque le bouton sur plus d'étapes (toute étape intermédiaire dont un matériau n'est fabriqué que pour elle).

- **Option A (recommandée pour ce lot)** : rien de plus, comme R14 de #202 (aucune maquette, aucune clé i18n).
- **Option B** : exposer la raison (`deletionRefusal: ?string` à la place ou à côté de `deletable`) et l'afficher en infobulle sur le placeholder ; demande une clé i18n par refus (quatre) et une maquette. À ouvrir en issue si les retours d'usage le demandent.

## Décisions prises en autonomie

- **Déclencheur = étape de matériau qui perd son dernier consommateur** (texte de l'option A de #202), et non « seule étape d'un produit encore consommé » : la seconde lecture refuserait D-E9 (aucune orpheline) et laisserait passer O-E4 (orpheline sous un produit ajouté depuis un type).
- **Message sans « mark it as purchased »**, contrairement au texte provisoire de l'option A : la marque est refusée en profondeur 0 (O8).
- **Pas d'exception pour une étape de matériau achetée ou en stock** (O2 d) : orpheline, elle serait lue comme une racine, stock ignoré, quantité figée achetée.
- **Refus rangé après R3** : un message à la fois, celui qui a un geste correctif sur l'étape elle-même d'abord.

## Notes de mise en œuvre (non normatives)

- Signature possible : `IndustryProject::refusalToDeleteStep(IndustryProjectStep $step, \Closure $materialTypeIdsOf)`, `$materialTypeIdsOf(IndustryProjectStep): list<int>` ; l'entité ne dépend pas de `App\Service` (deptrac). La closure se construit dans `App\Service\Industry` à partir de `findMaterialEntitiesForBlueprints()` et de `ProductionStepGraph::blueprintKeyOf()` / `stepMaterialsOf()`, une seule fois pour les deux appelants.
- Mapper : memo des matériaux par clé de blueprint (donnée SDE, comme `isT2Cache`), complété en une requête pour les clés manquantes du projet de l'étape.
- Calcul : pour chaque étape de matériau de l'étape supprimée, chercher une autre étape de production (≠ l'étape supprimée) dont les matériaux contiennent son produit. Un seul niveau : une étape de matériau orpheline garde ses propres matériaux.
- Carte du module (`docs/modules/industry.md`) : ajouter O1 à la ligne « Suppression d'une étape ou d'un job ».

## Impact prod

- **Aucune migration, aucune commande**, aucune donnée modifiée au déploiement.
- **Refus nouveau côté API** et bouton masqué pour les étapes visées par O1.
- **Une requête SDE de plus** par réponse portant des étapes (GET d'un projet, PATCH, POST…), indexée sur `type_id`.

## Glossaire

À reporter dans [`docs/glossary.md`](../glossary.md) à l'implémentation :

- **Étape orpheline** (Orphan step), nouveau : étape de production dont le produit n'est plus un matériau d'aucune étape de production du projet ; une suppression qui en créerait une est refusée (spec #202 Q1). Symbole : `IndustryProject::refusalToDeleteStep()`.
- **Étape supprimable**, précision : « …et dont aucune étape de matériau ne perdrait son dernier consommateur (spec #202 Q1). »
