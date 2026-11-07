# #343 — Acheter ou fabriquer chaque intermédiaire, import depuis Jita compris

Maquette : `industry-project-v3.html#p=7` (carte « Acheter ou fabriquer », notes 23 et 27). Groupe `unified-industry`. Mode nuit : validée en autonomie.

## Contexte

Le plan d'un projet (`ProductionPlanning::planOf`) fabrique tous les intermédiaires sans les comparer à leur achat. `BuyVsBuildService` compare déjà un composant à son achat, mais pour un produit isolé, sans import et sans lien avec un projet. Cette spec ajoute, à la lecture, une comparaison par intermédiaire du plan d'un projet. Rien n'est persisté.

## Règles

1. **Périmètre** : chaque `Intermediate` du plan du projet qui a au moins un job (un intermédiaire dont le stock couvre la demande n'a pas de ligne). Le plan est celui de `IndustryProjectService` (structures, systèmes favoris, blacklist et politique de structure du propriétaire) : `planOf` devient publique, sans changer son comportement. Un type blacklisté n'est pas un intermédiaire du plan : il n'a pas de ligne.
2. **Fabriquer** = coût total du ou des jobs de l'intermédiaire (`ProductionCostCalculator::costOf`, `CostMode::Plan`, `Runs` = somme des runs des jobs, ME et TE du premier job, matériaux directs achetés au marché : `blacklist` = types des matériaux du premier job, comme `BuyVsBuildService`). Les runs sont entiers : le coût compte tous les runs, surplus compris. Par unité : coût total ÷ demande.
3. **Surplus** : le surplus ne réduit pas le coût de fabrication. Si `surplus > 0`, la ligne porte en plus le coût par unité « si le surplus resert » = coût total ÷ quantité produite. Aucune valeur n'est créditée au projet.
4. **Acheter** = prix Jita + import. Prix Jita : `MarketPrices::unitPricesFor([typeId => demande])`, prix pondéré × demande, prix partiel conservé (`Cost::withPartialPrice` comme `ProductionCostCalculator::boughtCost`). Import = volume SDE (`ItemVolumes::volumeOf`) × demande × sauts × `StructureTotalCostComparator::TRANSPORT_ISK_PER_CUBIC_METER_JUMP` (150 ISK par m³ et par saut). Sauts : `JumpDistances::jumpsBetween(Jita, atelier)`, Jita = `EveConstants::JITA_SOLAR_SYSTEM_ID`, atelier = système favori de l'activité du job (`FavoriteSystems::forActivity`), manufacturing pour une fabrication, reaction pour une réaction.
5. **Inconnu, jamais zéro** : prix absent → `MissingData::marketPrice(typeId)` ; volume absent → `MissingData::itemVolume(typeId)` ; pas de système favori pour l'activité ou route inconnue → `MissingData::jumpRoute()`. Un coût d'import inconnu rend le coût d'achat inconnu ; le prix Jita reste affiché.
6. **Choix** : `Build` si fabriquer ≤ acheter (égalité : fabriquer, comme `BuyVsBuildService::verdictOf`), `Buy` sinon, `Unknown` dès que l'un des deux coûts est inconnu. `choiceWithoutImport` : même règle avec l'achat réduit au prix Jita (inconnu si le prix Jita ou le coût de fabrication l'est). L'écran signale « sans l'import : acheter » quand les deux diffèrent.
7. **Économie** : |achat − fabrication| pour la quantité demandée, `null` si le choix est `Unknown`. **Économie avec réemploi** : seulement si le choix est `Buy` et que fabriquer avec réemploi du surplus coûte moins que l'achat : (achat − coût total ÷ produit × demande), sinon `null`.
8. **Ordre des lignes** : celui des intermédiaires du plan.

## Exemples chiffrés (taux 150, 9 sauts)

| Cas | Demande / produit / surplus | Fabriquer (total) | Jita (total) | Volume | Import (total) | Acheter | Choix | Sans import | Économie | Avec réemploi |
|---|---|---|---|---|---|---|---|---|---|---|
| A. Drone, fabrication | 50 / 50 / 0 | 1 687 000 | 1 595 000 | 10 m³ | 675 000 | 2 270 000 | Build | Buy | 583 000 | null |
| B. Composite, réaction | 50 / 500 / 450 | 15 700 000 | 1 545 000 | 1 m³ | 67 500 | 1 612 500 | Buy | Buy | 14 087 500 | 42 500 |
| C. Égalité (1 saut, pas 9) | 10 / 10 / 0 | 1 000 | 400 | 0,4 m³ | 600 | 1 000 | Build | Buy | 0 | null |
| D. Prix Jita absent | 50 / 50 / 0 | 1 687 000 | inconnu | 10 m³ | 675 000 | inconnu | Unknown | Unknown | null | null |
| E. Pas de système favori | 50 / 50 / 0 | 1 687 000 | 1 595 000 | 10 m³ | inconnu | inconnu | Unknown | Buy | null | null |
| F. Volume absent | 50 / 50 / 0 | 1 687 000 | 1 595 000 | inconnu | inconnu | inconnu | Unknown | Buy | null | null |

Par unité (API, `total ÷ demande`) : A fabriquer 33 740, Jita 31 900, import 13 500, acheter 45 400 ; B fabriquer 314 000, « si le surplus resert » 31 400 (15 700 000 ÷ 500), Jita 30 900, import 1 350, acheter 32 250.

## Cas limites

- Atelier = Jita (0 saut) : import 0, connu.
- Plusieurs jobs (plafond de runs) : une seule ligne, runs additionnés.
- Plan sans intermédiaire (produit fait de feuilles) : liste vide, 200.
- Surplus nul : pas de coût « si le surplus resert ».
- Une réaction dont le plan est annulé par le stock : pas de ligne.

## API

`GET /api/industry/projects/{projectId}/buy-or-build` (ressource `ProjectBuyOrBuild`, `uriVariables` avec `Link`, `skip_null_values=false`). Corps : `id` (projet), `importIskPerCubicMeterJump` (150.0), `rows`. Champs d'une ligne : `typeId`, `typeName`, `activity` (`manufacturing` | `reaction`), `quantity` (demande), `runs`, `producedQuantity`, `surplus`, `volumePerUnit`, `jumps`, `buildPerUnit`, `buildPerUnitIfSurplusReused`, `jitaPerUnit`, `importPerUnit`, `buyPerUnit`, `choice` (`build` | `buy` | `unknown`), `choiceWithoutImport`, `saving`, `savingIfSurplusReused`, `missingData`, `warnings` (formats de `MissingDataPresenter` et `PartialPricePresenter`). Montants arrondis à 2 décimales ; inconnu = `null`. Les identifiants des systèmes ne sont pas exposés, seulement le nombre de sauts.

## Accès et fraîcheur

`ProjectAccessChecker::assertAcceptedMember` (404 « Project not found » avant tout contrôle). Le plan utilise les structures et systèmes favoris du **propriétaire**, comme la BOM. Prix Jita lus à la demande par le port `MarketPrices`. Pas de donnée de corporation exposée.

## Front

Carte « Acheter ou fabriquer » (`ProjectBuyOrBuild.vue`) dans l'onglet Chaîne, sous la chaîne, store `group-industry/buyOrBuild.ts`. Colonnes de la maquette : intermédiaire, quantité, fabriquer / u (avec « X si le surplus resert »), Jita / u, import / u (« V m³ × N sauts »), acheter / u, choix (puce fabriquer / acheter / inconnu, économie, « sans l'import : acheter », « avec réemploi du surplus, fabriquer économise Y »). Montant inconnu : tiret et motif (composant existant `UnknownContributionValue.vue` ou équivalent), jamais 0. Prix partiel : `PartialPriceMarker`. Tarif affiché : « import Jita : 150 ISK / m³ / saut ». Pas de bouton de bascule (voir hors périmètre).

## Tests existants modifiés

Aucun : `planOf` ne change que de visibilité.

## Hors périmètre (Blocage, décision de Sylvain)

- **Bouton « Passer en achat / en fabrication »** : la blacklist d'un projet n'est pas modifiable après la création (`UpdateProjectInput` sans blacklist) et la BOM n'est construite qu'à la création. Il faut décider ce que devient une ligne qui porte déjà un job lié ou un apport, et comment lister un type blacklisté pour pouvoir le repasser en fabrication.
- Barème de transport réglable (150 ISK en constante, comme la comparaison des structures), choix de la structure d'import, liste d'achats.
- Valorisation du surplus par d'autres projets (le coût « si le surplus resert » n'est qu'un affichage).

## Glossaire

Ajouter : **Acheter ou fabriquer (projet)**, **Import**, **Coût avec réemploi du surplus**.
