# Spec #1022 — Le contrat de courrier part du lieu d'achat

Statut : validée en autonomie (mode nuit). Circuit `circuit:m`. Suite de #351 (`docs/specs/351-courier-contract.md`).

## Problème

`CourierOverviewBuilder::build()` met tous les achats du projet dans un seul devis qui part toujours de Jita (`EveConstants::JITA_SOLAR_SYSTEM_ID`), quel que soit le lieu d'achat.

## Termes (glossaire : Contrat de courrier)

- **Origine d'un achat** : le système solaire du lieu de la transaction wallet (`IndustryProjectPurchase::getTransaction()->getLocationId()`), jamais déduit de Jita.
- **Origine inconnue** : achat sans transaction (source `manual`), ou dont le lieu ne se résout pas en système.
- **Système de fabrication** : `IndustryProjectService::favoriteSystemsOf($owner)->manufacturingSystemId` (inchangé).

## Règles

1. **Résolution du lieu** (nouveau service `PurchaseOriginResolver` dans `src/Service/Industry/Project/Courier/`, méthode `solarSystemIdOf(IndustryProjectPurchase): ?int`) :
   - pas de transaction : `null` ;
   - `locationId < 1_000_000_000` (station) : système de `StaStationRepository::findByStationId` ; `null` si la station est absente ;
   - sinon (structure) : `CachedStructureRepository::findByStructureId()->getSolarSystemId()`, à défaut `IndustryStructureConfigRepository::findByLocationId()->getSolarSystemId()`, à défaut `null`. Même règle de découpage que `IndustryCalculationService::resolveFacilityName`.
2. **Groupes d'achats** : `CourierOverviewBuilder::purchasesOf()` ne compte que les achats de quantité > 0, comme aujourd'hui, puis les répartit :
   - origine = système de fabrication : aucun transport, exclu du devis et de `lines` ;
   - origine résolue, différente du système de fabrication : un groupe par système ;
   - origine inconnue : groupe à part, jamais ajouté à un devis ni compté comme Jita.
   - Si le système de fabrication n'est pas défini, aucun achat n'est « dans l'atelier » : tous les groupes résolus restent, avec `workshop_unknown` (comportement actuel de `CourierQuoteCalculator`).
3. **Un devis par origine** : chaque groupe résolu passe par `CourierQuoteCalculator::quote()` (inchangé) avec `jumpsBetween(origine, atelier)` (`null` si pas d'atelier). Volume, collatéral et récompense sont donc calculés par origine, jamais mélangés.
4. **Origine principale** : le groupe de plus grand collatéral (somme des `totalPrice`), à égalité le plus petit identifiant de système. Elle alimente les champs existants de la ressource (`volumeCubicMeters`, `collateral`, `reward`, `jumps`, `quoteStatus`, `missingVolumeTypeIds`, `originSolarSystemId`, `originSolarSystemName`), donc le texte du contrat à copier et le rapprochement des candidats (`candidatesIn`) restent sur l'origine principale.
5. **Autres origines** : nouveau champ `otherOrigins` (liste, vide par défaut) de la ressource `ProjectCourierResource`, une ligne par groupe résolu non principal, triées par collatéral décroissant : `{solarSystemId, solarSystemName, volumeCubicMeters, collateral, reward, jumps, quoteStatus, missingVolumeTypeIds}`.
6. **Origine inconnue exposée** : nouveau champ `unknownOriginLines` (liste, vide par défaut) : `{typeId, typeName, quantity}`, quantités sommées par type, triées par `typeId`.
7. **Aucun achat à transporter** (tous dans l'atelier, ou aucun achat, ou seulement des origines inconnues) : devis principal `no_purchases`, `originSolarSystemId` et `originSolarSystemName` à `null` (le champ `originSolarSystemId` devient nullable dans `CourierOverview`, la ressource et `ProjectCourier` côté front), `otherOrigins` vide.
8. **`lines`** (état de transport par ligne de BOM) : `purchasedQuantity` ne compte que les achats à transporter (origines résolues hors atelier) ; une ligne sans quantité à transporter n'apparaît pas. Les quantités d'origine inconnue sont dans `unknownOriginLines`, pas dans `lines`.
9. Pas de migration, pas de nouvelle route, pas de nouveau scope ESI, pas de nouvelle donnée stockée. Le lien contrat ↔ projet et `DerivedContributionService::syncCourier` ne changent pas.

## Exemples chiffrés (volume par unité : Tritanium 0,01 m³, Nocxium 0,01 m³ ; Jita 30000142, Amarr 30002187, atelier Perimeter 30000144 ; 1 saut Jita → atelier, 9 Amarr → atelier)

| Achats (type, quantité, prix total, lieu) | Devis principal | Autres origines | Origine inconnue |
|---|---|---|---|
| Tritanium 1000, 1 000 000 ISK, station Jita | Jita → Perimeter, volume 10, collatéral 1 000 000, 1 saut | vide | vide |
| Tritanium 1000, 1 000 000 ISK, station Amarr | Amarr → Perimeter, collatéral 1 000 000, 9 sauts, récompense = volume × 9 × 150 | vide | vide |
| Tritanium 1000, structure hors atelier résolue par `CachedStructure` (système Amarr) | Amarr → Perimeter | vide | vide |
| Tritanium 1000, 1 000 000 ISK, station de Perimeter (système atelier) | `no_purchases`, origine `null` | vide | vide, `lines` vide |
| Tritanium 1000 à 1 000 000 ISK (Jita) + Nocxium 100 à 5 000 000 ISK (Amarr) | Amarr (collatéral 5 000 000) | Jita (1 000 000, 1 saut) | vide |
| Jita 1000 + Perimeter 500 (même ligne) | Jita, volume 10 (500 exclus) | vide | vide, `lines` : quantité 1000 |
| Tritanium 1000, source `manual` | `no_purchases`, origine `null` | vide | Tritanium 1000 |
| Tritanium 1000 Jita + Tritanium 300 manual | Jita, volume 10 | vide | Tritanium 300 |
| Achat dans une structure inconnue (ni cache ni config) | `no_purchases` | vide | quantité du type |
| Pas de système de fabrication, achats Amarr | Amarr, `workshop_unknown`, `jumps` null | vide | vide |

## Cas limites

- Deux origines au même collatéral : la plus petite id de système est principale.
- Même type acheté à deux origines : une quantité par origine dans son devis ; sans transaction, c'est l'origine inconnue.
- Achat de quantité 0 : ignoré comme aujourd'hui.
- Volume inconnu pour un type d'une origine : `volume_unknown` pour cette origine seulement, les autres origines gardent leur devis.
- Candidats de contrat : ne sont comparés qu'à l'origine principale ; `matchesQuote` reste faux quand elle est `no_purchases`.

## Front (`ProjectCourier.vue`, `types.ts`, i18n FR + EN)

- Route affichée : origine principale → atelier ; si `originSolarSystemName` est `null`, tiret via `textOrDash`.
- Sous le devis, quand `otherOrigins` n'est pas vide : bloc « Autres lieux d'achat » (une ligne par origine : nom du système, volume, collatéral, récompense, sauts, valeurs inconnues via `UnknownContributionValue`).
- Quand `unknownOriginLines` n'est pas vide : bloc « Origine inconnue » (liste type et quantité) avec la mention qu'ils ne sont inclus dans aucun contrat, visible même si le devis principal est `no_purchases`.
- État « rien à expédier » existant (`courier-empty`) conservé quand il n'y a ni devis ni origine inconnue.
- Direction HUD EVE : mêmes classes que les blocs existants du composant. Tester un nom de système long.

## Tests attendus (proportionnés)

- Unit `PurchaseOriginResolverTest` : station, structure en cache, structure en config, structure inconnue, sans transaction.
- Unit `CourierOverviewBuilderTest` : une ligne par exemple du tableau (dont origine principale à égalité, atelier non défini, volume inconnu sur une origine).
- Unit `ProjectCourierResourceMapperTest` : `otherOrigins`, `unknownOriginLines`, origine `null`.
- Functional `ProjectCourierCostApiTest` : un test GET avec achats Jita + autre hub + manuel, lien de contrat et coûts inchangés.
- Front `projectCourier.spec.ts` : au plus 6 tests (autres origines affichées, origine inconnue affichée, origine inconnue seule sans état vide, route avec origine non Jita).

## Hors périmètre

- Plusieurs contrats liés à un même projet (un lien par projet, inchangé).
- Résolution ESI d'une structure inconnue (aucun nouvel appel ESI).
- Rapprochement des candidats avec les origines secondaires.

## Décisions (prises en autonomie)

- Un devis par origine, l'origine principale alimente les champs existants ; les autres sont dans `otherOrigins` (plus simple qu'un contrat unique, et ne casse ni le lien ni le texte à copier).
- Origine inconnue : groupe à part, exclu des devis, jamais attribué à Jita.
- Structure non résoluble : origine inconnue, aucun appel ESI ajouté.
