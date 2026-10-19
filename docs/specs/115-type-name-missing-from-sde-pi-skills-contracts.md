# Spec #115 (lot 4b, PI, skill queue et contrats) — Un type absent du SDE a un nom inconnu

## Contexte

Lots 1, 2 et 4a (specs `115-type-name-missing-from-sde*.md`) : le **nom de type inconnu** vaut `null` dans l'API, à côté du typeId, et le front affiche « Nom inconnu (#id) » (`useTypeNames().typeNameLabel()`). `TypeNameResolver::findName()` / `findNames()` ne renvoient que les noms connus.

Quatre lectures inventent encore un nom :

- `PlanetaryResourceMapper` (colonies PI) : `contentTypeName` d'une route (`:73`), `typeName` d'un pin sans nom enregistré (`:158`), `extractorProductName` (`:178`), contenu d'un pin (`:229`, `"Type #<id>"` en direct), entrées et sortie d'un schéma (`:268`) ;
- `PlanetaryProductionCalculator` (production PI) : `typeName` d'un produit (`:97`) et de ses intrants (`:117`) ;
- `SkillQueueProvider` : `skillName` de la compétence en cours (`:72`) ;
- `ContractCollectionProvider` : `typeName` d'un item de contrat absent du SDE vaut `'Unknown'` (`:219`).

## Règles métier

- **R1 — Nom inconnu.** Dans ces quatre sorties, un type absent de `invTypes` a un nom `null` ; son typeId reste renseigné. Aucun `"Type #<id>"` ni `'Unknown'`.
- **R2 — Calculs inchangés.** Volumes, quantités, ISK/jour, tiers, prix et valeurs de contrat, ordre et nombre d'éléments inchangés.
- **R3 — Nom de pin enregistré.** Le nom enregistré sur un pin reste prioritaire ; seul le repli devient `null`.
- **R4 — Résolution.** Les trois services qui utilisent `TypeNameResolver::resolve()` passent par `findName()`. `ContractCollectionProvider` garde sa lecture groupée d'`InvTypeRepository` et remplace seulement `'Unknown'` par `null`.
- **R5 — Front.** Écrans PI (colonies, production), skill queue et contrats : un nom `null` s'affiche avec `typeNameLabel()`.

## Exemples chiffrés

Types : Bacteria 2393 (connu), Robotics 9848 (absent de `invTypes`), compétence 3380 Industry (connue).

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Garde-fou, noms connus | route, pin, extracteur, contenu, schéma, production, skill, contrat sur des types connus | noms du SDE, sortie inchangée |
| E2 | Route PI vers un type inconnu | route de contenu 9848, quantité 3000 | `contentTypeName = null`, `contentTypeId = 9848`, `quantity = 3000` |
| E3 | Pin sans nom enregistré, type inconnu | pin de type 9848, `typeName` enregistré null | `typeName = null` |
| E4 | Pin avec nom enregistré, type inconnu | pin 9848 nommé « Robotics » en base | `typeName = "Robotics"` |
| E5 | Extracteur d'un produit inconnu | `extractorProductTypeId = 9848` | `extractorProductName = null` |
| E6 | Contenu d'un pin inconnu | contenu 9848 × 120 | `typeName = null`, `amount = 120`, `volume = null` |
| E7 | Schéma avec un intrant inconnu | schéma dont un intrant est 9848 | intrant 9848 : `typeName = null`, quantité inchangée |
| E8 | Production PI d'un produit inconnu | produit 9848, intrant 2393 | item 9848 : `typeName = null`, ISK/jour et tier inchangés ; intrant 2393 : `"Bacteria"` |
| E9 | Skill queue, compétence inconnue | compétence en cours 9848 | `skillName = null`, `skillId = 9848`, niveau et date inchangés |
| E10 | Contrat avec un item inconnu | items 2393 × 10 et 9848 × 5 | item 9848 : `typeName = null`, prix et valeurs inchangés ; 2393 : `"Bacteria"` |

## Décision prise en autonomie

- **Contrats** : `'Unknown'` est traité comme un nom inventé, donc remplacé par `null` (même règle que `"Type #<id>"`).
- **Colonie PI, front** : les replis codés en dur (« Stockage », `pi.colony.unknown`) sur un pin sans nom deviennent `typeNameLabel()` (« Nom inconnu (#id) »), et la production par colonie est regroupée par typeId au lieu du nom, pour ne pas fusionner deux types inconnus (R2).
- **Pas de test front** (pas de harnais composant) : vérifié par le typecheck.

## Hors périmètre

- Lot 3 (noms enregistrés : syncs industry jobs, mining, PVE, PI, scans PVE, assets ; migration).
- `ProfitMarginService`, `BuyVsBuildService`, suppression de `resolve()` / `resolveMany()` (lot final, quand plus aucun appelant).

## Glossaire

Aucun terme ajouté.
