# Spec #174 — « Créer le projet » depuis Buy vs Build respecte le mix choisi

## Contexte

Depuis Buy vs Build, « Créer le projet » place dans l'intention de navigation les composants à ne pas fabriquer (`excludedTypeIds`) et l'écran l'annonce (« Exclut … »). La vue Industry ne lit que `typeId`, `typeName`, `runs`, `me` et `te` ; elle force `isT2: false`, donc un TE de 0 au lieu de 4 pour un produit T2 ; `CreateProjectInput` n'a pas de champ d'exclusion, et `IndustryProject::$excludedTypeIds` (colonne existante depuis V0.1) n'est jamais rempli. Le projet fabrique tout ce que l'utilisateur avait choisi d'acheter.

Décision de Sylvain du 2026-10-22, rattachée à cette issue : un composant au verdict `unknown` reste **à fabriquer** à la création du projet. Aujourd'hui le filtre `effectiveVerdict(c) !== 'build'` le compte à acheter.

#172 (prix de vente manquant) est traité dans la même branche : déjà corrigé sur `main` (`frontend/src/stores/industry/types.ts:616-617` nullables, `BuyVsBuildTab.vue:208` `formatMargin` gère `null`), il reçoit seulement un test de garde (E12).

## Règles métier

- **R1 — Composant acheté.** Dans Buy vs Build, un composant est acheté si son verdict effectif (choix de l'utilisateur, sinon verdict calculé) est `buy` ou `loss`. Un composant `build` ou `unknown` sans choix de l'utilisateur est fabriqué. Le choix explicite de l'utilisateur l'emporte toujours.
- **R2 — Intention de navigation.** « Créer le projet » transmet `typeId`, `typeName`, `runs`, `me` (ME saisi dans Buy vs Build), `isT2` (celui du résultat) et `excludedTypeIds` = composants achetés (R1), dans l'ordre des composants du résultat. La mention « Exclut … » liste exactement ces composants.
- **R3 — Préremplissage.** La vue Industry ouverte par cette intention préremplit le produit avec son `isT2`, les runs, le ME transmis, et le TE transmis, sinon 4 pour un produit T2 et 0 sinon.
- **R4 — Envoi.** La création du projet envoie `excludedTypeIds` dans le corps du `POST /api/industry/projects` : la liste transmise par l'intention, `[]` hors intention. Choisir un autre produit dans le formulaire, ou rouvrir le formulaire, vide la liste.
- **R5 — Entrée API.** `CreateProjectInput::$excludedTypeIds` : liste d'entiers strictement positifs, `[]` par défaut. Une valeur ≤ 0 est rejetée (422).
- **R6 — Création.** Le projet mémorise les `excludedTypeIds` reçus, dédoublonnés (sans y ajouter la blacklist). L'arbre de production est construit avec l'union blacklist ∪ exclusions du projet : un composant exclu n'a pas d'étape et devient une feuille de son consommateur.
- **R7 — Persistance du mix.** La régénération des étapes et l'arbre renvoyé par `GET /api/industry/projects/{id}` utilisent la même union blacklist ∪ exclusions du projet.

## Exemples chiffrés

Résultat Buy vs Build d'un produit T2 (`isT2: true`), 10 runs, ME saisi 2, composants dans cet ordre : A (`build`), B (`buy`), C (`loss`), D (`unknown`).

| # | Cas | Attendu |
|---|---|---|
| E1 | Aucun choix utilisateur, « Créer le projet » | intention `excludedTypeIds` = [B, C], `isT2` true, `me` 2, `runs` 10 ; « Exclut » cite B et C, pas A ni D |
| E2 | L'utilisateur choisit « acheter » pour D | `excludedTypeIds` = [B, C, D] |
| E3 | L'utilisateur choisit « fabriquer » pour B | `excludedTypeIds` = [C] |
| E4 | Intention reçue par Industry (T2, sans `te`) | formulaire ouvert, ME 2, TE 4, produit marqué T2 |
| E5 | Intention reçue avec `te` 6 | TE 6 |
| E6 | Intention reçue pour un produit non T2, sans `te` | TE 0 |
| E7 | Intention E1 puis « Créer » | corps POST : `excludedTypeIds` [B, C], `meLevel` 2, `teLevel` 4, `runs` 10 |
| E8 | Création sans intention | corps POST : `excludedTypeIds` [] |
| E9 | Intention E1, puis choix d'un autre produit, puis « Créer » | corps POST : `excludedTypeIds` [] |
| E10 | Processor : entrée `excludedTypeIds` [B, C, B] | la fabrique reçoit les exclusions ; le projet mémorise [B, C] ; l'arbre est construit avec blacklist ∪ {B, C} ; aucune étape pour B ni C, B et C sont matériaux de l'étape qui les consomme |
| E11 | Projet avec exclusions [B], blacklist [X] | `regenerateSteps` et l'arbre du `GET` projet reçoivent {X, B} |
| E12 | #172 : résultat avec `sellPrice` null et `marginPercent` null | l'onglet s'affiche sans erreur ; marge affichée « inconnue » (libellé `industry.inventionUnknown.label`), jamais `0` ni `NaN` |
| E13 | Entrée `excludedTypeIds` [0] ou [-5] | 422 |

## Cas limites

- Exclusion d'un composant absent de l'arbre : sans effet sur les étapes, mémorisée telle quelle.
- Exclusion d'un composant aussi blacklisté : une seule exclusion dans l'arbre.
- Produits ajoutés en plus dans le formulaire (étapes supplémentaires via `createStep`) : hors exclusions, comportement inchangé.

## Hors périmètre

- Modifier les exclusions d'un projet existant (pas d'édition dans l'UI).
- Liste d'achats (`IndustryShoppingListBuilder`) et `ProductionCostService` : ils lisent les étapes, et un composant exclu n'a pas d'étape.
- Group Industry, ME/TE des composants intermédiaires.

## Décisions prises en autonomie (mode nuit)

- Exclusions mémorisées sur le projet (colonne existante, aucune migration) plutôt que des étapes marquées achetées : l'issue attend « pas d'étape pour ce composant ».
- La blacklist n'est pas recopiée dans le projet : elle reste lue à chaque construction, comme aujourd'hui.
- Changer de produit dans le formulaire vide les exclusions (elles portent sur les composants du produit analysé).
- TE par défaut 4 pour un T2 comme la sélection manuelle (`Industry.vue` `onProductSelect`).

## Glossaire

Terme existant **Feuille** (« acheté de force ») couvre le composant exclu. Aucun ajout.
