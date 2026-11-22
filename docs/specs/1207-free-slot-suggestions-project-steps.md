# Spec — Les suggestions de slots libres commencent par les étapes des projets ouverts (#1207)

## Contexte

`SlotTrackerService::buildFreeSlots` / `findSuggestion` ne tire les suggestions d'un slot libre que des cibles de Stockpile. Sur la page Production, un joueur qui a un projet ouvert voit donc un article de stock plutôt que l'étape du projet à lancer. De plus `IndustrySlotsSection.vue` stylise le nom suggéré comme un lien (`cursor-pointer hover:underline`) sans action.

Les étapes de projet n'existent plus comme table (supprimées par #362) : une **étape à lancer** est un nœud de la chaîne du projet (`ProjectChainBuilder`) de statut `todo` (composants terminés, matériaux fournis, aucun job lié ni marque). La chaîne est déjà l'ordre de l'onglet Production (`GET /industry/projects/{projectId}/chain` : étape, statut, niveau, nom ; produit final en dernier).

Décisions prises sur la recommandation de l'issue (mode nuit) :
- une seule suggestion par entrée de slot libre (forme actuelle conservée) ; elle est la première étape à lancer de la catégorie, le Stockpile ne sert que s'il n'y en a aucune ;
- la réservation d'une ligne par un autre participant n'est pas lue (hors périmètre).

## Règles

- **R1.** Nouveau service `OpenProjectStepsToLaunch::forUser(User): list<StepToLaunch>` (`src/Service/Industry/Project/`). Il parcourt `IndustryProjectRepository::findOpenOfAcceptedParticipant($user)` (participant `accepted`, projet non `completed`), écarte les brouillons (`ProjectStatus::Draft`, jamais rapprochés, #1123), trie les projets par date de création croissante, puis garde les nœuds de la chaîne de chaque projet dans l'ordre de la chaîne.
- **R2.** Un nœud est une étape à lancer s'il est un nœud de job (`stage` `blueprint`, `component` ou `product`, jamais `material` ni `sale`), de statut `todo`, d'activité `manufacturing` (catégorie de slot `manufacturing`), `reaction` (`reaction`), `copy` ou `invention` (`science`). Un autre type d'activité n'est pas une étape suggérable (ignoré, jamais une exception). Statuts `blocked`, `missing_materials`, `active`, `done`, `unknown` : exclus.
- **R3.** `StepToLaunch` (`final readonly`) porte `slotCategory`, `projectId`, `projectName` (nullable), `typeId`, `typeName`, `runs` (entier ≥ 1 ; un nœud de job sans runs est une `UnexpectedValueException`, comme le builder).
- **R4.** Le chargement de la chaîne d'un projet est extrait de `ProjectChainProvider` dans `ProjectChainLoader::load(IndustryProject, list<IndustryProjectBomLine>): ProjectChain` (noms de structures du propriétaire, besoins matériaux, apports approuvés, ventes, container, `ProjectChainBuilder::build`). `ProjectChainProvider` l'appelle ; sa sortie ne change pas.
- **R5.** `SlotTrackerService` reçoit `OpenProjectStepsToLaunch` (dernier paramètre du constructeur). Il lit les étapes une fois par `getSlotStatus`, seulement si au moins un personnage a un slot libre calculé. Pour chaque entrée de slot libre (`count` > 0), la suggestion est la première étape de même `slotCategory` ; sinon la suggestion de Stockpile actuelle, inchangée ; sinon `null`.
- **R6.** Forme de la suggestion (`freeSlots[].suggestion`) : `typeId`, `typeName`, `reason`, plus `project` (`{id: string, name: string|null}` ou `null` ; un projet sans nom garde `null`, le front affiche le libellé de repli des jobs liés) et `runs` (`int` ou `null`). Étape de projet : `project` et `runs` remplis, `reason` = `null`. Stockpile : `project` = `null`, `runs` = `null`, `reason` inchangé (`stockpile at 40%`). `typeName` d'une étape est celui du nœud ; un nœud sans nom n'est pas une étape (ignoré, jamais un nom inventé).
- **R7.** Même étape pour deux personnages aux slots libres de même catégorie : acceptée, chaque personnage reçoit la première étape (pas de répartition).
- **R8.** Front : `FreeSlotEntry.suggestion` gagne `project: { id: string; name: string } | null` et `runs: number | null`, `reason: string | null`. Une suggestion avec `project` rend son nom dans un `RouterLink` `data-testid="free-slot-suggestion-link"` vers `{ name: 'industry-project', params: { id }, query: { tab: 'chain' } }` (onglet Production du projet), suivi de `t('industry.slots.stepRuns', { runs, project })` (FR « {runs} runs · {project} », EN « {runs} runs · {project} ») ; une suggestion sans `project` rend le nom en texte simple, sans style de lien (ni `cursor-pointer`, ni `hover:underline`) et garde `(reason)`.
- **R9.** L'étiquette de la suggestion (`freeSlotSuggestionLabel`) reste « Construire » / « Inventer » selon la catégorie du slot.

## Exemples (ce seront les tests rouges)

Un personnage (1 slot manufacturing libre, 1 slot science libre, 0 réaction libre). Projets de l'utilisateur sauf mention.

| # | Situation | Résultat |
|---|---|---|
| E1 | Un projet `in_progress` ; nœuds : composant Rifter Hull (`todo`, manufacturing, 4 runs), produit Rifter (`blocked`) | suggestion manufacturing = Rifter Hull, `project` = {id, nom}, `runs` 4, `reason` null |
| E2 | Même cas + cible de Stockpile `final_product` à 10 % | la suggestion reste Rifter Hull (le Stockpile ne passe qu'après) |
| E3 | Même cas, aucun nœud `todo` | suggestion = la cible de Stockpile, `project` null, `reason` « stockpile at 10% » |
| E4 | Aucun projet ouvert, aucun Stockpile | `suggestion` null |
| E5 | Deux projets ouverts créés J-5 et J-2, chacun avec une étape manufacturing `todo` | la suggestion est l'étape du projet créé J-5 |
| E6 | Un projet `draft` avec une étape `todo` | ignoré (suggestion de Stockpile ou null) |
| E7 | Un projet `completed` avec une étape `todo` | ignoré (absent de `findOpenOfAcceptedParticipant`) |
| E8 | Nœuds `missing_materials`, `active`, `done`, `unknown` seulement | aucune étape |
| E9 | Nœud `todo` d'activité `copy` (blueprint) | suggestion du slot science = cette copie ; `invention` idem |
| E10 | Nœud `todo` d'activité inconnue (`research_me`) | ignoré, pas d'exception |
| E11 | Nœud `todo` de stage `material` | ignoré |
| E12 | Personnage aux compétences inconnues | `freeSlots` vide, comme avant ; les étapes ne sont pas lues si aucun personnage n'a de slot libre |
| E13 | Étape manufacturing `todo` mais le personnage n'a aucun slot manufacturing libre | pas de suggestion manufacturing (rien n'est proposé pour un slot absent) |

Front :

| # | Situation | Résultat |
|---|---|---|
| F1 | Suggestion avec `project` | lien `free-slot-suggestion-link` vers `/industry/projects/<id>?tab=chain`, texte « 4 runs · <projet> » |
| F2 | Suggestion Stockpile (`project` null) | nom sans lien ni classes `cursor-pointer` / `hover:underline`, `(stockpile at 10%)` visible |

## Cas limites

- Projet à la chaîne vide ou sans nœud de job : aucune étape, pas d'erreur.
- Un projet dont la chaîne lève une `UnexpectedValueException` (ligne de job sans runs, groupe inconnu) : l'erreur remonte, jamais avalée (donnée métier) ; le Slot Tracker répond en erreur plutôt que de mentir.
- Même type fabriqué dans deux projets : deux étapes distinctes, la première gagne.
- Nom de projet très long : tronqué par CSS côté front (`truncate`, titre complet en `title`).

## Accès et fraîcheur

- Seuls les projets dont l'utilisateur est participant `accepted` entrent (`findOpenOfAcceptedParticipant`) ; nom et id d'un projet ne fuient jamais à un tiers. Aucun id reçu d'un autre périmètre, aucune donnée de corporation.
- Fraîcheur : la chaîne est calculée à la lecture (jamais stockée) ; le Slot Tracker se relit sur son cadencement actuel. Coût : une chaîne par projet ouvert à chaque lecture de `/industry/slots`, bornée aux projets ouverts du joueur (à relire).
- Aucune écriture, aucune migration, aucun endpoint nouveau, aucun Mercure (lecture seule).

## Tests existants contredits ou modifiés

- `SlotTrackerServiceTest` : 4 constructions de `SlotTrackerService` gagnent un neuvième argument (`OpenProjectStepsToLaunch` vide) ; aucune assertion modifiée.
- `industrySlotsSection*.spec.ts` : la forme `suggestion` du type `FreeSlotEntry` gagne `project`, `runs` ; les données de test existantes sont complétées (`project: null, runs: null`).
- `ProjectChainProviderTest` : 7 constructions du provider remplacent les 5 dépendances de chargement par `ProjectChainLoader` ; aucune assertion modifiée.

## Hors périmètre

- Plusieurs suggestions par entrée ou compteur « + N autres étapes ».
- Lecture des réservations de ligne (une étape réservée par un autre participant reste suggérée).
- Filtre par structure, par blueprint possédé ou par compétences du personnage ; répartition des étapes entre personnages.
- Compagnon, kanban, bouton « Lancer ».

## Termes du glossaire

- **Étape à lancer** (Step to launch) : nœud de job de la chaîne d'un projet ouvert dont le statut est `todo` (composants terminés, matériaux fournis, rien de lié ni de marqué) ; classée par catégorie de slot (fabrication, réaction, science). Alimente la suggestion d'un slot libre avant le Stockpile (#1207). `OpenProjectStepsToLaunch`, `StepToLaunch`.
- **Suggestion de slot libre** : entrée existante du Slot Tracker (`freeSlots[].suggestion`), désormais ordonnée : première étape à lancer de la catégorie, sinon cible de Stockpile la moins couverte.
