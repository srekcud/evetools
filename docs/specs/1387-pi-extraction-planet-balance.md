# Spec #1387 : bilan P0 des planètes d'extraction

Mode nuit : validée en autonomie, décisions notées en bas. « After #1381 » : branche empilée sur #1386 (PR #1424), donc sur toute la pile Colonies. Maquette locale non versionnée : `pi-production-mockup-2026-11-25.html`.

## Contexte

Sur une planète d'extraction qui porte des usines de base (P0 vers P1), le joueur ne sait pas si l'extracteur nourrit ses usines. Les usines de base tournent à un taux qui dépend de ce que l'extracteur sort (rendement décroissant de #1381) contre ce qu'elles demandent. Le bilan répond à « une tête de plus ou une usine de moins ».

## Prérequis vérifiés sur la branche

- `PlanetaryInputNeedsCalculator` (#1380) calcule déjà par colonie et par type la demande des usines à plein régime (`dailyRequired`) et ce que la colonie produit (`dailyProducedLocally`, extraction = moyenne du programme de #1381, `null` si inconnue ; un extracteur expiré ne compte pas). Son calcul de flux est privé : le bilan le réutilise (`calculate([$colony])`), il ne le recopie pas.
- `PlanetaryPin::getExtractorNumHeads()` donne les têtes d'un extracteur ; le rendement par tête n'est pas stocké (la quantité d'un programme est l'ensemble des têtes).
- Aucun bilan P0 n'existe, ni côté API ni côté écran.

## Backend (`ColonyResource`, liste et détail, sans migration)

1. Service `PlanetaryExtractionBalanceCalculator` (`src/Service/Planetary/`). Pour une colonie, une ligne par type P0 extrait par un extracteur de la colonie et demandé par au moins une de ses usines : `typeId`, `typeName`, `extractedPerDay`, `demandedPerDay`, `balancePerDay`, `runRate`, `factoryCount` (usines qui consomment ce type), `headCount`, `advice`. Une colonie sans extracteur, sans usine ou sans type commun : liste vide.
2. `extractedPerDay` = `dailyProducedLocally` du type ; `demandedPerDay` = `dailyRequired`. `balancePerDay` = extrait − demandé. `runRate` = `min(1, extrait / demandé)`, jamais au-dessus de 1. Extraction inconnue (`null`) : `extractedPerDay`, `balancePerDay`, `runRate` et `advice` valent `null`, jamais 0. Extracteur expiré : il ne compte pas dans l'extrait (même règle que #1380), donc `runRate` 0 s'il était le seul.
3. `headCount` = somme des têtes des extracteurs du type qui ne sont pas expirés ; `null` si l'un des nombres est inconnu.
4. Conseil (`advice`, `null` si inconnu), tolérance `1e-6` :
   - déficit (balance < 0) : `kind` `deficit`, `heads` = `ceil(déficit / rendementParTête)` avec `rendementParTête = extrait / headCount` (`null` si `headCount` est 0 ou inconnu, ou extrait 0), `factories` = `ceil(déficit / demandeParUsine)` avec `demandeParUsine = demandé / factoryCount` ;
   - excédent d'au moins une usine (balance ≥ `demandeParUsine`) : `kind` `surplus`, `factories` = `floor(balance / demandeParUsine)`, `heads` `null` ;
   - sinon : `kind` `balanced`.
5. `ColonyResource::$extractionBalance` (`list<array>`, vide par défaut) rempli par `PlanetaryResourceMapper::toResource` ; clé présente même vide.
6. Portée : colonies de l'utilisateur connecté, comme aujourd'hui. Pas de nouvelle ressource, pas de lecture ESI, pas de Mercure.

## Frontend

7. Type `Colony.extractionBalance` dans le store.
8. Composant `PiExtractionBalance.vue` dans le détail déplié d'une colonie qui a au moins une ligne : tableau par P0 avec extrait par jour, demandé par jour, solde, jauge du taux de fonctionnement (`runRate` en %, vert à 100 %, ambre en dessous de 100 %, rouge sous 50 %), nombre d'usines et de têtes, et le conseil : déficit « +N tête(s) ou −M usine(s) », excédent « une usine de plus tient (+M) », équilibre « équilibré ». Inconnu : « inconnu », pas de jauge, pas de conseil. Têtes inconnues : seule l'option usine est dite.
9. Une ligne de colonie sans bilan (liste vide) n'affiche rien. i18n FR et EN (`pi.balance.*`), noms longs testés, direction HUD EVE, aucun bloc existant retiré.

## Exemples chiffrés

| Cas | Données | Résultat |
|---|---|---|
| Déficit | extrait 100 000 /j (2 têtes), 2 usines à 144 000 /j chacune (demandé 288 000) | balance −188 000, `runRate` 0,347, tête : `ceil(188 000 / 50 000)` = 4, usines : `ceil(188 000 / 144 000)` = 2 |
| Équilibre | extrait 288 000, demandé 288 000 | balance 0, `runRate` 1, `balanced` |
| Excédent | extrait 450 000, 2 usines (288 000) | balance +162 000, `surplus`, 1 usine de plus |
| Inconnu | extracteur sans installation | tout `null`, pas de conseil |
| Expiré | seul extracteur expiré | extrait 0, `runRate` 0, `heads` `null` |

## Tests

- PHP : calculateur (déficit, équilibre, excédent, taux plafonné à 1, inconnu, expiré, têtes inconnues, colonie sans usine ou sans extracteur), mapper (clé présente dans la liste et le détail, vide hors extraction).
- Front (au plus 10) : tableau, jauge par seuil, conseil de déficit, d'excédent, d'équilibre, inconnu, têtes inconnues, absence de bloc sans bilan.

## Hors périmètre

Contrôle du CPU et du PG pour la tête ajoutée (les jauges de #1385 sont dans la carte), planètes usine (bilan P1 et au-delà), bilan par extracteur, alertes, taux de fonctionnement du calculateur de revenus (`resolveRunRates`).

## Décisions

- Taux de fonctionnement = extrait / demandé plafonné à 1 : une estimation de capacité des usines de base, distincte de l'état de stock du calculateur de revenus, qui n'est pas modifié.
- Rendement par tête = extrait / têtes : les têtes d'un extracteur sont supposées égales (le jeu les place à des rayons différents, la valeur par tête n'est pas lue).
