# Spec #806 : exclure l'invention d'un projet

Mode nuit (2026-11-13) : spec rédigée sans gate. Décisions de Sylvain sur l'issue : circuit l, après #804 ; l'option est aussi dans la blacklist générale des réglages Industrie (défaut des nouveaux projets). Lot unique (réglages compris).

## Contexte

`IndustryProjectService::replaceT2BlueprintCopiesByInventions` planifie invention, copie T1 et datacores pour tout produit T2 du plan. #804 permet de déclarer des BPC T2 possédés (solo, runs retranchés). Beaucoup de joueurs inventent à part, et les projets de corporation consomment des BPC déjà présents : il faut pouvoir sortir l'invention du plan en bloc, et dire quels BPC T2 apporter.

## Règles

1. **Option de projet** : `excludesInvention` (booléen, faux par défaut) sur `IndustryProject` et `IndustryProjectTemplate` (copiée par `CreateProjectTemplateProcessor` et relue à l'instanciation, comme `acceptsOutsideFavoriteSystem`). Migration.
2. **Plan** : projet qui exclut l'invention, pour chaque produit T2 inventé du plan : aucune ligne `invention`, aucune copie T1 ajoutée, aucun datacore ajouté, la ligne `<produit>:copy` retirée (comme aujourd'hui), probabilité d'invention non exigée (pas de `InventionProbabilityMissingException`). Les BPC T2 sont traités comme fournis, sans coût. Une copie T1 ou un datacore partagé avec un autre besoin du plan garde les quantités de cet autre besoin. Produits T1 et réactions : inchangés.
3. **Déclarations #804** : ignorées par le plan quand l'option est active (tous les runs sont fournis), conservées en base ; elles reprennent effet si l'option est retirée.
4. **BPC T2 à fournir** : lecture seule, `GET /industry/projects/{projectId}/t2-blueprints-to-provide` (ressource `ProjectT2BlueprintsToProvide`, `id` = id du projet), même accès que le détail du projet (propriétaire et membres acceptés, autre utilisateur 403, projet inconnu 404). Contenu : `excludesInvention` ; `lines` : une par produit T2 inventé du plan, `productTypeId`, `typeName`, `runs` (somme des runs des lignes de fabrication du produit), ordre `typeName` croissant. Option inactive : `lines = []`. Aucun évènement Mercure (lecture synchrone).
5. **Création** : `CreateProjectInput::$excludesInvention` (`?bool`, `null` par défaut). `null` : valeur des réglages de l'utilisateur ; `true` ou `false` explicite : prime sur les réglages. Vaut pour toutes les entrées (formulaire, modèle, Buy vs Build, API) via `CreateProjectProcessor`. Un modèle instancié sans valeur explicite reprend sa propre valeur.
6. **Modification** : `PATCH` du projet accepte `excludesInvention` (`?bool`), `draft` seulement (400 sinon, comme la blacklist), BOM recalculé par `buildBom` ; un `RuntimeException` du recalcul devient 400 et l'ancienne valeur est restaurée (comme `changeTheBlacklist`).
7. **Réglages** : `User::industryExcludeInvention` (booléen, faux, migration). `GET/PUT /industry/blacklist` (`BlacklistResource`, `UpdateBlacklistInput`) exposent et écrivent `excludeInvention` (`?bool`, `null` = inchangé). Un changement des réglages n'affecte pas les projets existants.
8. **Ressource projet** : `IndustryProjectResource::$excludesInvention` (via `ProjectResourceMapper`), `IndustryProjectTemplateResource::$excludesInvention`.
9. **Proposition #804** : projet qui exclut l'invention : `T2BlueprintCopyProposer::proposalOf` renvoie l'état `invention_excluded` (`lines = []`), et `POST /industry/projects/{projectId}/t2-blueprint-copies` répond 400. Le panneau « BPC T2 possédés » affiche alors un message, sans liste.
10. **Affichage** :
    - Onglet Chaîne (`ProductionChain.vue`) : bloc « BPC T2 à fournir » (HUD, même identité que les blocs voisins) quand `excludesInvention` : une ligne par produit (icône, nom tronqué avec `title`, runs), vide : « aucun produit T2 dans ce plan ». Option inactive : bloc absent.
    - Détail du projet : interrupteur « Exclure l'invention » près de `ProjectBlacklistEditor` (éditable en `draft` pour le propriétaire, lecture seule sinon, avec une phrase d'aide : les BPC T2 sont à fournir).
    - `NewProjectModal.vue` : case « Exclure l'invention », cochée au départ si les réglages l'exigent (envoie la valeur explicite).
    - Réglages Industrie, onglet blacklist (`BlacklistConfig.vue`) : interrupteur « Exclure l'invention des nouveaux projets », enregistré tout de suite, erreur affichée si l'écriture échoue.
    - Textes FR et EN.
11. **Valorisation** : aucun montant ajouté ; le coût du plan baisse de l'invention, de la copie T1 et des datacores.

## Exemples chiffrés (`attempts(n) = ceil(n / (baseRuns × p))`)

| Cas | Entrée | Attendu |
|---|---|---|
| Issue | projet T2 de 15 runs, option active | pas de ligne `invention`, copie T1 ni datacore ; `lines = [{produit, 15}]` |
| Option inactive | même projet | plan inchangé (`attempts(15)`), `lines = []` |
| Probabilité inconnue | option active, probabilité d'invention absente | plan construit sans exception |
| Déclaration #804 | 6 runs déclarés, option active | comme sans déclaration, 15 runs à fournir ; option retirée : `attempts(9)` |
| Deux produits T2, un T1 | option active | `lines` : les deux T2 ; ligne du T1 inchangée |
| Produit à deux lignes de fabrication | runs 6 et 9 | une ligne, `runs = 15` |
| Création sans valeur, réglages actifs | `excludesInvention` absent | projet `excludesInvention = true` |
| Création `false`, réglages actifs | explicite | `false` |
| Modèle | modèle créé d'un projet `true` | instancié `true` |
| PATCH hors draft | projet publié | 400, valeur inchangée |
| Membre non propriétaire | GET `to-provide` d'un projet de groupe | 200 ; PATCH 403 (accès existant) |
| Autre utilisateur | GET | 403 |
| Réglages | PATCH `excludeInvention: true` puis nouveau projet | projet `true` ; anciens projets inchangés |
| Proposition #804 | option active | `invention_excluded`, POST 400 |

## Cas limites

- Le projet n'a aucun produit T2 : `lines = []` avec l'option active, message dédié.
- Un datacore aussi matériau d'un autre job du plan reste dans les matériaux (quantité de l'autre job).
- Projet `Completed` : lecture seule inchangée.

## Accès

Aucune nouvelle donnée de tiers : la liste dérive de la BOM du projet, visible de ceux qui voient le projet. Écriture : propriétaire seul, `draft` seulement (PATCH, comme la blacklist) ; réglages : l'utilisateur sur lui-même.

## Tests existants contredits ou modifiés

Aucun attendu (défaut faux). À vérifier au rouge : tests qui construisent `CreateProjectData`, `CreateProjectInput`, `IndustryProjectResource`, `BlacklistResource`, `UpdateBlacklistInput`, `T2BlueprintCopyProposal` (nouvel état), et les mocks de `User`.

## Hors périmètre

Consommation des BPC fournis par des jobs, vérification que les BPC sont présents (assets), choix du BPC (ME/TE), exclusion par produit, liste dans l'onglet Matériaux, changement de l'option hors `draft`.

## Décisions (mode nuit)

- D1 : valeur d'entrée `null` = défaut des réglages ; explicite prime (contrairement à la blacklist qui s'additionne : un booléen doit pouvoir être décoché).
- D2 : option modifiable en `draft` seulement, comme la blacklist.
- D3 : les déclarations #804 restent en base et reprennent effet si l'option est retirée.
- D4 : liste des BPC à fournir dans l'onglet Chaîne, endpoint dédié lisible par les membres.

## Glossaire

- **Invention exclue** (Excluded invention) : option de projet : le plan ne contient ni invention, ni copie T1, ni datacore, les BPC T2 sont à fournir (spec #806).
- **BPC T2 à fournir** (T2 blueprint copies to provide) : produits T2 du plan et leurs runs de fabrication, que les participants apportent quand l'invention est exclue.
