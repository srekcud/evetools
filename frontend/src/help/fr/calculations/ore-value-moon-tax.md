# Valeur du minerai et taxe lunaire

Cette page explique comment EVE Tools donne un prix à une unité de minerai, de glace ou de gaz, et comment il calcule la taxe lunaire d'une raffinerie. Le déroulé pas à pas (opérations, réglage d'une raffinerie, dons) est dans le playbook [opération de minage et taxe lunaire](/help?page=playbooks/mining-moon-tax).

Deux règles de prix coexistent :

- la **valeur du minerai** suit votre **profil de valorisation minière** (marché, côté, base, structure, taxe d'export), réglé sur la page « Valeur du minerai » ;
- la **taxe lunaire** ignore ce profil : elle prend toujours le prix d'achat Jita du minerai compressé, sans taxe d'export.

## Où les chiffres apparaissent

| Écran | Chiffre |
|---|---|
| Menu « Minage », onglet « Valeur du minerai », tableau « Valeur au m³ extrait » | Par minerai : « Brut », « Compressé », « Retraité », « Meilleur » et « Écart au meilleur », en ISK/m³ |
| Même page, panneau « Rendement de retraitement » | Le rendement utilisé pour la colonne « Retraité » |
| Onglet « Mon minage » | « Valeur totale » du minerai miné sur la période, avec sa source de valorisation |
| Onglet « Lunes », sous-onglet « Chunks », colonne « Valeur » | « Valeur estimée » d'un chunk et sa part « À 4 », « À 6 », « À 8 participants » |
| Onglet « Lunes », sous-onglet « Taxe lunaire » | « Dû », « Payé », « Reste » et « Statut » de chaque relevé |

Les réglages en haut de la page « Valeur du minerai » (« Taxe export », « Structure », « Marché », « Base de valorisation ») sont enregistrés sur votre compte : ils valorisent aussi « Mon minage » et les chunks (« La structure et la taxe d'export valorisent aussi les lignes de Mon minage. »).

## La formule

### Prix unitaire d'un minerai

Pour chaque minerai, EVE Tools lit le **meilleur ordre** du carnet (achat le plus haut pour « Jita achat » et « Structure achat », vente la plus basse pour « Jita vente » et « Structure vente »). Ce n'est pas un prix pondéré.

Brut = max(0, prix du minerai brut − taxe export × volume d'une unité brute)
Compressé = max(0, prix du minerai compressé − taxe export × volume d'une unité compressée) × taille de lot compressée ÷ taille de lot brute
Retraité = Σ (quantité de minéral par lot × rendement × prix du minéral) ÷ taille de lot brute

- Les trois chiffres sont des prix **par unité brute minée**. Le compressé est ramené à l'unité brute par les tailles de lot (portion size) du SDE : c'est le **prix compressé équivalent**.
- La variante compressée est le type nommé « Compressed » suivi du nom du minerai brut. Sans ce type, « Compressé » est inconnu.
- La **taxe d'export** (« Taxe export », ISK/m³) ne s'applique qu'aux prix Jita bruts et compressés. Elle ne touche ni le retraité ni les prix de structure. Un prix net négatif est ramené à 0.
- Le retraité utilise les quantités de minéraux du SDE pour un lot de minerai brut. Si un seul minéral n'a pas de prix, ou si la somme vaut 0, le retraité est inconnu.

### Valeur au m³ (tableau « Valeur au m³ extrait »)

ISK/m³ = prix unitaire ÷ volume d'une unité brute
Meilleur = la plus haute des trois valeurs connues (à égalité : compressé, puis retraité, puis brut)
Écart au meilleur = (meilleure valeur du tableau − « Meilleur » de la ligne) ÷ meilleure valeur du tableau × 100

Le volume est celui du minerai brut, même pour le compressé : le tableau compare ce que rapporte un m³ **extrait par le laser**. La ligne en tête du tableau a un écart de 0 %. L'écart est arrondi à une décimale à l'affichage.

Le tableau liste les types publiés de la catégorie astéroïde et du groupe des nuages de gaz, présents au marché, sans leurs variantes compressées. Famille « Glace » : groupe SDE 465 ; « Gaz » : groupe 711 ; tout le reste est « Minerai ».

### Rendement de retraitement

Panneau « Rendement de retraitement » : vous choisissez « Personnage », « Compétence de retraitement du minerai », « Lieu », « Rig de retraitement », « Sécurité » et « Implant ». EVE Tools lit les niveaux de compétences du personnage par l'ESI et calcule :

rendement = base × (1 + 0,03 × Reprocessing) × (1 + 0,02 × Reprocessing Efficiency) × (1 + 0,02 × compétence de minerai) × (1 + implant ÷ 100)

| Lieu | Base |
|---|---|
| « Station NPC » | 0,50 |
| « Athanor » ou « Tatara » | (50 + bonus du rig) ÷ 100 × (1 + bonus de sécurité) × (1 + bonus de la structure) |

| Réglage | Valeurs |
|---|---|
| « Rig de retraitement » | « Aucun rig » 0, « Rig T1 » 1, « Rig T2 » 3 (points ajoutés aux 50) |
| « Sécurité » | « Sécurité haute » 0, « Sécurité basse » 0,06, « Sécurité nulle et trous de ver » 0,12 |
| Structure | « Athanor » 0,02, « Tatara » 0,055 |
| « Implant » | 0, 1, 2 ou 4 % |

Le rig et la sécurité ne comptent qu'en structure. Le résultat n'est pas plafonné par EVE Tools. Le rendement est recalculé quand vous enregistrez le panneau ou cliquez sur « Recalculer ».

### Valeur du minerai miné (« Mon minage »)

Valeur totale = Σ (quantité minée du type × prix unitaire du type selon votre profil)

La quantité vient de votre ledger de minage, sommée sur la période. La base de valorisation du profil (« Brut », « Compressé » ou « Retraité ») choisit lequel des trois prix unitaires est pris ; « Retraité » utilise le rendement de votre panneau. Une opération de minage a sa propre base, qui remplace votre profil (playbook, étape 3).

### Valeur d'un chunk

volume du chunk = durée d'extraction en heures × 25 000 m³/h
unités d'un minerai = part du minerai × volume du chunk ÷ volume d'une unité brute
valeur estimée = Σ (unités × prix unitaire selon votre profil)
part par participant = valeur estimée ÷ 4, 6 ou 8

La durée d'extraction va du début de l'extraction à l'arrivée du chunk. Les parts viennent du scan de la lune collé dans « Scan de la lune ». Si la somme des parts est inférieure à 100 %, le reste ne vaut rien : rien n'est remis à l'échelle. La ligne du tableau montre la part « à 6 » ; « Afficher le détail de la valeur » donne les autres et le calcul du volume. C'est une valeur avant booster et taxe.

### Taxe lunaire

Pour chaque personnage, raffinerie et période, EVE Tools additionne le ledger de la raffinerie par type de minerai, puis :

taxe d'un type = arrondi à 2 décimales (quantité × prix compressé équivalent Jita achat × taux de la rareté ÷ 100)
taxe du relevé = arrondi à 2 décimales (Σ taxes des types)

- Prix : meilleur ordre d'achat Jita du minerai compressé, ramené à l'unité brute, sans taxe d'export et sans structure, quel que soit votre profil.
- **Rareté** : déduite du groupe SDE du minerai : 1884 = R4, 1920 = R8, 1921 = R16, 1922 = R32, 1923 = R64. Le taux est celui saisi pour la raffinerie (« Taux R4 (%) » à « Taux R64 (%) »).
- Période : une ligne du ledger appartient à la période qui contient sa date (semaine du lundi 00:00 UTC, ou mois du 1er 00:00 UTC).
- Le **relevé** est créé, à la fin d'une lecture réussie du ledger, pour une période terminée depuis au moins une heure. Ses lignes, prix et taux sont alors figés. La période en cours reste « Provisoire » et se recalcule à chaque affichage avec les prix du moment.

### Règlement par les dons

Payé = somme des dons appliqués au relevé
Reste = Dû − Payé, arrondi à 2 décimales

Un don en ISK reçu par la corporation paie un relevé si les trois conditions sont vraies :

1. même corporation ;
2. même payeur : le personnage auquel un gestionnaire a attribué le don, sinon le donateur ;
3. date du don postérieure ou égale au **début** de la période du relevé.

Les dons sont pris du plus ancien au plus récent. Chacun paie d'abord le relevé qui finit le plus tôt (toutes raffineries de la corporation confondues), et son surplus passe au suivant qui remplit les conditions. **Le motif du don n'est jamais lu** : la référence `TAX-…` ne sert qu'à la lecture humaine.

Statut d'un relevé non annulé et non marqué payé : « Payé » si le reste est nul ; sinon « En retard » plus de 7 jours après la fin de la période ; sinon « Partiel » si une partie est payée, « À payer » sinon.

## D'où vient chaque terme

| Terme | Source | Fraîcheur |
|---|---|---|
| Prix Jita des minerais, compressés et minéraux | ESI `/markets/10000002/orders/`, meilleur ordre de la station Jita 4-4 | Synchronisé toutes les heures, gardé 2 h |
| Prix d'une structure | Marché de la structure (ESI), lu avec un personnage qui y a accès | Toutes les heures si c'est la structure par défaut ou une structure de marché choisie par un utilisateur ; sinon seulement par « Sync prix structure ». Gardé 2 h |
| Volume, taille de lot, minéraux de retraitement, groupe (rareté, famille) | SDE | À chaque import du SDE |
| Niveaux de compétences | ESI, compétences du personnage du panneau | À l'enregistrement ou à « Recalculer » |
| Lieu, rig, sécurité, implant, taxe d'export, marché, base, structure | Vos réglages | Enregistrés sur votre compte |
| Quantités de « Mon minage » | ESI, ledger de minage de vos personnages | Voir [fraîcheur et confidentialité](/help?page=data/freshness-privacy) |
| Début d'extraction et arrivée du chunk | ESI, extractions de la corporation | Toutes les 30 minutes |
| Parts du minerai d'une lune | Scan collé par un joueur | Jusqu'au collage suivant |
| Ledger de la raffinerie | ESI `/corporation/{id}/mining/observers/{observer_id}/`, une ligne par personnage, type et jour, remplacée à chaque lecture | Toutes les heures, ou « Synchroniser » |
| Dons | ESI `/corporations/{id}/wallets/{division}/journal/`, lignes `player_donation` positives reçues par la corporation | Toutes les heures, ou « Synchroniser » |
| Taux R4 à R64, période | Réglage de la raffinerie | Figés dans le relevé à sa création |

## Exemple

Valeurs d'exemple, rondes, qui ne viennent pas du jeu.

**Rendement.** Station NPC, Reprocessing 5, Reprocessing Efficiency 5, compétence de minerai 5, implant 0 % :
0,50 × 1,15 × 1,10 × 1,10 = 0,69575, soit 69,575 %.

**Valeur d'un minerai.** Minerai « Exemple » : 10 m³ par unité brute, compressé à 0,1 m³ par unité, même taille de lot (100) brut et compressé. Un lot de 100 unités brutes se retraite en 1 000 minéral A et 100 minéral B. Profil : « Jita achat », « Taxe export » 10 ISK/m³, rendement 70 %. Meilleurs ordres d'achat Jita : brut 1 000 ISK, compressé 1 100 ISK, minéral A 50 ISK, minéral B 400 ISK.

| Chiffre | Calcul | Résultat |
|---|---|---|
| Brut, par unité | 1 000 − 10 × 10 | 900 ISK |
| Compressé, par unité brute | (1 100 − 10 × 0,1) × 100 ÷ 100 | 1 099 ISK |
| Retraité, par unité brute | (1 000 × 0,7 × 50 + 100 × 0,7 × 400) ÷ 100 | 630 ISK |
| « Brut » en ISK/m³ | 900 ÷ 10 | 90 |
| « Compressé » en ISK/m³ | 1 099 ÷ 10 | 109,9 |
| « Retraité » en ISK/m³ | 630 ÷ 10 | 63 |
| « Meilleur » | la plus haute | 109,9 (compressé) |

Si c'est la meilleure ligne du tableau, son écart vaut 0,0 % ; une autre ligne à 100 ISK/m³ affiche (109,9 − 100) ÷ 109,9 × 100 = 9,0 %. Avec la base « Compressé », 1 000 unités minées valent 1 000 × 1 099 = 1 099 000 ISK dans « Mon minage ».

**Valeur d'un chunk.** Extraction de 100 h : 100 × 25 000 = 2 500 000 m³. Scan : minerai A 40 %, minerai B 60 %, tous deux à 10 m³ par unité, prix du profil 500 ISK et 100 ISK.

| Minerai | Unités | Valeur |
|---|---|---|
| A | 0,4 × 2 500 000 ÷ 10 = 100 000 | 50 000 000 ISK |
| B | 0,6 × 2 500 000 ÷ 10 = 150 000 | 15 000 000 ISK |
| Total | | 65 000 000 ISK |

La ligne affiche « ≈ 65 000 000 ISK · 10 833 333 ISK / pers. à 6 » ; le détail donne aussi 16 250 000 ISK à 4 et 8 125 000 ISK à 8.

**Taxe lunaire.** Raffinerie hebdomadaire, « Taux R4 (%) » 5, « Taux R16 (%) » 10. En semaine 1, un personnage mine 10 000 unités d'un minerai R16 (prix compressé équivalent Jita achat 500 ISK) et 20 000 unités d'un minerai R4 (100 ISK).

| Ligne | Calcul | Taxe |
|---|---|---|
| R16 | 10 000 × 500 × 10 ÷ 100 | 500 000 ISK |
| R4 | 20 000 × 100 × 5 ÷ 100 | 100 000 ISK |
| Relevé semaine 1 | | 600 000 ISK |

Son relevé de semaine 2 vaut 300 000 ISK. En semaine 2, il donne 400 000 ISK le mardi puis 250 000 ISK le mercredi, depuis le personnage qui a miné.

| Don | Effet |
|---|---|
| 400 000 (mardi) | Semaine 1 : payé 400 000, reste 200 000 |
| 250 000 (mercredi) | Semaine 1 : payé 600 000, « Payé ». Surplus de 50 000 : la semaine 2 commence le lundi, avant le don, donc il lui revient |

Tant que la semaine 2 est « Provisoire », elle affiche « Payé » 0 et « Reste » 300 000. Une fois son relevé créé, elle affiche « Payé » 50 000 et « Reste » 250 000.

## Quand le chiffre est inconnu

L'inconnu s'affiche « — » (infobulle « Valeur inconnue ») ou « inconnue », jamais 0.

| Donnée manquante | Effet | À l'écran |
|---|---|---|
| Aucun ordre sur le marché choisi pour le minerai brut ou compressé | Prix de cette colonne inconnu | « — » dans la colonne |
| Pas de variante « Compressed … », ou taille de lot absente ou nulle | « Compressé » inconnu | « — » |
| Aucun rendement calculé (panneau non rempli, jeton ESI absent, scope des compétences manquant, erreur ESI) | « Retraité » inconnu partout | « Aucun rendement de retraitement calculé : les valeurs retraitées sont inconnues. … » et le message d'échec du panneau |
| Un minéral sans prix | « Retraité » inconnu pour ce minerai | « — » |
| Minerai sans minéraux de retraitement dans le SDE | Pas de retraité | « — » avec « Ne peut pas être retraité » pour les lecteurs d'écran |
| Volume du minerai absent ou nul | Toutes ses valeurs au m³ inconnues | « — » |
| Aucune des trois valeurs connue | « Meilleur » et « Écart au meilleur » inconnus | « — », ligne en bas du tableau |
| Un type miné sans prix sur votre profil | « Valeur totale » inconnue, pas une somme partielle | « Minerais sans prix : … » |
| Aucun minage sur la période | Valeur totale 0 : il n'y a rien à valoriser | 0 |
| Pas de scan de la lune | Chunk non valorisé | « valeur inconnue · coller le scan de la lune » |
| Un minerai du scan sans prix | Chunk non valorisé | « valeur inconnue · prix manquant » |
| Un minerai du scan sans volume | Chunk non valorisé | « valeur inconnue · volume du minerai manquant » |
| Un type du ledger sans prix compressé Jita achat, ou hors des groupes R4 à R64 | Taxe du relevé inconnue, statut « Inconnu », aucun don appliqué | « inconnue » et « sans prix : » suivi des numéros de type ; totaux « inconnu » avec « dont … connus » |

## Pièges

- **Deux prix différents pour le même minerai lunaire.** La valeur d'un chunk et « Mon minage » suivent votre profil ; la taxe lunaire prend toujours Jita achat compressé. Avec un profil « Jita vente » ou « Retraité », la taxe ne se déduit pas de la valeur affichée.
- **Le message d'un chunk sans prix est trompeur.** Le détail dit « Au moins un minerai du scan n'a pas de prix d'achat Jita compressé. » alors que le prix manquant est celui de votre profil.
- **La taxe d'export est partielle.** Elle n'est retirée qu'aux prix Jita bruts et compressés. Le retraité et les prix de structure sont bruts de toute taxe d'export, ce qui avantage la colonne « Retraité » face au compressé quand vous saisissez une taxe.
- **Un prix ramené à 0 n'est pas inconnu.** Si la taxe d'export dépasse le prix, le brut ou le compressé affiche 0 au lieu d'un « — ».
- **Meilleur ordre, pas carnet.** Le prix est celui du premier ordre, quelle que soit la quantité. Pour vendre beaucoup, voyez le prix pondéré de l'[Évaluateur](/help?page=calculations/weighted-price-appraisal).
- **Prix de structure périmés.** La structure de votre profil n'est relue chaque heure que si elle est aussi la structure par défaut ou une structure de marché choisie par un utilisateur. Sinon, ses prix disparaissent 2 h après le dernier « Sync prix structure » et les valeurs passent à inconnu.
- **Le champ « Structure » vide affiche une structure marquée « (defaut) ».** Le profil minier n'a pourtant pas de structure par défaut : tant que vous n'en choisissez pas, « Structure achat » et « Structure vente » restent grisés.
- **Un relevé fige le prix du moment de sa création,** environ une heure après la fin de la période, pas celui du jour du minage.
- **Les dons ne réduisent pas une ligne « Provisoire ».** Un don fait pendant la période en cours ne s'affiche qu'une fois le relevé créé.
- **Un don daté d'avant le début d'une période ne la paie jamais.** Il ne paie que les relevés de périodes déjà commencées à sa date : on ne paie pas d'avance une période future.
- **L'écart au meilleur ignore le filtre de famille.** Avec « Glace » ou « Gaz », l'écart reste mesuré contre la meilleure ligne de tout le tableau, minerais compris.
- **Changer la « Période » d'une raffinerie peut créer un doublon.** Un relevé est identifié par raffinerie, début de période et personnage. Passer de « Hebdomadaire » à « Mensuelle » change les débuts de période : les jours déjà facturés par des relevés hebdomadaires peuvent produire un relevé mensuel en plus. Choisissez la période avant la première synchronisation.
- **Une ligne de ledger tardive est perdue pour la taxe.** Une fois le relevé créé, une nouvelle ligne de la même période et du même personnage n'est plus comptée.

## Note technique

Valeur du minerai : `OreValueService::getBatchOrePrices()` (prix brut, `compressedEquivalentPrice`, `reprocessValue`), table `OreValueTableService::build()` (`GET /api/mining/ore-values`, `ICE_GROUP_ID` = 465, `HARVESTABLE_CLOUD_GROUP_ID` = 711), profil `MiningProfileUnitPrices::read()` et `MiningValuationSettings::defaults()` (Jita, achat, compressé, taxe d'export 0). Meilleur ordre : `JitaMarketService::getPrices()`, `getBuyPrices()` (`CACHE_TTL` = 7200 s) ; `StructureMarketService::getLowestSellPrices()`, `getHighestBuyPrices()` (`CACHE_TTL` = 7200 s). Rendement : `ReprocessingYieldCalculator::calculate()` (`NPC_STATION_BASE_YIELD` = 0,50, `STRUCTURE_BASE_PERCENT` = 50, bonus par niveau 0,03, 0,02, 0,02), `ReprocessingRigTier`, `ReprocessingSecurity`, `ReprocessingLocation`. Valeur minée : `MiningBestValueCalculator::getTotalBestValue()`. Chunk : `MoonChunkValueReader::read()`, `MoonChunkValuer::value()` (`PARTICIPANT_COUNTS` = 4, 6, 8), `MoonChunkVolumeEstimator` (`EXTRACTION_RATE_M3_PER_HOUR` = 25 000). Taxe : `MoonTaxUnitPrices::read()`, `MoonOreRarity::fromGroupId()`, `MoonTaxCalculator::calculate()` (`MONEY_DECIMALS` = 2), `MoonTaxPeriod::containing()`, `MoonTaxStatementCloser::close()` (`LEDGER_CACHE_DELAY` = 1 h), `MoonTaxStatementReader` (lignes provisoires), `MoonTaxSettlement::settle()` (`PAYMENT_GRACE_DAYS` = 7).
