# Prix pondéré et Évaluateur

Cette page explique comment EVE Tools lit un prix dans le carnet d'ordres de Jita, et comment l'Évaluateur valorise une liste collée. Le **prix pondéré** est le prix moyen réellement payé (ou reçu) pour toute une quantité, en remontant le carnet ordre par ordre. Il sert aussi aux matériaux et aux ventes de l'analyse industrielle (voir [profit et marge](/help?page=calculations/margin)).

Ne le confondez pas avec le **prix ajusté** : c'est un prix de référence publié par CCP, qui ne sert qu'au coût d'installation des jobs (voir [coût d'un job](/help?page=calculations/job-cost)). Il n'intervient jamais dans l'Évaluateur.

## Où le chiffre apparaît

Menu « Commerce », onglet « Évaluateur ». Collez votre liste dans « Collez votre liste d'items (depuis le jeu ou au format nom + quantite) », puis « Analyser ».

| Bloc | Ce qui est affiché |
|---|---|
| Carte « Valeur Sell » (« Cout pour acheter ces items ») | Total pondéré côté ordres de vente ; dessous, « Meilleur prix: » au prix de l'ordre le moins cher |
| Carte « Valeur Buy » (« Ce que vous recevez en vendant ») | Total pondéré côté ordres d'achat ; dessous, « Meilleur prix: » au prix de l'ordre d'achat le plus haut |
| Carte « Volume total » | Somme des volumes du SDE |
| Colonne « Prix unitaire » (badges « Jita · plus bas », « Jita achat · plus bas ») | Par ligne : « Sell » et « Buy » pondérés en gros, prix du meilleur ordre en petit, « ~N/jour » (volume journalier moyen) |
| Colonne « Total pondere » (badges « Jita · pondéré », « Jita achat · pondéré ») | Prix pondéré × quantité ; en petit, meilleur prix × quantité ; « … % couvert » si le carnet ne suffit pas |
| Panneau « Comparaison Structure » | « Jita + Transport », « (votre structure) (pondere) », « Meilleur prix (mixte) », « Couverture structure » ; colonnes « + Import », « Sell pondere », « Acheter a » |

## La formule

### Prix pondéré

On consomme les ordres du meilleur au pire jusqu'à couvrir la quantité : ordres de vente du moins cher au plus cher pour « Sell », ordres d'achat du plus cher au moins cher pour « Buy ».

prix pondéré = Σ (unités prises à chaque ordre × prix de l'ordre) ÷ unités couvertes
couverture = unités couvertes ÷ quantité demandée (au plus 1)
total pondéré = prix pondéré × quantité demandée

- La quantité est celle de la ligne collée. Deux lignes au même nom (casse ignorée) sont additionnées avant le calcul.
- Le carnet ne garde que **les 20 meilleurs ordres** de chaque côté. Si leur volume ne couvre pas la quantité, le prix pondéré est celui de la part couverte, appliqué à toute la quantité : c'est un **prix partiel**, marqué « … % couvert » (pourcentage arrondi à une décimale, affiché entre 0,1 et 99,9).
- Le prix unitaire et le total d'une ligne sont arrondis à 2 décimales ; le total est calculé avant l'arrondi du prix unitaire.

### Meilleur prix

meilleur prix = prix du premier ordre du carnet (vente la moins chère ou achat le plus cher)

C'est le chiffre en petit sous chaque prix pondéré et le « Meilleur prix: » des cartes.

### Split

split = (Sell + Buy) ÷ 2, en pondéré comme en meilleur prix. Il n'apparaît que sur une évaluation partagée (carte « Valeur Split »).

### Comparaison avec une structure

Le panneau compare deux façons d'acheter chaque ligne :

Jita + Transport = meilleur prix Sell de Jita × quantité + volume de la ligne × « Cout transport / m3 »
structure = ordre de vente le moins cher de la structure × quantité
Acheter a = le moins cher des deux (Jita en cas d'égalité)
Meilleur prix (mixte) = somme, ligne par ligne, du moins cher des deux

- Le coût de transport vaut 1 200 ISK/m3 par défaut. Il n'est pas enregistré.
- La structure est celle choisie dans « Rechercher une structure (min 3 car.)... », sinon votre structure de marché préférée, sinon la première de vos structures de marché, sinon la structure par défaut de l'application.
- « Couverture structure » compte les lignes qui ont un prix dans la structure, pas les quantités.

### Prix percentile (projets)

Les projets n'utilisent pas le prix pondéré pour le « Cout est. » de la BOM ni pour la valeur d'un apport de matériau : ils prennent la moyenne des 5 % les moins chers du volume en vente à Jita (volume des 20 meilleurs ordres), le dernier ordre étant pris en partie. Sous une unité de volume visé, c'est le prix de l'ordre le moins cher. Voir [projet T2](/help?page=playbooks/t2-project) et [contributions de groupe](/help?page=calculations/group-contributions).

## D'où vient chaque terme

| Terme | Source | Fraîcheur |
|---|---|---|
| Carnet Jita, types courants (matériaux d'industrie, minerais et compressés, produits de retraitement, produits PI, produits fabricables) | ESI `/markets/10000002/orders/`, ordres de la station Jita 4-4 | Synchronisé toutes les heures, gardé 2 h au plus |
| Carnet Jita, autres types (vaisseaux, modules…) | Même endpoint, lu à la demande | Gardé 5 min |
| Prix de la structure | Marché de la structure (ESI), lu avec le personnage qui y a accès | Toutes les heures pour la structure par défaut et toutes les structures de marché choisies par les utilisateurs ; « Sync prix » relit tout de suite ; gardé 2 h |
| Volume unitaire | SDE | À chaque import du SDE |
| Volume journalier moyen (« ~N/jour ») | ESI `/markets/10000002/history/`, moyenne des 30 derniers jours | Gardé 24 h |
| Coût de transport | Votre saisie | Pour cette analyse |
| Évaluation partagée | Copie du résultat au moment du partage | Figée, expire au bout d'une semaine |

## Exemple

Valeurs d'exemple, rondes, qui ne viennent pas du jeu. Vous collez `400 Exemple`. Volume unitaire 0,1 m3.

Ordres de vente à Jita : 100 à 1 000 ISK, 200 à 1 100 ISK, 300 à 1 200 ISK.
Ordres d'achat à Jita : 150 à 900 ISK, 100 à 850 ISK.

| Chiffre | Calcul | Résultat |
|---|---|---|
| Sell pondéré | (100 × 1 000 + 200 × 1 100 + 100 × 1 200) ÷ 400 | 1 100 ISK |
| « Valeur Sell » | 1 100 × 400 | 440 000 ISK |
| « Meilleur prix: » (Sell) | 1 000 × 400 | 400 000 ISK |
| Buy pondéré | (150 × 900 + 100 × 850) ÷ 250 | 880 ISK, « 62.5 % couvert » |
| « Valeur Buy » | 880 × 400 | 352 000 ISK |
| « Meilleur prix: » (Buy) | 900 × 400 | 360 000 ISK |
| « Jita + Transport » | 1 000 × 400 + 40 m3 × 1 200 | 448 000 ISK |
| Structure (meilleur ordre à 1 150 ISK) | 1 150 × 400 | 460 000 ISK |
| « Acheter a » | 448 000 < 460 000 | Jita |

Le carnet d'achat ne couvre que 250 unités sur 400 : l'icône d'alerte indique « Seulement 250 sur 400 disponibles (top 20 ordres) ». Les 352 000 ISK supposent que les 150 unités manquantes se vendraient aussi à 880 ISK, ce qui est optimiste.

Notez que « Jita + Transport » part du meilleur prix (400 000), pas du total pondéré (440 000).

## Quand le chiffre est inconnu

| Donnée manquante | Effet | À l'écran |
|---|---|---|
| Nom non reconnu ou objet non publié | Ligne écartée | « N item(s) non trouve(s) » et la liste des noms |
| Aucun ordre à Jita sur un côté, ou lecture ESI à la demande en échec | Prix de ce côté absent, ligne absente du total de ce côté | « - » dans la cellule |
| Échec de la lecture des prix | Aucun prix | Message « Unable to fetch market prices. Please try again later. », cartes à 0 |
| Volume absent du SDE | Volume de la ligne, « Volume total », « + Import » et « Jita + Transport » inconnus | « Inconnu » et « Volume inconnu dans le SDE pour : … » |
| Prix dans la structure (aucun ordre, structure jamais synchronisée, aucun personnage avec un jeton ESI) | Pas de prix structure pour la ligne | « -- » ; la ligne part à Jita si « Jita + Transport » est connu, sinon « - » |
| Sell ou Buy absent | Split inconnu (évaluation partagée) | « - » |
| Volume journalier non lu | Pas d'indication | « ~N/jour » absent |

## Pièges

- **Les totaux ignorent les lignes sans prix.** « Valeur Sell », « Valeur Buy », la colonne structure et « Meilleur prix (mixte) » additionnent les seules lignes qui ont un prix, sans le signaler. Vérifiez qu'aucune ligne n'affiche « - » avant de vous fier à un total.
- **Une ligne sans prix Sell masque aussi son prix Buy.** Si le côté vente manque, toute la cellule affiche « - », même si un prix d'achat existe.
- **La colonne structure n'est pas pondérée.** « (pondere) », « Sell pondere » et l'infobulle « Prix sell pondere base sur les ordres disponibles » le disent, mais le calcul prend l'ordre de vente le moins cher de la structure × quantité, sans tenir compte du volume disponible.
- **« Jita + Transport » et « Acheter a » partent du meilleur prix Jita**, pas du prix pondéré de la colonne voisine. Pour une grosse quantité, la comparaison favorise Jita.
- **« item(s) avec couverture faible »**, sous la colonne structure, compte les lignes dont le carnet de **vente Jita** couvre moins de la moitié de la quantité.
- **L'infobulle de « … % couvert » côté Buy est celle du côté vente** : elle parle d'un coût probablement plus élevé. Côté achat, c'est la recette qui est probablement plus basse.
- **Un carnet limité.** Seuls les 20 meilleurs ordres de chaque côté sont gardés, lus sur la première page de résultats ESI. Une couverture à 100 % veut dire « ces ordres suffisent », pas « le marché suffit ».
- **Station ou région.** Les ordres de la station Jita 4-4 sont pris en priorité. S'il n'y en a aucun sur un côté, ce sont ceux de toute la région The Forge, sans mention à l'écran.
- **Le format n'est pas lu.** Le choix « Format : » n'est pas envoyé à l'analyse, et « Multibuy detecte » s'affiche quel que soit le format collé en mode « Auto-detect ».
- **Une évaluation partagée est une photo.** Le lien (« Partager », copié automatiquement) ouvre une page lisible sans compte, avec les prix du moment du partage, jamais rafraîchis. Elle montre la colonne Split mais pas la comparaison avec la structure. Après une semaine, la page affiche « Cet appraisal partage n'existe pas ou a expire. » ; les évaluations expirées sont effacées une fois par jour.

## Note technique

Évaluation : `AppraiseProcessor::process()` (`POST /api/shopping-list/appraise`). Prix : `JitaMarketService::getPricesWithFallback()`, `getBuyPricesWithFallback()`, `getWeightedSellPricesWithFallback()`, `getWeightedBuyPricesWithFallback()`, calcul par `calculateWeightedPrice()` ; carnet par `collectOrderBooks()` (constante `MAX_ORDERS_PER_TYPE` = 20) ; cache `CACHE_TTL` = 7200 s, `ON_DEMAND_CACHE_TTL` = 300 s, `VOLUME_CACHE_TTL` = 86400 s, `VOLUME_HISTORY_DAYS` = 30. Percentile : `getCheapestPercentilePrices()` (`CHEAPEST_PERCENTILE` = 0,05). Couverture affichée : `PartialPricePresenter::coveragePercentOfCoverage()`. Comparaison structure : `ParseProcessor::process()` (`POST /api/shopping-list/parse`), `MarketService::comparePrices()`, `StructureMarketService::getLowestSellPrices()`. Partage : `ShareListProcessor`, `SharedShoppingList` (expiration `+1 week`), purge `PurgeExpiredSharedListsHandler` planifiée chaque jour dans `SyncScheduler`.
