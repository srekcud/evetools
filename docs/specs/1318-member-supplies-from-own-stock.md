# #1318 — A member supplies intermediates from their own stock

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan (chaîne C) et du prototype (scénario « Apport d'intermédiaires » : l'apport est immédiat, la réaction perd des runs, valorisé au prix Jita du jour). Prérequis sur `feat/industry-flow` : #1294 (7c045ce5c), #1324 (f00f9b728, lecteur `PersonalStockReader`).

## Contexte

`IntermediateStockProposer` ne sert que le propriétaire d'un projet solo, `ProjectIntermediateStockProposalProvider` fait `assertOwner`, et `ProjectContributionService::submit` refuse un apport d'intermédiaire hors projet solo (`assertSoloProject`). Un membre qui a le stock utile dans son hangar ne peut donc que refabriquer. Le recalcul des runs existe déjà (#706 : un apport `material` approuvé sur une ligne de job devient le stock de départ du plan, `buildBom` strict) ; cette issue l'ouvre au membre.

## Règles

R1. **Lecture ouverte.** `GET /industry/projects/{projectId}/intermediate-stock-proposal` : propriétaire ou participant accepté (`ProjectAccessChecker::assertAcceptedMember`), un autre utilisateur reçoit 403, un projet inconnu 404. La proposition est celle du lecteur : `IntermediateStockProposer::proposalOf(IndustryProject, User $reader)`.

R2. **Mon stock seulement.** Les quantités viennent de `PersonalStockReader::of($reader)` (hangars perso + containers perso déclarés, #1324), jamais du stock d'un autre membre ni du stock corpo. `unknownReason()` du lecteur non nul : état `assets_unknown` avec cette raison, comme aujourd'hui. Assets perso jamais synchronisés : `personal_assets_not_synced`.

R3. **Container du projet.** Compté seulement quand le projet est un projet solo du lecteur (comportement actuel inchangé). Projet de groupe : `containerQuantity = null`, le container du projet est du stock de projet, pas du stock perso (son stock non réclamé suit le circuit `AllocateUnclaimedStockProcessor`, qui reste réservé au propriétaire).

R4. **Pas de double compte.** Stock disponible = stock perso du lecteur − ses propres apports d'intermédiaire déjà approuvés du type (`IndustryProjectContributionRepository::findIntermediateStockByTypeId($project, ?User $contributor)`, nouveau filtre optionnel ; sans filtre le comportement actuel). Les apports des autres membres ne sont pas déduits du stock du lecteur : seul le reste de la ligne (`requiredQuantity − fulfilledQuantity`) plafonne la quantité proposée. Une ligne où le lecteur a déjà un apport `material` en attente ou approuvé n'est pas listée (inchangé).

R5. **État `not_solo`.** Ne subsiste que pour un projet terminé (`lines = []`) ; la constante et la valeur de l'API restent (le front les connaît), le nom est conservé. Un projet de groupe n'est plus `not_solo` pour son propriétaire ni pour un membre.

R6. **Apport d'un membre.** `POST /industry/projects/{projectId}/contributions`, type `material`, sur une ligne de job, sans `valueSource`, par un participant accepté (propriétaire compris) d'un projet qui n'est pas un projet solo du contributeur :
- `OwnStockSupplyGuard::assertWithin(IndustryProjectBomLine, User, int $quantity)` lit la proposition du contributeur : état autre que `ready` → `\DomainException` (400) ; quantité supérieure au `proposedQuantity` de la ligne (0 si la ligne n'est pas listée : produit final, ligne sans stock, déjà couverte) → `\DomainException` (400) « The quantity exceeds your own stock available for this line (N). » ;
- le service accepte alors l'apport (`submit(..., verifiedOwnStock: true)`) : approuvé tout de suite par le contributeur lui-même, `fulfilledQuantity` incrémenté, BOM recalculée dans la même transaction (`recalculateBomOf`, mode strict, refus D3 de #706 = rien n'est enregistré, 400) ;
- valorisation inchangée (`resolveMaterialValue` : prix payé lu du wallet pour les unités rapprochées, sinon prix Jita du jour de l'apport) ;
- Mercure : l'évènement `contribution_submitted` existant, aucun nouveau topic.
Sans `verifiedOwnStock`, `submit` refuse toujours un apport d'intermédiaire en projet de groupe (message actuel). Un projet solo du contributeur ne passe pas par la garde (comportement actuel inchangé, y compris la déclaration à la main).

R7. **Front.** Le panneau `IntermediateStockProposal` s'affiche pour tout participant accepté d'un projet non terminé (`isOwner || isAcceptedMember`), pas seulement pour le propriétaire. Validation et erreurs inchangées (un `POST` de contribution par ligne cochée, le message du serveur est affiché). Libellés existants (« Utiliser mon stock »), aucun écart au prototype.

## Exemples

Projet de groupe, composant Photonic Metamaterials : reste 1 059 runs de réaction, ligne `requiredQuantity` Q, reste Q.

| Cas | Entrée | Attendu |
|---|---|---|
| Membre avec stock | membre accepté, 90 000 en hangar, reste 300 000 | GET : `ready`, une ligne, `personalQuantity = stockQuantity = proposedQuantity = 90 000`, `containerQuantity = null` |
| Stock du propriétaire | le propriétaire a 50 000, le membre 0 | GET du membre : aucune ligne |
| Plafond par le reste | membre 400 000, reste 300 000 | `proposedQuantity = 300 000` |
| Deux membres | A apporte 200 000 (approuvé), B a 150 000 | GET de B : reste 100 000 → `proposedQuantity = 100 000`, stock disponible 150 000 |
| Apport déjà fait | le membre a déjà un apport sur la ligne | ligne absente |
| Propriétaire de groupe | stock perso 10 000 | `ready`, ligne proposée (plus `not_solo`) |
| Container de projet | groupe, container du projet rempli, membre sans stock | `containerQuantity = null`, aucune ligne |
| Projet terminé | tout | `not_solo`, `lines = []` |
| Non participant | utilisateur étranger | 403 |
| Apport dans la limite | membre 90 000, POST 90 000 | 201, approuvé, runs de la ligne réduits, `fulfilledQuantity` +90 000 |
| Apport au-delà | membre 90 000, POST 90 001 | 400, message de plafond, rien d'enregistré |
| Ligne non listée | POST sur le produit final | 400, rien d'enregistré |
| Stock inconnu | assets jamais synchronisés | GET `assets_unknown` ; POST 400 |
| Refus du recalcul | job ESI lié qui ne tiendrait plus | 400, aucun apport approuvé (atomicité de #706) |
| Solo inchangé | propriétaire d'un projet solo | proposition et apport comme avant (container compris, pas de garde) |

## Cas limites

- Lignes scindées d'un même type : une seule proposition par type, sur la ligne de plus grand `requiredQuantity` (limite de #706 R7 conservée).
- Un membre qui quitte le projet : ses apports approuvés restent, le stock de départ du plan ne regrossit pas.
- Pas d'annulation ni de correction d'un apport de membre : en groupe l'apport reste verrouillé (`isRevisableBySoloContributor` inchangé), à traiter dans une issue à part (noté au journal).

## Accès

Lecture et apport : participant accepté, sur son propre stock lu par `PersonalStockReader`. Aucune lecture du stock d'un autre membre. Pas de nouvelle ressource API, pas de migration, pas d'appel ESI.

## Tests existants contredits ou modifiés

- `IntermediateStockProposerTest::testGroupProjectIsNotSoloWhateverTheAssets` : un projet de groupe n'est plus `not_solo` ; réécrit pour le cas membre, `not_solo` ne reste que pour le projet terminé.
- `ProjectIntermediateStockProposalApiTest` (cas `not_solo` ligne 185, 403 d'un membre) : réécrits pour le membre.
- Les appels `proposalOf($project)` des tests du proposer passent le lecteur ; constructeurs de `SubmitContributionProcessor` (2 tests) complétés avec la garde ; aucune autre assertion modifiée.

## Hors périmètre

Annulation d'un apport de membre, apport sur un produit final, répartition du stock entre lignes scindées, stock d'un autre membre, `AllocateUnclaimedStockProcessor` (reste propriétaire), compagnon et cartes du flux (#1304 lit cette proposition ; son garde « propriétaire seul » côté front sera à lever après #1304).

## Glossaire

- **Proposition de stock** : lecture du stock perso du lecteur (propriétaire ou participant accepté), par intermédiaire du plan ; container du projet compté seulement en projet solo.
- **Apport d'intermédiaire** : ouvert au participant d'un projet de groupe sur son propre stock, vérifié contre la proposition, approuvé tout de suite.
- **Apport de stock perso** (Own-stock supply) : apport d'intermédiaire d'un participant d'un projet de groupe, borné par sa proposition de stock.
