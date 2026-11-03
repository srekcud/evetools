# Spec #233 : uriVariables des sous-ressources de projet industriel

**Statut : validée en autonomie en mode nuit le 2026-11-03, à relire par Sylvain.**

Circuit : `circuit:m`. Lots de #233 : le point 1 (R1 à R3, fusionné) puis le point 4 (R4 à R6, décidé par Sylvain le 2026-11-03 : toujours une liste). Source : issue #233, règle « Sub-resources » du `CLAUDE.md`.

## Contexte

Les opérations sous `/industry/projects/{id}/…` sont des sous-ressources du projet. API Platform 4 exige `uriVariables` avec `Link` pour résoudre leurs variables (règle OBLIGATOIRE du `CLAUDE.md`). Aucune ne le déclare aujourd'hui : les providers et processors lisent pourtant `$uriVariables['id']` et `$uriVariables['stepId']`.

Opérations concernées (templates avec une variable suivie d'un autre segment) :

| Ressource | Template |
|---|---|
| `ProjectStepResource` | `/industry/projects/{id}/steps` (POST), `/industry/projects/{id}/steps/{stepId}` (PATCH, DELETE), `…/{stepId}/split`, `…/{stepId}/merge`, `…/{stepId}/link-job` |
| `ProjectResource` | `/industry/projects/{id}/shopping-list`, `/industry/projects/{id}/match-jobs` |
| `AvailableJobResource`, `BpcKitResource`, `CostEstimationResource`, `CopyCostsResource`, `PurchaseSuggestionListResource` | `/industry/projects/{id}/available-jobs`, `bpc-kit`, `cost-estimation`, `copy-costs`, `purchase-suggestions` |
| `StepPurchaseResource` | `/industry/projects/{id}/steps/{stepId}/purchases`, `…/purchases/{purchaseId}` |

## Règles

- **R1 : Variable parent.** Pour chaque opération de `src/ApiResource/Industry/` dont le `uriTemplate` contient une variable suivie d'un autre segment, `uriVariables` déclare chaque variable du template :
  - `id` : `new Link(fromClass: ProjectResource::class)` ;
  - `stepId` : `new Link(fromClass: ProjectStepResource::class)` ;
  - `purchaseId` : `new Link(fromClass: self::class)`.
- **R2 : Comportement inchangé.** Aucune route, méthode, format, sécurité ni contenu de réponse ne change : la table des routes (`debug:router`) et l'export OpenAPI sont identiques avant et après, aux seules différences de description des paramètres de chemin que l'export pourrait porter. Seule exception constatée, voulue : le `@id` JSON-LD de la réponse du PATCH d'étape (et du DELETE) portait l'identifiant de l'étape à la place de celui du projet (`/industry/projects/<stepId>/steps/<stepId>`) ; avec `id` lié à `ProjectResource` il devient `/industry/projects/<projectId>/steps/<stepId>`. Le frontend ignore `@id` (`utils/jsonld.ts`).
- **R3 : Test d'architecture.** Un test unitaire lit les attributs d'opération (réflexion sur les classes de `src/ApiResource/Industry/`, `newInstance()` de chaque attribut d'opération) et échoue pour toute opération qui viole R1, en nommant la classe et le template. Il ne s'appuie pas sur les métadonnées API Platform résolues (elles remplissent `uriVariables` d'office, le test ne verrait jamais d'écart).

- **R4 : Réponse unique du PATCH d'étape.** `PATCH /industry/projects/{id}/steps/{stepId}` renvoie toujours une liste de `ProjectStepResource` : l'étape modifiée en tête quand elle existe encore, puis les autres étapes recalculées par la cascade, sans doublon. Liste vide si la cascade a supprimé l'étape modifiée. Plus jamais un objet seul.
- **R5 : Front.** `updateStep` et `toggleStepPurchased` (`frontend/src/stores/industry/steps.ts`) lisent uniquement une liste. La règle de rechargement du projet (étape modifiée absente de la réponse, ou étape de groupe scindé dans la réponse) est inchangée ; le retour de `updateStep` reste l'étape modifiée ou `undefined` si elle a disparu.
- **R6 : Reste inchangé.** Routes, sécurité, corps de requête, calcul de la cascade, autres opérations de `ProjectStepResource`.

## Exemples

| # | Cas | Attendu |
|---|---|---|
| A1 | `ProjectStepResource` PATCH `/industry/projects/{id}/steps/{stepId}` sans `uriVariables` | test rouge, message `ProjectStepResource /industry/projects/{id}/steps/{stepId}` |
| A2 | même opération avec `id` et `stepId` déclarés | vert |
| A3 | opération qui déclare `id` seulement alors que le template porte `stepId` | rouge |
| A4 | `GET /industry/projects/{id}` (variable finale, identifiant propre) | ignorée par le test |
| A5 | `StockpileTargetResource` `/industry/stockpile-targets/{id<…>}` (regex dans la variable, finale) | ignorée par le test |

| P1 | PATCH `runs` sans cascade | liste d'une étape : l'étape modifiée |
| P2 | PATCH `runs` avec cascade qui recalcule d'autres étapes | liste : étape modifiée en tête, puis les autres, sans doublon |
| P3 | PATCH dont la cascade supprime l'étape modifiée | liste vide |
| P4 | PATCH `purchased` (bascule d'achat) | liste d'une étape ; le store met l'étape à jour sans recharger le projet |
| P5 | réponse contenant une étape de groupe scindé | le store recharge le projet (comportement actuel conservé) |

## Décisions

1. **Point 3 de #233 déjà corrigé (#200)** : `UuidOrNotFound::of()` est appelé par `ProjectProvider`, `UpdateProjectProcessor` et `UpdateStepProcessor` ; un identifiant non UUID rend 404. Hors lot.
2. **Point 2 (`#[ApiProperty(identifier: true)]` sans `Get`)** : on ne change rien (décision de Sylvain, option recommandée) : l'identifiant sert aux IRI, aucune route nouvelle.
3. **Point 4 (réponse du PATCH d'étape à deux formes)** : forme unique « toujours une liste » (décision de Sylvain, 2026-11-03). Rejeté : un objet avec `recalculatedSteps`, faute de représentation pour l'étape supprimée par la cascade.

## Hors périmètre

Les ressources des autres domaines (Group Industry les déclare déjà), le `Get` d'étape, tout changement de provider.
