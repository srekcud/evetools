# Spec #337 : chaîne de production et arbre des jobs

L'issue date d'avant #362 (étapes, BOM de groupe). Les étapes n'existent plus : la BOM plate (`IndustryProjectBomLine`) est la source du plan. Maquette retenue : `industry-project-v3.html` pages 7 et 10 (chaîne « Vue d'ensemble » et « Arbre des jobs »). Cette spec livre l'API de la chaîne et un composant autonome ; le branchement dans l'onglet Chaîne du cockpit (#336) vient après sa fusion.

## Contexte

- Le plan est figé à la création dans la BOM : lignes de matériau (feuilles du plan), lignes de job `final` (produit du projet) ou `component` (intermédiaire, `parentTypeId` = produit du premier job qui le consomme), lignes `blueprint` (copie, invention). Chaque ligne de job porte runs, ME, TE, `activityType` ; les jobs ESI liés (`jobMatches`, #362) et les achats (`purchases`) sont sur la ligne.
- Un apport approuvé (`IndustryProjectContribution`, #363) porte la valeur réelle : prix payé, coût ESI, ou prix Jita du dépôt ; `null` si inconnue. C'est l'unique source de coût du projet (#363 R7).
- Le plan ne garde pas le lien matériau → job consommateur (feuilles agrégées). La structure choisie par job est décidée par `StructureSelector` pendant `ProductionPlanning::planOf` mais n'est pas conservée (`Plan` est du Domain, `StructureChoice` de l'Application).
- #319 (lieux d'entrée et de sortie des jobs) est ouverte : `blueprint_location_id` et `output_location_id` ne sont pas synchronisés.

## Règles

- **R1 — Chaîne dérivée, rien de persisté.** `GET /api/industry/projects/{projectId}/chain` calcule la chaîne à la lecture depuis la BOM, les jobs et achats liés, les apports et les ventes du projet. Pas de nouvelle entité, pas d'appel au noyau de calcul ni au marché à la lecture : le plan n'est jamais recalculé. Seule exception de persistance : la structure conseillée par job (R9b).
- **R2 — Accès comme la BOM.** `assertAcceptedMember` (propriétaire ou participant `accepted`) ; projet inconnu : 404 « Project not found » avant tout contrôle. Un projet privé n'est lu que par ses participants. La ressource n'expose rien que la BOM, les apports et les ventes n'exposent déjà aux participants.
- **R3 — Un nœud par ligne de BOM, plus un nœud de vente.** `stage` : `blueprint` (groupe `blueprint`), `material` (ligne non job), `component` (job `component`), `product` (job `final`), `sale` (un seul nœud par projet, id `sale`). Ordre : stage (blueprint, material, component, product, sale), puis nom de type, puis id.
- **R4 — Parent.** `parentId` est l'id du nœud de job consommateur : un composant ou une copie / invention pointe vers le nœud de job (fabrication ou réaction) dont le `typeId` est son `parentTypeId` ; une ligne `blueprint` pointe d'abord vers le job de même `typeId`, à défaut vers celui de son `parentTypeId`. Un produit, un matériau, la vente et toute ligne sans job cible : `parentId` `null` (le plan ne dit pas quel job consomme une feuille ; les matériaux forment un groupe racine).
- **R5 — Statut d'un nœud de job** (`blueprint` en copie ou invention, `component`, `product`) : `done` si les runs des liens livrés (`delivered`) atteignent les runs de la ligne ; `active` si un lien `active`, `paused` ou `ready` existe, ou si des runs sont livrés sans couvrir la ligne ; `blocked` si aucun lien n'existe et qu'un job `component` enfant (R4) n'est pas `done` ; `todo` sinon ; `unknown` si un lien porte un statut hors `active`, `paused`, `ready`, `delivered`, `cancelled`, `reverted` (jamais `todo` par défaut). Les liens `cancelled` et `reverted` ne comptent pour aucun run.
- **R6 — Statut d'un nœud de matériau.** `done` si `fulfilledQuantity` ≥ `requiredQuantity` ; `active` si 0 < `fulfilledQuantity` < `requiredQuantity` ; `todo` sinon. Quantités fournies : `requiredQuantity`, `fulfilledQuantity`.
- **R7 — Coût réel = somme des apports approuvés de la ligne** (tous types, dérivés et déclarés). `costState` : `none` sans apport approuvé (`realCost` `null`, rien n'est encore payé) ; `unknown` si un apport approuvé a une valeur `null` (`realCost` `null`, jamais une somme partielle ni 0) ; `known` sinon (`realCost` = la somme). Les apports `pending` et `rejected` ne comptent pas.
- **R8 — Qui l'apporte.** `suppliers` : un élément par participant et type d'apport approuvé sur la ligne (`participantId`, `characterName`, `type`, `quantity` = somme des quantités ; pour un job, des runs). Vide sans apport approuvé. Participant retiré : ses apports approuvés restent listés tant qu'ils comptent dans le coût.
- **R9 — Arbre des jobs.** Un nœud de job porte `runs`, `meLevel`, `teLevel`, `activity` (`manufacturing`, `reaction`, `copy`, `invention`) et `jobs` : ses liens ESI non annulés (`esiJobId`, `status`, `runs`, `characterName`, `endDate`, `cost`, `facilityName`). `structures` : noms distincts des `facilityName` des liens (structures réelles), sans valeur par défaut ; vide sans lien ou sans nom.
- **R9b — Structure conseillée persistée (D1, choix de Sylvain : option B).** À la création du projet, la structure choisie par le plan pour chaque job de fabrication ou de réaction est conservée sur la ligne de BOM : `plannedStructureId` (id de la configuration de structure du propriétaire, `ProductionStructure::$id`, sans clé étrangère) et `plannedStructureStatus` (`assigned`, `best_in_favorite_system`, `best_configured`, `best_outside_favorite_system`, `best_by_total_cost`, `not_configured`). `ProductionPlanning` expose le choix par produit et activité sans changer `planOf` (nouvelle méthode, `planOf` en dépend) ; `IndustryProjectService` l'écrit (création et `updateLineInPlace`). Copie, invention et matériaux : null (le plan ne les planifie pas). Migration : deux colonnes nullables, lignes gardées, `down()` lève (D7 de #362) ; une ligne antérieure garde null.
- **R9c — Lecture de la structure conseillée.** `plannedStructure` du nœud : `{name, status}` ; `name` résolu à la lecture depuis la configuration de structure du propriétaire (nom seul : ni rigs, ni bonus, ni taxe) ; id inconnu ou configuration supprimée : `name` `null` (l'écran dit « structure supprimée ») ; ligne sans `plannedStructureId` ou statut `not_configured` : `plannedStructure` `null` (« aucune structure configurée »). Un statut hors liste est une erreur, jamais une valeur par défaut. `best_outside_favorite_system` est signalé comme avertissement. Quand un job est lancé, l'écran montre les structures réelles (R9) et garde la conseillée en repère.
- **R10 — Entrée et sortie : inconnu.** Aucun champ d'API tant que #319 n'est pas livrée. L'écran affiche la colonne « Entrée → sortie » en « inconnu » (violet pointillé) avec la cause « lieux des jobs non synchronisés (#319) », jamais un lieu supposé. Quand #319 existe, la même colonne lit ses champs.
- **R11 — Nœud de vente.** `soldQuantity` (somme des quantités des ventes), `grossRevenue` (somme des `totalPrice`) ; statut `done` si le projet est `completed`, `active` si une vente existe, `todo` sinon. Le total d'unités à vendre n'est pas dérivable de la BOM (unités par run non persistées) : aucun « n / total » inventé.
- **R12 — Front : composant autonome.** `ProductionChain.vue` (`components/group-industry/`, props : `projectId`) lit la ressource par un store `groupIndustry/chain` et propose deux vues comme la maquette : « Vue d'ensemble » (colonnes invention et matériaux, composants, intermédiaires, produit, vente ; clic sur un nœud ouvre l'arbre dessus) et « Arbre des jobs » (tableau : élément, activité, runs, ME/TE, personnage, statut, coût réel, fin, entrée → sortie, structure ; lignes repliables). Légende des statuts. `costState` `unknown` : violet pointillé avec la cause ; `none` : « — ». Pas de zéro pour un coût inconnu.
- **R13 — Direct.** Le composant se rafraîchit seul sur `syncStore.getGroupProjectEvent(projectId)` (toute action) et sur `getSyncProgress('industry-project')` du même projet, comme le détail actuel, sans modifier `groupProjectRefreshes.ts` ni les fichiers du cockpit.
- **R14 — Libellés** FR et EN (`fr.json`, `en.json`), testés avec des noms de type très longs (troncature et titre complet).

## Exemples (vérifiables par test)

P : projet de 2 Hammerhead II (produit), composant Hammerhead I (job `component`), copie du Hammerhead I, invention du Hammerhead II, matériaux Tritanium et PAU.

| # | État | Résultat |
|---|---|---|
| E1 | Aucun lien, aucun apport | produit `blocked` (le composant n'est pas `done`), composant `todo`, tout `costState` `none`, vente `todo` |
| E2 | Composant : un lien `delivered` de 50 runs sur 50, coût 214 000 apporté | composant `done`, `realCost` 214 000 `known` ; produit `todo` (plus de composant non `done`) |
| E3 | Produit : 5 liens de 10 runs, 2 `delivered`, 1 `active` | `active`, `jobs` de 5 éléments ; pas `done` |
| E4 | Produit : les 5 `delivered` | `done` |
| E5 | Lien `cancelled` de 10 runs sur le produit | absent de `jobs`, 0 run compté |
| E6 | Lien de statut `weird` | produit `unknown`, jamais `todo` |
| E7 | Apport approuvé de 100 000 par U et un de valeur `null` par V sur le PAU | `costState` `unknown`, `realCost` `null`, deux `suppliers` |
| E8 | Apports `pending` seulement | `costState` `none`, `suppliers` vide |
| E9 | Tritanium 400 sur 1 000 fournis | `active`, `requiredQuantity` 1 000, `fulfilledQuantity` 400 |
| E10 | Lien sans `facilityName` ; ligne planifiée en structure S (`best_in_favorite_system`) | `structures` vide ; `plannedStructure` {nom de S, `best_in_favorite_system`} |
| E11 | Copie T1 d'invention (typeId du composant, parentTypeId du produit) | `parentId` = nœud du composant |
| E12 | Deux ventes (3 à 700 000, 2 à 750 000) | nœud `sale` : `soldQuantity` 5, `grossRevenue` 3 600 000, `active` ; projet `completed` : `done` |
| E13 | Non-participant | 403 ; projet inconnu 404 ; projet privé d'un autre : 403 |
| E14 | Contribution approuvée qui change (Mercure `contribution_synced`) | le composant relit la chaîne |
| E15 | Configuration de S supprimée ; ligne sans structure planifiée ; copie | `name` `null` ; `plannedStructure` `null` ; `plannedStructure` `null` |
| E16 | Création d'un projet avec deux structures configurées, un job de fabrication et une réaction | chaque ligne de job du plan garde la structure choisie et son statut ; matériaux, copie, invention : null |

## Cas limites

- Un projet sans ligne de BOM : seul le nœud `sale` (`todo`).
- Une ligne de job qui a aussi des apports matériau (composant acheté) : son coût les somme (R7), son statut reste celui des jobs (R5).
- Une ligne `blueprint` de copie ignorée parce que le BPO existe : aucune spécificité (la BOM ne la contient pas).
- Une ligne de job dont `parentTypeId` ne correspond à aucun job de la BOM : `parentId` `null`.

## Hors périmètre

Route du cockpit, rail des phases, prochaine action, barre d'onglets (#336) ; branchement dans l'onglet Chaîne (après fusion de #336) ; structure conseillée d'une copie ou d'une invention, lieux d'entrée et de sortie (#319), probabilité d'invention affichée, unités vendues sur total, planning (#343) ; aucun retrait d'écran, de réglage ni de route existants (le détail `GroupIndustryDetail` garde sa BOM).

## Décisions

- **D1 — Structure d'un job : conseillée persistée (option B, Sylvain, 2026-11-06).** Touche le noyau d'application (`ProductionPlanning`, ajout d'une méthode), la création de projet et une migration irréversible ; la chaîne reste une lecture pure.
- **D2 — Nom de structure exposé aux participants.** Le nom de la configuration du propriétaire est lu pour tous les participants `accepted` (mêmes lecteurs que les `facilityName` des jobs liés) ; rien d'autre de la configuration. À vérifier à la revue sécurité.

## Glossaire (à ajouter à l'implémentation)

- **Chaîne de production** (Production chain) : vue dérivée de la BOM d'un projet, de l'invention à la vente, un nœud par ligne de BOM.
- **Nœud** (Chain node) : ligne de BOM ou vente, avec son statut, son coût réel (somme des apports approuvés) et ses fournisseurs.
- **Structure conseillée** (Planned structure) : structure choisie par le plan pour un job à la création du projet, conservée sur la ligne de BOM avec la manière dont elle a été choisie.
- **Arbre des jobs** (Job tree) : la chaîne en tableau hiérarchique (runs, ME/TE, jobs ESI liés, structures).
