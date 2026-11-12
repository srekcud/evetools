# Spec #781 — What to produce : les volumes journaliers de The Forge sont remplis par la sync planifiée

Constat : `GET /api/industry/opportunities` (marché Jita) lit les volumes avec `JitaMarketService::getCachedDailyVolumes` (clés `jita_volume_<typeId>`). Ces clés ne sont écrites qu'à la demande (`getAverageDailyVolumes`). La sync `market-volumes` (#419) n'écrit que `volume_<regionId>_<typeId>` et seulement pour les régions de la structure par défaut et des structures préférées. `ProductionOpportunityRanker::opportunityOf()` écarte tout produit sans volume : 4 233 produits inconnus avec un carnet Jita chaud.

Décision (nuit, Sylvain absent) : étendre la sync `market-volumes` existante plutôt que créer une sync. Même planification (24 h), même type de sync, même tracker, même topic `admin-sync`, même libellé front : aucune nouvelle clé de sync, pas de front. Niveau de technicité 2 au moins (~4 200 requêtes d'historique ESI de plus par run).

## Règles métier

- **R1 — The Forge est toujours synchronisée.** `SyncRegionalMarketVolumesHandler` ajoute la région The Forge (`EveConstants::THE_FORGE_REGION_ID`) aux régions à synchroniser, qu'une structure par défaut ou préférée s'y trouve ou non, dédoublonnée avec les autres régions (une seule requête d'historique par type et région).
- **R2 — Liste de produits.** Les types sont ceux de `IndustryActivityProductRepository::findAllManufacturableProducts` (les produits à classer), dédoublonnés. Ce n'est pas la liste des matériaux de la sync du carnet Jita : le classement lit les volumes des produits.
- **R3 — Un seul jeu de clés pour The Forge.** Pour la région The Forge, `JitaMarketService::syncRegionalDailyVolumes` écrit sous `jita_volume_<typeId>` et `getCachedDailyVolumesForRegion` lit sous `jita_volume_<typeId>`. `getCachedDailyVolumes` et `getCachedDailyVolumesForRegion(THE_FORGE_REGION_ID, …)` renvoient donc les mêmes valeurs. Les autres régions gardent `volume_<regionId>_<typeId>`.
- **R4 — Durée de vie.** La sync stocke les volumes de The Forge 48 h (règle #419 R5), la lecture à la demande garde 24 h.
- **R5 — Budget d'erreurs ESI (inchangé).** Requêtes via `EsiClient::getBatch` (lots de `SYNC_BATCH_SIZE`, pause et rejeu du 420/429 gérés par le client). Un type dont la requête échoue n'est pas stocké (inconnu) et est compté. Après 3 lots consécutifs sans réponse, la région est abandonnée. L'abandon de The Forge n'empêche pas les autres régions.
- **R6 — Tracker.** Le résumé du type `market-volumes` compte The Forge comme une région. Échec du tracker si au moins un type a échoué ou une région a été abandonnée (inchangé).
- **R7 — Plus de sortie anticipée sans région.** Sans structure par défaut ni préférée résoluble, The Forge est quand même synchronisée ; les structures dont la région est introuvable restent citées dans le résumé sans échec.

## Exemples chiffrés

| # | Cas | Attendu |
|---|---|---|
| E1 | Aucune structure préférée, structure par défaut en région 10000060 | régions synchronisées : The Forge et 10000060, chacune avec [587, 588] |
| E2 | Structure préférée dans The Forge | une seule synchronisation de The Forge |
| E3 | Historique de 32 jours (30 derniers : 10 à 300, +1 le dernier jour) pour The Forge | `jita_volume_34 = 155.03`, pas de `volume_10000002_34`, TTL 48 h |
| E4 | `jita_volume_34 = 12.5` en cache | `getCachedDailyVolumesForRegion(THE_FORGE, [34])` = `getCachedDailyVolumes([34])` = `[34 => 12.5]` |
| E5 | Autre région (Heimatar) | clés `volume_10000030_<typeId>` inchangées, aucune clé `jita_volume_` |
| E6 | The Forge abandonnée (3 lots vides), autre région saine | tracker en échec, nom de The Forge dans « abandoned » |

## Hors périmètre

- Front, #782, déclenchement manuel (`market-volumes` est déjà déclenchable par l'admin).
- Suppression de `getAverageDailyVolumes` (utilisée à la demande par le navigateur de marché, l'appraisal, les marges).
- ETag : même décision que #419 (pas de stockage de l'historique complet).

## Décisions ouvertes

Aucune. Action prod : déclencher `market-volumes` une fois après déploiement (admin) ou attendre le premier run planifié ; environ 4 200 requêtes d'historique de plus.
