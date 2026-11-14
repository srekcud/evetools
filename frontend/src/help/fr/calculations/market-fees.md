# Frais de vente : courtage et taxe de vente

Cette page explique comment EVE Tools calcule les frais d'une vente au marché, à partir des compétences et des standings de votre personnage principal, et pourquoi ils peuvent être inconnus. La marge qui en découle est décrite dans [marge](/help?page=calculations/margin). Pour synchroniser compétences et standings : [premiers pas](/help?page=playbooks/getting-started), étape 5.

## Où le chiffre apparaît

Les frais de vente sont la somme de deux taux : le **courtage** (frais de l'ordre de vente) et la **taxe de vente** (prélevée à chaque vente).

- **Menu « Industrie », onglet « Analyse », sous-onglet « Marges ».** L'en-tête du tableau « Comparaison prix de vente » affiche « Courtage » suivi du taux, et la colonne « Frais » le montant par lieu de vente. La carte « Où vendre » donne les « Frais », le « Net / u » de chaque marché et le « Net » de la « Vente immédiate · ordres d'achat Jita ».
- **Sous-onglets « Scan », « Achat vs Prod » et « Pivot ».** La marge de chaque ligne est nette des frais.
- **Onglet « Atelier », carte « Quoi produire ».** Le profit par semaine est net des frais du vendeur.
- **Un projet, onglet « Finances ».** Le « Résumé financier » affiche « Frais courtier (x %) » et « Taxe de vente (x %) ». Ces deux taux sont ceux du projet, copiés à sa création (voir plus bas).

## Qui est le vendeur

Le vendeur est toujours le **personnage principal** de votre compte, celui marqué de l'étoile sur la page Personnages. Ses compétences et ses standings servent pour tous vos écrans, même si vous vendez avec un autre personnage. Changer de personnage principal change les frais dès l'affichage suivant.

## La formule

taxe de vente = 7,5 % × (1 − 0,11 × Accounting)

courtage = 3 % − 0,3 % × Broker Relations − 0,03 % × standing de faction − 0,02 % × standing de corporation

- **Accounting** et **Broker Relations** : niveaux (0 à 5) de ces deux compétences du personnage principal.
- **Standing de faction** et **standing de corporation** : standings du personnage principal envers la faction et la corporation NPC de la station Jita 4-4, de −10 à 10. Le calcul utilise toujours ceux de Jita, quel que soit le marché où vous vendez.
- Les standings sont les valeurs brutes lues dans l'ESI, sans l'effet des compétences Connections ou Diplomacy.
- Un standing absent de la liste lue dans l'ESI compte comme neutre (0).
- Aucun plancher : un standing négatif augmente le courtage. Avec Broker Relations 5 et des standings à 10, le courtage tombe à 1 %.

Le taux appliqué dépend de la façon de vendre :

| Façon de vendre | Taux appliqué |
|---|---|
| Ordre de vente (Jita ou structure, étiquettes « vente » et « sous-enchere ») | courtage + taxe de vente |
| Vente à un ordre d'achat existant (« vente instantanee », « Vente immédiate ») | taxe de vente seule |
| Contrat (« contrat ») | aucun frais |

frais = revenu × taux appliqué, avec revenu = prix unitaire × quantité.

## D'où vient chaque terme

| Terme | Source | Fraîcheur |
|---|---|---|
| Niveaux Accounting et Broker Relations | ESI `GET /characters/{id}/skills/` (scope `esi-skills.read_skills.v1`) | Lus seulement quand vous cliquez « Sync toutes », jamais planifiés |
| Standings | ESI `GET /characters/{id}/standings/` (scope `esi-characters.read_standings.v1`) | Lus par le même bouton, jamais planifiés |
| Personnage principal | Votre choix, page Personnages | Immédiat |
| Taux d'un projet | Copiés depuis vos frais à la création, puis modifiables à la main | Figés : jamais recalculés |

Les frais sont recalculés à chaque affichage des écrans d'analyse, à partir des dernières valeurs synchronisées. Seuls les projets gardent leurs taux d'origine.

## Exemple

Valeurs d'exemple, rondes, qui ne viennent pas du jeu.

Un personnage principal avec Accounting 4, Broker Relations 4, un standing de faction de 2 et un standing de corporation de 5.

- Taxe de vente = 7,5 % × (1 − 0,11 × 4) = 7,5 % × 0,56 = **4,2 %**.
- Courtage = 3 % − 1,2 % − 0,06 % − 0,1 % = **1,64 %**.

Vente de 100 unités à 1 000 000 ISK, soit un revenu de 100 000 000 ISK :

| Façon de vendre | Taux | Frais | Net |
|---|---|---|---|
| Ordre de vente | 1,64 % + 4,2 % = 5,84 % | 5 840 000 | 94 160 000 |
| Ordre d'achat existant | 4,2 % | 4 200 000 | 95 800 000 |
| Contrat | 0 % | 0 | 100 000 000 |

Repères : sans aucune des deux compétences et avec des standings neutres, 7,5 % de taxe et 3 % de courtage ; avec les deux compétences au niveau 5 et des standings neutres, 3,375 % et 1,5 %.

## Quand les frais sont inconnus

Les frais sont **inconnus**, jamais 0 ni un taux par défaut, quand :

- le compte n'a pas de personnage principal ;
- les standings du personnage principal n'ont jamais été synchronisés (par exemple si son jeton n'a pas le scope des standings) ;
- le niveau Accounting ou Broker Relations du personnage principal n'a jamais été lu (compétences jamais synchronisées, ou jeton sans le scope des compétences).

Une compétence non apprise ne rend pas les frais inconnus : après une synchro réussie, elle est enregistrée au niveau 0 et compte comme telle.

À l'écran :

- **« Marges »** : l'en-tête affiche « Frais inconnus » et un bandeau « Le courtage et la taxe de vente sont calculés depuis vos compétences et vos standings. Synchronisez-les depuis la page Compétences. » Les colonnes « Frais » et « Profit » affichent « — » (infobulle « Valeur inconnue ») et « Marge » affiche « Inconnu », sauf la ligne contrat, sans frais.
- **« Où vendre »** : « Frais inconnus » dans les colonnes « Frais » et « Net / u », et dans le « Net » de la vente immédiate.
- **« Quoi produire »** : « Frais du vendeur inconnus : synchronisez les compétences et standings de votre personnage principal ».
- **Projet** : « Frais inconnus : le propriétaire du projet n'a pas synchronisé ses compétences et ses standings, le revenu net, le profit et la marge ne peuvent pas être calculés. » Le seuil de rentabilité affiche « Les frais de vente sont inconnus. » et les virements « Le courtage ou la taxe de vente du projet est inconnu : les virements ne peuvent pas être calculés. » Un projet vendu uniquement par contrat n'a pas de frais de marché et n'est pas concerné.

## Pièges

- **Le tableau des compétences ne montre pas Accounting ni Broker Relations.** La section « Competences » n'affiche qu'Industry, Adv. Industry et Reactions. Avant la première synchro, elle montre des niveaux à 0 avec la source « Manuel » : c'est une absence de donnée, et les frais restent inconnus. Accounting et Broker Relations ne se règlent pas à la main.
- **Le bandeau de « Marges » renvoie à « la page Compétences ».** La synchro se fait en réalité dans « Configuration », section « Competences », bouton « Sync toutes ». La page « Compétences » (gains de compétences) n'a pas de bouton de synchro.
- **La section « Frais » de la configuration ne contient pas ces frais.** Elle ne règle que le « Coût d'export (ISK/m³) ». Les frais de vente ne se saisissent pas.
- **Les taux d'un projet sont figés à sa création.** Ils sont copiés depuis les frais du propriétaire, puis jamais recalculés : synchroniser ses compétences plus tard ne remplit pas un projet créé avec des frais inconnus, et ne met pas à jour un projet existant. Le propriétaire ou un admin les corrige avec le bouton « Modifier », champs « Frais courtier % » et « Taxe de vente % ». L'indication « Laissé vide, les frais restent inconnus tant que le propriétaire n'a pas synchronisé ses compétences et ses standings. » laisse croire qu'une synchro suffit : il faut saisir les deux taux. Leur somme ne peut pas dépasser 100 %, et un projet terminé ne se modifie plus.
- **Dans un projet, toute vente au marché paie courtage et taxe.** La distribution applique les deux taux à tout le revenu du canal marché, y compris une vente à un ordre d'achat existant, que les écrans d'analyse comptent avec la taxe seule.
- **La note sous « Comparaison prix de vente »** dit que les frais incluent courtage et taxe de vente, et que la marge vaut profit / revenu. Le calcul n'applique que la taxe à la « vente instantanee », et la marge vaut profit / coût total (voir [marge](/help?page=calculations/margin)).
- **Toujours les standings de Jita.** Une vente en structure de joueur utilise le même courtage, calculé avec les standings envers la faction et la corporation NPC de Jita 4-4. EVE Tools ne lit pas les frais propres à une structure.

## Note technique

Calcul : `SalesFeeCalculator::calculate()` (`src/Service/Industry/SalesFees/SalesFeeCalculator.php`), appelé par `SellerSalesFeesResolver::forUser()`, qui lit le personnage principal, ses compétences et ses standings en cache. Synchro : `SyncCharacterSkillsProcessor` et `CharacterStandingsSynchronizer`. Application par lieu : `ProfitMarginService::marginsOf()`, `SellMarketComparison`, `BatchProfitScannerService`, `BuyVsBuildService`, `PivotAdvisorService`. Projets : `CreateProjectProcessor` (copie), `ProjectDistributionService`, `ProjectBreakEven`, `ProjectSettlementCalculator`.
