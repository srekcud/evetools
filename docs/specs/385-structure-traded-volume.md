# Spec #385 — Volume échangé d'une structure, estimé par différence entre synchros

Mode nuit : spec validée en autonomie, sur les décisions de Sylvain du 2026-11-05 (commentaire de #348) et le texte de #385.

## Contexte

L'ESI ne publie aucun historique de marché pour une structure. `StructureMarketService::syncStructureMarket` lit toutes les pages d'ordres chaque heure, agrège, puis jette les ordres ; `StructureMarketSnapshot` ne garde que le volume en vente et en achat du carnet (`sellVolume`, `buyVolume`), pas le volume échangé. #348 a besoin d'un volume journalier de la capitale pour calculer les jours pour tout vendre.

## Règles métier

- **R1 — Baseline.** À chaque synchro réussie d'une structure, l'état des ordres lus (`order_id` → `type_id`, `volume_remain`) est gardé dans le pool `structure_market.cache`, clé propre à la structure, durée de vie 3 h. C'est la baseline de la synchro suivante.
- **R2 — Différence.** Sans baseline (première synchro, baseline expirée) : rien n'est compté, la baseline est écrite. Avec baseline : pour chaque `order_id` présent dans les deux lectures avec le même `type_id`, une baisse de `volume_remain` (précédent − courant, strictement positif) est un volume échangé de ce type. Les hausses sont ignorées. Un ordre absent de la lecture courante ne compte pas (vendu ou annulé, indistinguable) ; un ordre nouveau ne compte pas.
- **R3 — Compteur.** Le volume échangé est cumulé par structure, par type et par jour de synchro (`syncedAt`, au jour). Table `structure_traded_volumes` : `structure_id` (bigint), `type_id`, `date` (date_immutable), `traded_volume` (bigint), unique sur les trois premières colonnes, comme `structure_market_snapshots`. Chaque synchro avec baseline cumule (`+=`) dans la ligne du jour, y compris `0` pour un type de la lecture courante sans baisse : la ligne dit « le type a été observé ce jour ». Un type absent de la lecture courante n'a pas de ligne.
- **R4 — Jour couvert.** Un jour couvert d'une structure est un jour qui porte au moins une ligne de cette structure. Jamais compté : aujourd'hui (jour incomplet, exclu des moyennes).
- **R5 — Lecture.** `StructureTradedVolumeService::getAverageDailyVolumes(int $structureId, array $typeIds, \DateTimeImmutable $today): array<int, float|null>`. La fenêtre est les 30 jours qui précèdent `$today` (aujourd'hui exclu). Jours = nombre de jours couverts de la structure dans la fenêtre. Moyenne d'un type = somme de ses `traded_volume` dans la fenêtre ÷ jours.
- **R6 — Inconnu, jamais 0.** La moyenne d'un type vaut `null` si la structure a moins de 7 jours couverts dans la fenêtre, ou si la somme du type dans la fenêtre vaut 0 (borne basse sans information : un ordre entièrement vendu disparaît et ne compte pas). Une entrée par `typeId` demandé, `null` pour les types sans ligne. Liste vide demandée : tableau vide.
- **R7 — Borne basse.** Le chiffre sous-estime le volume réel : à documenter dans le glossaire. Les jours pour tout vendre qui s'en déduisent sont une borne haute.
- **R8 — Câblage.** `syncStructureMarket` appelle `StructureTradedVolumeService::recordFromSync($structureId, $orders, new \DateTimeImmutable())` après `recordSnapshots`, avec les ordres lus (`order_id`, `type_id`, `volume_remain`). Une exception remonte dans le `catch` existant : la synchro est alors en échec, jamais silencieuse. Le prix et le carnet sont déjà en cache à ce stade.
- **R9 — Purge.** `PurgeOldMarketHistoryHandler` purge aussi `structure_traded_volumes` au-delà de 90 jours (même règle que les snapshots), méthode `purgeOlderThan(int $days)` du repository du compteur.
- **R10 — Pas de Mercure ni d'API.** Aucun nouvel appel async ni endpoint : le compteur est alimenté par la synchro existante, qui publie déjà sur le topic `market-structure`. La lecture est un service pour #348.

## Interface attendue

- `App\Service\StructureTradedVolumeService` : constructeur `(Connection $connection, #[Autowire(service: 'structure_market.cache')] CacheItemPoolInterface $cache, LoggerInterface $logger)` ; `recordFromSync(int $structureId, iterable $orders, \DateTimeImmutable $syncedAt): void` ; `getAverageDailyVolumes` (R5).
- `App\Service\OrderVolumeDecreases` (classe pure, sans état) : `between(array $previous, array $current): array<int, int>` avec `$previous` et `$current` de forme `orderId => ['typeId' => int, 'volumeRemain' => int]`, renvoie `typeId => volume échangé` (R2), uniquement les types avec une baisse.
- Entité `StructureTradedVolume`, repository `StructureTradedVolumeRepository` (`purgeOlderThan`), migration créant la table et ses index (`structure_id, type_id` ; `date`).
- `StructureMarketService` reçoit `StructureTradedVolumeService` en dernier argument du constructeur : les quatre constructions de test existantes sont mises à jour en phase rouge.

## Exemples chiffrés

| # | Cas | Attendu |
|---|---|---|
| E1 | Ordre 1 (type 34) 1000 → 700 | 34 : 300 |
| E2 | Ordre 1 (type 34) 1000 → 1000 | aucune entrée pour 34 |
| E3 | Ordre 1 (type 34) 1000 → absent ; ordre 2 (type 34) absent → 500 | aucune entrée (disparu, nouveau) |
| E4 | Ordres 1 (type 34) 1000 → 900 et 2 (type 34) 50 → 20, ordre 3 (type 35) 10 → 4 | 34 : 130, 35 : 6 |
| E5 | Ordre 1 (type 34) 700 → 900 (hausse) | aucune entrée |
| E6 | Ordre 1 passe du type 34 au type 35 (même id) | aucune entrée |
| E7 | Première synchro (sans baseline), 100 ordres | table vide, baseline écrite |
| E8 | Deux synchros le même jour, baisses 300 puis 200 sur le type 34 | une ligne (jour, type 34) = 500 |
| E9 | Synchro avec baseline, type 36 présent sans baisse | ligne (jour, 36) = 0 |
| E10 | Structure avec 7 jours couverts avant aujourd'hui, type 34 : 700 au total | moyenne 100.0 |
| E11 | Idem avec 6 jours couverts | `null` |
| E12 | 7 jours couverts, type 34 sans aucune ligne ou à 0 partout | `null` |
| E13 | Lignes d'aujourd'hui seules + 6 jours passés | `null` (aujourd'hui exclu) |
| E14 | 10 jours couverts, une ligne à 31 jours (hors fenêtre) | la ligne hors fenêtre ne compte ni dans la somme ni dans les jours |
| E15 | Deux structures, mêmes types | chaque moyenne ne lit que sa structure |

## Cas limites

- `volume_remain` ou `order_id` absent d'un ordre ESI : l'ordre mal formé fait lever une exception (`\InvalidArgumentException`), pas d'ordre ignoré ni de volume 0 par défaut.
- Baseline illisible (autre forme que celle écrite) : traitée comme absente, avec un log `warning`.
- Les structures du sync sont déjà celles des utilisateurs (voir « Accès ») ; une structure jamais synchronisée n'a aucun jour couvert, donc `null` partout.
- Volume en `bigint` côté base, `int` PHP (64 bits).

## Accès et fraîcheur

- Donnée de marché de structure, partagée par construction avec le prix et le carnet déjà en cache : aucune donnée personnelle, aucun identifiant reçu d'un utilisateur, aucun endpoint. Le contrôle d'accès à la lecture sera défini par #348 quand il l'exposera.
- Fraîcheur : un jour par synchro horaire ; un trou de synchro n'invente rien (baseline expirée = pas de comptage, R2). Une baseline vieille de 3 h au plus donne une différence valable (borne basse).
- La synchro d'une structure peut être déclenchée par plusieurs chemins (scheduler, commande, `SyncMarketProcessor`) : un déclenchement rapproché compte seulement les baisses survenues depuis la baseline précédente, donc sans double comptage.

## Tests existants appelés à changer

- `tests/Unit/MessageHandler/StructureMarketSyncFixtures.php`, `tests/Unit/State/Processor/ShoppingList/SyncMarketProcessorCharacterizationTest.php`, `tests/Unit/Service/MarketAlertServiceCharacterizationTest.php`, `tests/Unit/MessageHandler/CheckAlertPricesHandlerTest.php` : construction de `StructureMarketService` (nouvel argument). Aucune assertion modifiée.
- Les tests de `PurgeOldMarketHistoryHandler` (s'il y en a) : nouveau collaborateur.

## Hors périmètre

- Exposition API ou front du volume, calcul des jours pour tout vendre à 10 % (#348).
- Reconstitution des ventes d'ordres disparus (wallet de l'utilisateur, estimation) : décision de Sylvain, borne basse seulement.
- Reprise des données passées, volume des régions NPC (historique ESI, inchangé).

## Glossaire

- **Volume journalier moyen** : distinguer le volume de région (historique ESI, 30 entrées) du **volume de structure estimé** (borne basse par différence entre synchros, 30 jours précédant aujourd'hui, inconnu sous 7 jours couverts ou sans baisse observée, jamais 0).
- Nouveau : **Volume échangé estimé** (Estimated traded volume) : somme des baisses de `volume_remain` des ordres présents à deux synchros successives ; `StructureTradedVolumeService`.
