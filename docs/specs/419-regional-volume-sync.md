# Spec #419 — Scan par lot : volumes régionaux synchronisés, aucun appel ESI dans la requête

Décision de Sylvain (2026-11-07, option A) : une sync planifiée remplit le cache des volumes journaliers des régions de vente ; le scan lit ce cache et ne fait aucun appel ESI. Un volume non synchronisé est inconnu (`null`), jamais une valeur par défaut.

## Règles métier

- **R1 — Le scan ne parle pas à ESI.** `GET /api/industry/profit-scan`, quel que soit le lieu de vente (Jita ou structure) et l'état du cache, n'émet aucun appel ESI. Pour une structure, les volumes viennent du cache de la région de la structure (`volume_{regionId}_{typeId}`), en lecture seule.
- **R2 — Volume inconnu.** Un produit sans volume en cache est renvoyé avec `dailyVolume = null`, `iskPerDay = null`, motif `missing_daily_volume`, et exclu dès que `minDailyVolume` est posé (règles existantes). Région de la structure introuvable (structure ou système inconnu) : tous les volumes sont inconnus, plus de repli sur les volumes de Jita.
- **R3 — Sync planifiée quotidienne.** Nouveau message `SyncRegionalMarketVolumes`, planifié toutes les 24 h dans `SyncScheduler`, routé `async`. Son handler suit la sync dans `SyncTracker` sous le type `market-volumes` (libellé `Market Volumes`, intervalle attendu 86 400 s, notifié par `admin-sync`).
- **R4 — Quoi synchroniser.** Régions : celles de la structure de marché par défaut et des structures préférées des utilisateurs (`UserRepository::findDistinctPreferredMarketStructureIds`), résolues comme le scanner (`CachedStructureRepository` puis `MapSolarSystemRepository`), dédoublonnées. Types : produits de `IndustryActivityProductRepository::findAllManufacturableProducts`, dédoublonnés. Une structure dont la région est introuvable est ignorée et citée dans le résumé, ce n'est pas un échec.
- **R5 — Calcul et stockage.** Par type : `GET /markets/{region}/history/?type_id=` ; volume = moyenne des 30 derniers jours (règle existante, historique vide = 0.0 connu) ; stocké sous `volume_{regionId}_{typeId}` avec une durée de vie de 48 h (un run manqué ne vide pas le cache).
- **R6 — Budget d'erreurs ESI.** Requêtes via `EsiClient::getBatch` (lots de `SYNC_BATCH_SIZE`, pause et rejeu du 420/429 gérés par le client). Un type dont la requête échoue n'est pas stocké (inconnu) et est compté. Après 3 lots consécutifs sans aucune réponse, la région est abandonnée (budget épuisé) sans marteler ESI. Le résumé du tracker dit combien de types sont synchronisés, échoués, et quelles régions ont été abandonnées ; le tracker passe en échec si au moins un type a échoué ou une région a été abandonnée.
- **R7 — Lecture seule côté scanner.** `JitaMarketService::getCachedDailyVolumesForRegion(int $regionId, array $typeIds): array<int, float>` ne lit que le cache.

## Exemples chiffrés

| # | Cas | Attendu |
|---|---|---|
| E1 | Scan, lieu de vente structure (région 10000060), cache vide, client ESI qui échoue | aucun appel ESI ; chaque produit `dailyVolume = null`, motif `missing_daily_volume` |
| E2 | Même scan, `minDailyVolume = 5`, cache `volume_10000060_587 = 20.0` | seul le type 587 reste, `dailyVolume = 20.0` |
| E3 | Structure dont le système est inconnu | volumes inconnus (pas ceux de Jita) |
| E4 | Historique de 32 jours (30 derniers : 10 à 300, +1 le dernier jour) | `volume_10000060_34 = 155.03`, TTL 48 h |
| E5 | 2 structures de la même région + la structure par défaut d'une autre | 2 régions synchronisées, pas 3 |
| E6 | 60 types, 3 lots de 20 sans réponse | région abandonnée, tracker en échec |

## Hors périmètre

- Sync des volumes de The Forge (Jita) : le scan Jita lit déjà `jita_volume_*` en cache seul, rempli à la demande ailleurs.
- `getAverageDailyVolumesForRegion` n'est plus appelé par le scanner : conservé (aucun retrait sans accord).
- ETag : `getWithCache` stocke l'historique complet par type (environ 100 Ko, deux fois) soit plus d'un Go de Redis par région pour 6 100 types ; `getBatch` n'envoie pas d'ETag. Le coût ESI (200 ou 304) est le même pour le budget d'erreurs. Décision conservatrice : pas d'ETag.
- Déclenchement manuel admin de la sync.

## Cas limites

- Aucune structure préférée ni structure par défaut résoluble : aucune région, le tracker se termine sans échec avec « 0 regions ».
- Un type produit absent de l'historique de la région (404) : inconnu, compté échoué.

## Décisions ouvertes

Aucune.
