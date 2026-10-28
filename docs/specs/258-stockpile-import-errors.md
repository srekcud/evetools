# Spec #258 — Erreurs d'aperçu et d'import du Stockpile : réponse stable, raison d'échec codée, runs plafonnés

**Statut : validée par Sylvain le 2026-10-28** (questions interactives : 422 avec message stable, code stable avec détail dans les logs, plafond 10 000).

## Contexte

La revue de sécurité du lot #69 (Stockpile sur `ProductionPlanning`) relève trois défauts dans `PlansStockpileImports`, `StockpileStartingStockReducer` et `StockpileImportInput` :

- `producerOf()` lève un `\RuntimeException` générique pour un type sans blueprint ni réaction, et `planOfImport()` convertit toute `\DomainException` du planificateur en `\RuntimeException` : l'aperçu et l'import répondent 500 pour une saisie invalide ;
- `StockpileStartingStockReducer::reduce()` attrape tout `\RuntimeException` et renvoie `getMessage()` brut dans `failedProducts[].reason`, affiché par `StockpileDashboard.vue` ; une `UnexpectedValueException` de `ConfiguredProductionStructures` y expose l'UUID d'une structure de l'utilisateur ;
- `StockpileImportInput::$runs` n'a que `#[Assert\Positive]`.

## Règles métier

- **R1 — Type non planifiable.** Un nouveau `StockpileProductNotPlannableException` (sous-classe de `\RuntimeException`, `#[WithHttpStatus(422)]`) porte un code de raison stable `no_blueprint` (type sans blueprint ni réaction) ou `plan_failed` (le planificateur a levé une `\DomainException`, par exemple un cycle). Son message est stable et ne contient que l'identifiant du type : `Type 12 has no blueprint nor reaction` / `Type 12 cannot be planned`.
- **R2 — Aperçu et import.** `POST` de l'aperçu et de l'import répondent 422 avec le message de R1 pour un type non planifiable. Les autres exceptions (base, structures illisibles) ne sont pas converties : elles restent des erreurs serveur.
- **R3 — Raison d'échec du statut.** Le réducteur n'attrape que `StockpileProductNotPlannableException`. `failedProducts[].reason` vaut le code de raison (`no_blueprint` ou `plan_failed`), jamais un message. Le détail de l'exception (message et exception précédente) est journalisé au niveau warning avec l'identifiant du type. Une autre exception se propage.
- **R4 — Libellé.** Le dashboard affiche un libellé traduit (EN et FR) pour chaque code ; un code inconnu affiche le libellé générique `plan_failed`.
- **R5 — Plafond des runs.** `StockpileImportInput::$runs` est borné à `1..10000` (`#[Assert\Range]`). Au-delà : 422. `typeId` reste `#[Assert\Positive]`.

## Exemples chiffrés

| # | Entrée | Attendu |
|---|---|---|
| E1 | Aperçu d'un type sans blueprint ni réaction (typeId 99999) | `StockpileProductNotPlannableException`, code `no_blueprint`, message `Type 99999 has no blueprint nor reaction`, statut HTTP 422 |
| E2 | Import d'un type dont le plan lève une `\DomainException` (cycle) | exception code `plan_failed`, message `Type {id} cannot be planned`, 422 ; le message du cycle n'apparaît pas dans le message public |
| E3 | Statut avec un import sans blueprint | `failedProducts` = `[{typeId, reason: 'no_blueprint'}]`, un warning journalisé qui contient le détail |
| E4 | Statut avec un import dont le plan échoue | `reason: 'plan_failed'` |
| E5 | Statut : `ConfiguredProductionStructures` lève une `\UnexpectedValueException` contenant un UUID | l'exception se propage, aucune entrée dans `failedProducts` |
| E6 | `runs` = 10 000 | valide |
| E7 | `runs` = 10 001 | violation de contrainte (422) |
| E8 | `runs` = 0 | violation (déjà le cas) |
| E9 | Dashboard, `failedProducts` = `[{typeId: 5, reason: 'no_blueprint'}]` | libellé traduit EN « No blueprint or reaction » / FR « Aucun blueprint ni réaction » (le code brut n'est plus affiché) |
| E10 | Dashboard, `reason` = `'plan_failed'` | libellé traduit EN « The production plan could not be built » / FR « Le plan de production n'a pas pu être construit » |
| E11 | Dashboard, `reason` inconnu (`'x'`) | libellé de `plan_failed` |

## Cas limites

- Un produit sans blueprint présent plusieurs fois dans les imports : une seule entrée de `failedProducts` par type, triée par type (comportement actuel conservé).
- Le message public ne contient jamais le message de la `\DomainException` d'origine ni l'UUID d'une structure.

## Hors périmètre

- `CostEstimationResource::$failedProducts` et `ShoppingListResource::$failedProducts` (autres écrans : un lot séparé si leur `reason` expose aussi un message brut).
- `ConfiguredProductionStructures` (messages d'`UnexpectedValueException`).

## Tests existants appelés à changer

À lister par la phase rouge : les tests de `StockpileServiceTest`, `StockpileStartingStockReducerTest`, `StockpileStatusProviderTest` et du processor d'aperçu qui attendent un `\RuntimeException` ou un message brut ; les fixtures du dashboard qui portent un `reason` en clair ; le constructeur du réducteur gagne un `LoggerInterface`.

## Glossaire

Aucun terme ajouté : « Producteur » existe déjà.
